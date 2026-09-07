<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\TraversalDirection;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\Lineage\LearningLineageRelation;
use voku\AgentLearning\Lineage\LearningLineageResult;

/**
 * Learning-owned boundary for the rebuildable lineage projection.
 *
 * Consumers receive Learning identities and relation kinds only. The SQLite
 * path and agent-graph storage API remain private implementation details.
 */
final readonly class LearningLineageService
{
    private const MAXIMUM_DEPTH = 8;
    private const MAXIMUM_RESULTS = 500;

    public function __construct(
        private LearningLineageProjector $projector = new LearningLineageProjector(),
        private LearningNoteRepository $noteRepository = new LearningNoteRepository(),
    ) {
    }

    public function rebuild(string $root): void
    {
        $root = $this->normalizedRoot($root);
        $revisionBefore = $this->sourceRevision($root);
        $catalog = new LearningCatalog($root);
        $relations = $this->projector->relations(
            $catalog->findings(),
            $catalog->proposals(),
            array_values($this->noteRepository->loadActive($root)),
        );
        $fingerprint = $this->sourceFingerprint($root);
        $revisionAfter = $this->sourceRevision($root);
        if (!hash_equals($revisionBefore, $revisionAfter)) {
            throw new RuntimeException('Learning state changed during lineage rebuild; retry from one owner generation.');
        }

        $database = $this->databasePath($root);
        $directory = dirname($database);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Learning lineage directory: ' . $directory);
        }

        (new GraphStore($database))->replace(
            $relations,
            sourceRevision: $revisionAfter,
            sourceFingerprint: $fingerprint,
            allowEmpty: true,
        );
    }

    public function lineage(
        string $root,
        string $identityId,
        int $maximumDepth = 3,
        int $maximumResults = 100,
    ): LearningLineageResult {
        $identityId = trim($identityId);
        if ($identityId === '') {
            throw new InvalidArgumentException('Learning lineage identity must be non-empty.');
        }
        if ($maximumDepth < 1 || $maximumDepth > self::MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Learning lineage depth must be between 1 and ' . self::MAXIMUM_DEPTH . '.');
        }
        if ($maximumResults < 1 || $maximumResults > self::MAXIMUM_RESULTS) {
            throw new InvalidArgumentException('Learning lineage result limit must be between 1 and ' . self::MAXIMUM_RESULTS . '.');
        }

        $store = $this->openCurrent($this->normalizedRoot($root));
        $traversal = $store->traverse(
            $identityId,
            TraversalDirection::INCOMING,
            maximumDepth: $maximumDepth,
            maximumNodes: $maximumResults,
        );

        $visibleIds = array_fill_keys([$identityId, ...$traversal->nodeIds], true);
        $relations = [];
        foreach ($traversal->relations as $relation) {
            $projected = $this->projectRelation($relation, $visibleIds);
            if ($projected !== null) {
                $relations[] = $projected;
            }
        }
        usort(
            $relations,
            static fn (LearningLineageRelation $left, LearningLineageRelation $right): int => $left->id <=> $right->id,
        );

        return new LearningLineageResult(
            identityId: $identityId,
            identityIds: $traversal->nodeIds,
            depthByIdentityId: [$identityId => 0] + $traversal->depthByNodeId,
            relations: $relations,
            maximumDepth: $maximumDepth,
            maximumResults: $maximumResults,
            truncated: $traversal->truncated,
        );
    }

    public function verifyCurrent(string $root): void
    {
        $root = $this->normalizedRoot($root);
        $store = $this->openCurrent($root);
        $actualFingerprint = $store->sourceFingerprint();
        $expectedFingerprint = $this->sourceFingerprint($root);
        if ($actualFingerprint === null || !hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new RuntimeException('Derived Learning lineage graph failed source fingerprint verification.');
        }

        $integrityFailures = $store->integrityFailures();
        if ($integrityFailures !== []) {
            throw new RuntimeException('Derived Learning lineage graph failed integrity checks: ' . implode(', ', $integrityFailures));
        }
    }

    private function openCurrent(string $root): GraphStore
    {
        $database = $this->databasePath($root);
        if (!is_file($database)) {
            throw new RuntimeException('Derived Learning lineage graph not found; rebuild it first.');
        }

        $store = new GraphStore($database);
        $actualRevision = $store->sourceRevision();
        $expectedRevision = $this->sourceRevision($root);
        if ($actualRevision === null || !hash_equals($expectedRevision, $actualRevision)) {
            throw new RuntimeException('Derived Learning lineage graph is stale; rebuild it from current Learning state.');
        }

        return $store;
    }

    /** @param array<string, true> $visibleIds */
    private function projectRelation(GraphRelation $relation, array $visibleIds): ?LearningLineageRelation
    {
        if (count($relation->targetIds) !== 1) {
            throw new RuntimeException('Learning lineage graph contains an invalid multi-target relation: ' . $relation->id);
        }

        $targetId = $relation->targetIds[0];
        if (!isset($visibleIds[$relation->sourceId], $visibleIds[$targetId])) {
            return null;
        }

        return new LearningLineageRelation(
            id: $relation->id,
            sourceId: $relation->sourceId,
            kind: $relation->kind,
            targetId: $targetId,
        );
    }

    private function normalizedRoot(string $root): string
    {
        $root = rtrim($root, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new InvalidArgumentException('Learning root must be an existing directory.');
        }

        return $root;
    }

    private function databasePath(string $root): string
    {
        return $root . '/.derived/lineage/graph.sqlite';
    }

    private function sourceRevision(string $root): string
    {
        $context = hash_init('sha256');
        foreach ($this->sourceFiles($root) as $path) {
            $stat = stat($path);
            if (!is_array($stat)) {
                throw new RuntimeException('Unable to stat Learning lineage source: ' . $path);
            }

            hash_update($context, $this->relativePath($root, $path) . "\0");
            hash_update($context, (string) $stat['size'] . "\0");
            hash_update($context, (string) $stat['mtime'] . "\0");
            hash_update($context, (string) $stat['ctime'] . "\0");
        }

        return 'sha256:' . hash_final($context);
    }

    private function sourceFingerprint(string $root): string
    {
        $context = hash_init('sha256');
        foreach ($this->sourceFiles($root) as $path) {
            hash_update($context, $this->relativePath($root, $path) . "\0");
            if (!hash_update_file($context, $path)) {
                throw new RuntimeException('Unable to hash Learning lineage source: ' . $path);
            }
            hash_update($context, "\0");
        }

        return 'sha256:' . hash_final($context);
    }

    /** @return list<string> */
    private function sourceFiles(string $root): array
    {
        $paths = [];
        foreach (['findings', 'proposals', 'notes'] as $collection) {
            $directory = $root . '/' . $collection;
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'json' || $file->getSize() === 0) {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
