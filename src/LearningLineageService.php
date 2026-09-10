<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SplQueue;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\Lineage\LearningLineageRelation;
use voku\AgentLearning\Lineage\LearningLineageResult;
use voku\AgentLearning\Lineage\LearningTaskPrecedentResult;

/**
 * Learning-owned boundary over the rebuildable lineage projection.
 *
 * The graph database and agent-graph API stay private. Ordinary lineage reads
 * compare a cheap owner-state generation revision and do not decode Learning
 * documents. Task precedent reads traverse only bounded owner lineage and then
 * decode the selected active LearningNotes. Explicit verification additionally
 * hashes all projected sources and runs graph integrity checks.
 */
final readonly class LearningLineageService
{
    private const int MAXIMUM_DEPTH = 8;
    private const int MAXIMUM_RESULTS = 500;
    private const string PROJECTION_VERSION = '2';

    public function __construct(
        private LearningLineageProjector $projector = new LearningLineageProjector(),
        private LearningNoteService $noteService = new LearningNoteService(),
        private LearningNoteRepository $noteRepository = new LearningNoteRepository(),
    ) {
    }

    public function rebuild(string $root, ?string $projectRoot = null): void
    {
        $root = $this->normalizedRoot($root);
        $revisionBefore = $this->sourceRevision($root);
        $catalog = new LearningCatalog($root);
        $notes = is_dir($root . '/notes/' . LearningNoteStatus::ACTIVE->value)
            ? $this->noteService->activeProjections($root, $projectRoot)
            : [];
        $relations = $this->projector->project(
            $catalog->findings(),
            $catalog->proposals(),
            $notes,
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
        $this->assertLimits($maximumDepth, $maximumResults);

        $store = $this->openForRead($this->normalizedRoot($root));

        return $this->traverse(
            $store,
            $identityId,
            $maximumDepth,
            $maximumResults,
            allowTaskAnchorAtRoot: false,
        );
    }

    public function precedentsForTask(
        string $root,
        string $taskId,
        ?string $projectRoot = null,
        int $maximumRelatedIdentities = 100,
    ): LearningTaskPrecedentResult {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new InvalidArgumentException('Learning task precedent query requires a non-empty task id.');
        }
        $this->assertLimits(3, $maximumRelatedIdentities);

        if (!is_dir($root)) {
            return new LearningTaskPrecedentResult(
                taskId: $taskId,
                precedents: [],
                lineage: new LearningLineageResult(
                    identityId: $taskId,
                    identityIds: [],
                    depthByIdentityId: [$taskId => 0],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: $maximumRelatedIdentities,
                    truncated: false,
                ),
            );
        }

        $root = $this->normalizedRoot($root);
        $revisionBefore = $this->sourceRevision($root);
        if (!is_file($this->databasePath($root)) && $this->sourceFiles($root) === []) {
            $revisionAfter = $this->sourceRevision($root);
            if (!hash_equals($revisionBefore, $revisionAfter)) {
                throw new RuntimeException('Learning state changed during task precedent query; retry from one owner generation.');
            }

            return new LearningTaskPrecedentResult(
                taskId: $taskId,
                precedents: [],
                lineage: new LearningLineageResult(
                    identityId: $taskId,
                    identityIds: [],
                    depthByIdentityId: [$taskId => 0],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: $maximumRelatedIdentities,
                    truncated: false,
                ),
            );
        }

        $store = $this->openForRead($root, $projectRoot);
        $lineage = $this->traverse(
            $store,
            $taskId,
            maximumDepth: 3,
            maximumResults: $maximumRelatedIdentities,
            allowTaskAnchorAtRoot: true,
        );

        $projectRoot ??= (new LearningProjectPaths())->projectRootForLearningRoot($root);
        $precedents = [];
        foreach ($lineage->identityIds as $identityId) {
            if (!str_starts_with($identityId, 'learning-note.')) {
                continue;
            }

            $precedents[] = $this->projectActiveNote($root, $identityId, $projectRoot);
        }

        $existingIds = array_fill_keys(
            array_map(static fn (LearningNoteProjection $projection): string => $projection->id, $precedents),
            true,
        );
        $remainingCapacity = $maximumRelatedIdentities - count($precedents);
        $topUpIds = [];
        $precedentsTruncated = false;
        foreach ($this->activeNoteIds($root) as $activeNoteId) {
            if (isset($existingIds[$activeNoteId])) {
                continue;
            }
            if (count($topUpIds) >= $remainingCapacity) {
                $precedentsTruncated = true;
                break;
            }
            $topUpIds[] = $activeNoteId;
        }
        foreach ($topUpIds as $activeNoteId) {
            $precedents[] = $this->projectActiveNote($root, $activeNoteId, $projectRoot);
        }

        usort(
            $precedents,
            static fn (LearningNoteProjection $left, LearningNoteProjection $right): int => $left->id <=> $right->id,
        );

        $revisionAfter = $this->sourceRevision($root);
        if (!hash_equals($revisionBefore, $revisionAfter)) {
            throw new RuntimeException('Learning state changed during task precedent query; retry from one owner generation.');
        }

        return new LearningTaskPrecedentResult(
            taskId: $taskId,
            precedents: $precedents,
            lineage: $lineage,
            precedentsTruncated: $precedentsTruncated,
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

    private function projectActiveNote(string $root, string $id, string $projectRoot): LearningNoteProjection
    {
        $note = $this->noteRepository->findActive($root, $id);
        if ($note === null) {
            throw new RuntimeException('Current Learning precedent query references unavailable active LearningNote: ' . $id);
        }

        return new LearningNoteProjection(
            id: $note->id,
            patternKey: $note->patternKey,
            status: $note->status,
            scope: $note->scope,
            tags: $note->tags,
            sourceFindings: $note->sourceFindings,
            sourceProposals: $note->sourceProposals,
            validationCase: $note->validationCase,
            content: $note->content,
            digest: $note->digest(),
            evidenceState: $this->noteService->evidenceState($note, $projectRoot),
        );
    }

    /** @return list<string> */
    private function activeNoteIds(string $root): array
    {
        $directory = $root . '/notes/' . LearningNoteStatus::ACTIVE->value;
        if (!is_dir($directory)) {
            return [];
        }
        $paths = glob($directory . '/*.json');
        if ($paths === false) {
            throw new RuntimeException('Unable to enumerate active LearningNotes.');
        }
        sort($paths, SORT_STRING);

        $ids = [];
        foreach ($paths as $path) {
            $id = basename($path, '.json');
            if (preg_match(RecordIdGenerator::pattern('learning-note'), $id) !== 1) {
                throw new ValidationException($path, null, $id !== '' ? $id : null, 'LearningNote id must match learning-note.YYYY-MM-DD.<suffix>');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function traverse(
        GraphStore $store,
        string $identityId,
        int $maximumDepth,
        int $maximumResults,
        bool $allowTaskAnchorAtRoot,
    ): LearningLineageResult {
        /** @var SplQueue<array{id: string, depth: int}> $queue */
        $queue = new SplQueue();
        $queue->enqueue(['id' => $identityId, 'depth' => 0]);

        /** @var array<string, true> $visited */
        $visited = [$identityId => true];
        /** @var list<string> $identityIds */
        $identityIds = [];
        /** @var array<string, int> $depthByIdentityId */
        $depthByIdentityId = [$identityId => 0];
        /** @var array<string, GraphRelation> $relationsById */
        $relationsById = [];
        $truncated = false;

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            if ($current['depth'] >= $maximumDepth) {
                continue;
            }

            $relations = array_merge(
                $store->incoming($current['id']),
                $store->outgoing($current['id']),
            );
            usort($relations, self::compareGraphRelations(...));

            foreach ($relations as $relation) {
                if ($relation->kind === LearningLineageProjector::FINDING_FROM_TASK) {
                    if (!$allowTaskAnchorAtRoot || $current['depth'] !== 0 || $relation->sourceId !== $identityId) {
                        continue;
                    }
                }
                if (count($relation->targetIds) !== 1) {
                    throw new RuntimeException('Learning lineage graph contains an invalid multi-target relation: ' . $relation->id);
                }
                $relationsById[$relation->id] = $relation;

                $candidateIds = [];
                if ($relation->sourceId !== $current['id']) {
                    $candidateIds[] = $relation->sourceId;
                }
                $targetId = $relation->targetIds[0];
                if ($targetId !== $current['id']) {
                    $candidateIds[] = $targetId;
                }
                $candidateIds = array_values(array_unique($candidateIds));
                sort($candidateIds, SORT_STRING);

                foreach ($candidateIds as $candidateId) {
                    if (isset($visited[$candidateId])) {
                        continue;
                    }
                    if (count($identityIds) >= $maximumResults) {
                        $truncated = true;
                        continue;
                    }

                    $depth = $current['depth'] + 1;
                    $visited[$candidateId] = true;
                    $identityIds[] = $candidateId;
                    $depthByIdentityId[$candidateId] = $depth;
                    if ($depth < $maximumDepth) {
                        $queue->enqueue(['id' => $candidateId, 'depth' => $depth]);
                    }
                }
            }
        }

        $relations = [];
        foreach ($relationsById as $relation) {
            $targetId = $relation->targetIds[0];
            if (!isset($visited[$relation->sourceId], $visited[$targetId])) {
                continue;
            }
            $relations[] = new LearningLineageRelation(
                sourceId: $relation->sourceId,
                kind: $relation->kind,
                targetId: $targetId,
            );
        }
        usort(
            $relations,
            static fn (LearningLineageRelation $left, LearningLineageRelation $right): int => [
                $left->sourceId,
                $left->kind,
                $left->targetId,
            ] <=> [
                $right->sourceId,
                $right->kind,
                $right->targetId,
            ],
        );

        return new LearningLineageResult(
            identityId: $identityId,
            identityIds: $identityIds,
            depthByIdentityId: $depthByIdentityId,
            relations: $relations,
            maximumDepth: $maximumDepth,
            maximumResults: $maximumResults,
            truncated: $truncated,
        );
    }

    /**
     * Open the graph for an ordinary read, reconstructing it when that is deterministic.
     *
     * The lineage graph is a Learning-owned projection of durable Learning records: it
     * holds no fact that cannot be recomputed from findings, proposals and active notes.
     * Refusing a read because it is stale therefore asks a consumer to repair the owner's
     * own cache, and every consumer then needs to know this class exists. Worse, the
     * refusal surfaces far from its cause - `FindingCreator::createValidated()` publishes
     * a Finding and returns, so the next unrelated Recall-consuming command is the one
     * that fails, with a message about lineage it has no reason to understand.
     *
     * `LearningNoteService::publish()` and `retire()` already rebuild after changing
     * durable state; this closes the same loop from the read side for the writers that
     * do not.
     *
     * Only two states are repaired: an absent database, and one whose stored revision no
     * longer matches current durable state. Both are recomputable by definition. A
     * rebuild that cannot complete - unreadable records, invalid durable data, Learning
     * state moving underneath the projection - still throws, and the second `openCurrent()`
     * re-checks the revision rather than trusting that the rebuild produced a usable
     * graph. A read never invents Learning state; it only recomputes what Learning
     * already implies.
     */
    private function openForRead(string $root, ?string $projectRoot = null): GraphStore
    {
        try {
            return $this->openCurrent($root);
        } catch (LearningLineageProjectionUnavailable) {
            // Recomputable. Fall through to one rebuild.
        }

        $this->rebuild($root, $projectRoot);

        return $this->openCurrent($root);
    }

    private function openCurrent(string $root): GraphStore
    {
        $database = $this->databasePath($root);
        if (!is_file($database)) {
            throw new LearningLineageProjectionUnavailable(
                'Derived Learning lineage graph not found; rebuild it first.',
            );
        }

        $store = new GraphStore($database);
        $actualRevision = $store->sourceRevision();
        $expectedRevision = $this->sourceRevision($root);
        if ($actualRevision === null || !hash_equals($expectedRevision, $actualRevision)) {
            throw new LearningLineageProjectionUnavailable(
                'Derived Learning lineage graph is stale; rebuild it from current Learning state.',
            );
        }

        return $store;
    }

    private function assertLimits(int $maximumDepth, int $maximumResults): void
    {
        if ($maximumDepth < 1 || $maximumDepth > self::MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Learning lineage depth must be between 1 and ' . self::MAXIMUM_DEPTH . '.');
        }
        if ($maximumResults < 1 || $maximumResults > self::MAXIMUM_RESULTS) {
            throw new InvalidArgumentException('Learning lineage result limit must be between 1 and ' . self::MAXIMUM_RESULTS . '.');
        }
    }

    private static function compareGraphRelations(GraphRelation $left, GraphRelation $right): int
    {
        return [
            $left->sourceId,
            $left->kind,
            implode("\0", $left->targetIds),
            $left->id,
        ] <=> [
            $right->sourceId,
            $right->kind,
            implode("\0", $right->targetIds),
            $right->id,
        ];
    }

    private function normalizedRoot(string $root): string
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException('Learning root must be an existing directory.');
        }

        return rtrim(str_replace('\\', '/', $realRoot), '/');
    }

    private function databasePath(string $root): string
    {
        return $root . '/.derived/lineage/graph.sqlite';
    }

    private function sourceRevision(string $root): string
    {
        $context = hash_init('sha256');
        hash_update($context, 'learning-lineage-projection:' . self::PROJECTION_VERSION . "\0");
        foreach ($this->sourceFiles($root) as $path) {
            $stat = stat($path);
            if (!is_array($stat)) {
                throw new RuntimeException('Unable to stat Learning lineage source: ' . $path);
            }

            hash_update($context, $this->relativePath($root, $path) . "\0");
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
                hash_update($context, (string) $stat[$field] . "\0");
            }
        }

        return 'sha256:' . hash_final($context);
    }

    private function sourceFingerprint(string $root): string
    {
        $context = hash_init('sha256');
        hash_update($context, 'learning-lineage-projection:' . self::PROJECTION_VERSION . "\0");
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
        $directories = [
            $root . '/findings',
            $root . '/proposals',
            $root . '/notes/' . LearningNoteStatus::ACTIVE->value,
        ];
        $paths = [];
        foreach ($directories as $directory) {
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
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function relativePath(string $root, string $path): string
    {
        return substr($path, strlen($root) + 1);
    }
}
