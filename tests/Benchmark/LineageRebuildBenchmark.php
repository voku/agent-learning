<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests\Benchmark;

use ReflectionMethod;
use RuntimeException;
use SplFileInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentLearning\LearningCatalog;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNote;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\ValidationCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class LineageRebuildBenchmark
{
    private const array SCALE_RECORDS = [100, 500, 1000];

    private const int DEFAULT_REPETITIONS = 3;

    private const string BENCHMARK_DATE = '2026-09-13';

    private readonly int $repetitions;

    private readonly string $outputDirectory;

    public function __construct()
    {
        $configuredRepetitions = getenv('LINEAGE_BENCHMARK_REPETITIONS');
        $this->repetitions = $configuredRepetitions === false
            ? self::DEFAULT_REPETITIONS
            : max(1, (int) $configuredRepetitions);

        $configuredOutputDirectory = getenv('LINEAGE_BENCHMARK_OUTPUT');
        $this->outputDirectory = $configuredOutputDirectory === false || trim($configuredOutputDirectory) === ''
            ? dirname(__DIR__, 2) . '/build/lineage-rebuild-benchmark'
            : $configuredOutputDirectory;
    }

    public function run(): int
    {
        if (!is_dir($this->outputDirectory)
            && !mkdir($this->outputDirectory, 0o775, true)
            && !is_dir($this->outputDirectory)) {
            throw new RuntimeException('Unable to create benchmark output directory: ' . $this->outputDirectory);
        }

        $results = [];
        foreach (self::SCALE_RECORDS as $scaleRecords) {
            $results[] = $this->measureScale($scaleRecords);
        }

        $report = [
            'schema_version' => '1.0',
            'benchmark' => 'learning_lineage_rebuild_phases',
            'issue' => 'voku/agent-learning#88',
            'git_sha' => $this->environmentString('GITHUB_SHA'),
            'php_version' => PHP_VERSION,
            'php_os_family' => PHP_OS_FAMILY,
            'repetitions' => $this->repetitions,
            'scale_unit' => 'one validated Finding plus one active LearningNote',
            'results' => $results,
        ];

        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $markdown = $this->renderMarkdown($report);

        $this->write($this->outputDirectory . '/raw-and-summary.json', $json);
        $this->write($this->outputDirectory . '/summary.md', $markdown);

        echo $markdown;

        return 0;
    }

    /**
     * @return array{
     *   scale_records: int,
     *   finding_count: int,
     *   active_note_count: int,
     *   durable_documents: int,
     *   relation_count: int,
     *   relation_digest: string,
     *   graph_size_bytes: int,
     *   public_rebuild_anchor_ms: float,
     *   public_rebuild_peak_memory_bytes: int,
     *   raw_repetitions: list<array{
     *     total_ms: float,
     *     peak_memory_bytes: int,
     *     graph_size_bytes: int,
     *     phases_ms: array<string, float>
     *   }>,
     *   summary: array{
     *     total_ms: array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float},
     *     peak_memory_bytes: array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float},
     *     phases_ms: array<string, array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float}>,
     *     phase_share_percent: array<string, float>,
     *     largest_phase: string,
     *     largest_phase_share_percent: float,
     *     largest_phase_cv_percent: float,
     *     profile_vs_public_anchor_percent: float
     *   }
     * }
     */
    private function measureScale(int $scaleRecords): array
    {
        $root = $this->createCorpus($scaleRecords);
        $service = new LearningLineageService();
        $projector = new LearningLineageProjector();
        $noteService = new LearningNoteService();
        $revisionMethod = new ReflectionMethod(LearningLineageService::class, 'sourceRevision');
        $fingerprintMethod = new ReflectionMethod(LearningLineageService::class, 'sourceFingerprint');

        try {
            $rawRepetitions = [];
            $relationDigest = null;
            $relationCount = 0;
            $graphSizeBytes = 0;

            for ($repetition = 0; $repetition < $this->repetitions; ++$repetition) {
                memory_reset_peak_usage();
                $totalStartedAt = microtime(true);

                [$revisionBefore, $revisionBeforeMs] = $this->timed(
                    fn (): string => $this->invokePrivateString($revisionMethod, $service, $root),
                );

                [$catalogData, $catalogLoadMs] = $this->timed(static function () use ($root): array {
                    $catalog = new LearningCatalog($root);

                    return [
                        'findings' => $catalog->findings(),
                        'proposals' => $catalog->proposals(),
                    ];
                });

                [$notes, $activeNoteProjectionMs] = $this->timed(
                    fn (): array => $noteService->activeProjections($root, $root),
                );

                [$relations, $relationProjectionMs] = $this->timed(
                    fn (): array => $projector->project(
                        $catalogData['findings'],
                        $catalogData['proposals'],
                        $notes,
                    ),
                );

                [$sourceIdentity, $sourceIdentityMs] = $this->timed(function () use (
                    $fingerprintMethod,
                    $revisionMethod,
                    $service,
                    $root,
                ): array {
                    return [
                        'fingerprint' => $this->invokePrivateString($fingerprintMethod, $service, $root),
                        'revision_after' => $this->invokePrivateString($revisionMethod, $service, $root),
                    ];
                });

                if (!hash_equals($revisionBefore, $sourceIdentity['revision_after'])) {
                    throw new RuntimeException('Learning state changed during benchmark rebuild decomposition.');
                }

                [$currentGraphSizeBytes, $graphReplaceMs] = $this->timed(function () use (
                    $root,
                    $relations,
                    $sourceIdentity,
                ): int {
                    $database = $root . '/.derived/lineage/graph.sqlite';
                    $directory = dirname($database);
                    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                        throw new RuntimeException('Unable to create benchmark graph directory: ' . $directory);
                    }

                    (new GraphStore($database))->replace(
                        $relations,
                        sourceRevision: $sourceIdentity['revision_after'],
                        sourceFingerprint: $sourceIdentity['fingerprint'],
                        allowEmpty: true,
                    );

                    $size = filesize($database);
                    if (!is_int($size)) {
                        throw new RuntimeException('Unable to read benchmark graph size: ' . $database);
                    }

                    return $size;
                });

                $totalMs = (microtime(true) - $totalStartedAt) * 1000.0;
                $currentRelationDigest = $this->relationDigest($relations);
                if ($relationDigest !== null && !hash_equals($relationDigest, $currentRelationDigest)) {
                    throw new RuntimeException('Relation projection changed across identical benchmark repetitions.');
                }
                $relationDigest = $currentRelationDigest;
                $relationCount = count($relations);
                $graphSizeBytes = $currentGraphSizeBytes;

                if ($relationCount !== $scaleRecords * 2) {
                    throw new RuntimeException(sprintf(
                        'Unexpected relation count for scale %d: expected %d, got %d.',
                        $scaleRecords,
                        $scaleRecords * 2,
                        $relationCount,
                    ));
                }

                $rawRepetitions[] = [
                    'total_ms' => $totalMs,
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                    'graph_size_bytes' => $currentGraphSizeBytes,
                    'phases_ms' => [
                        'source_revision_before' => $revisionBeforeMs,
                        'catalog_load' => $catalogLoadMs,
                        'active_note_projection' => $activeNoteProjectionMs,
                        'relation_projection' => $relationProjectionMs,
                        'source_fingerprint_and_revision_after' => $sourceIdentityMs,
                        'graph_replace' => $graphReplaceMs,
                    ],
                ];
            }

            $service->verifyCurrent($root);

            memory_reset_peak_usage();
            $publicStartedAt = microtime(true);
            $service->rebuild($root, $root);
            $publicRebuildAnchorMs = (microtime(true) - $publicStartedAt) * 1000.0;
            $publicRebuildPeakMemoryBytes = memory_get_peak_usage(true);
            $service->verifyCurrent($root);

            $summary = $this->summarize($rawRepetitions, $publicRebuildAnchorMs);

            return [
                'scale_records' => $scaleRecords,
                'finding_count' => $scaleRecords,
                'active_note_count' => $scaleRecords,
                'durable_documents' => $scaleRecords * 2,
                'relation_count' => $relationCount,
                'relation_digest' => $relationDigest ?? throw new RuntimeException('Missing relation digest.'),
                'graph_size_bytes' => $graphSizeBytes,
                'public_rebuild_anchor_ms' => $publicRebuildAnchorMs,
                'public_rebuild_peak_memory_bytes' => $publicRebuildPeakMemoryBytes,
                'raw_repetitions' => $rawRepetitions,
                'summary' => $summary,
            ];
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function createCorpus(int $scaleRecords): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-lineage-rebuild-' . $scaleRecords . '-' . bin2hex(random_bytes(6));
        $findingDirectory = $root . '/findings/validated';
        if (!mkdir($findingDirectory, 0o775, true) && !is_dir($findingDirectory)) {
            throw new RuntimeException('Unable to create benchmark corpus: ' . $findingDirectory);
        }

        $noteRepository = new LearningNoteRepository();
        for ($index = 1; $index <= $scaleRecords; ++$index) {
            $suffix = sprintf('%06x', $index);
            $findingId = 'finding.' . self::BENCHMARK_DATE . '.' . $suffix;
            $noteId = 'learning-note.' . self::BENCHMARK_DATE . '.' . $suffix;
            $unitPath = 'src/Unit' . $index . '.php';

            $finding = [
                'id' => $findingId,
                'task_id' => 'SCALE-' . $index,
                'session' => 'scale-session-' . $index,
                'created_at' => self::BENCHMARK_DATE . 'T00:00:00+00:00',
                'created_by' => 'lineage-rebuild-benchmark',
                'scope' => [$unitPath],
                'observation' => 'Observed reusable scale behavior for unit ' . $index . '.',
                'evidence' => [[
                    'type' => 'file_reference',
                    'path' => $unitPath,
                    'line' => 1,
                ]],
                'hypothesis' => 'The scale behavior may be reusable for unit ' . $index . '.',
                'validated_conclusion' => 'The scale behavior is reusable for unit ' . $index . '.',
                'confidence' => 'high',
                'validation_status' => 'validated',
                'status' => 'validated',
                'sensitivity' => 'public',
            ];
            $encodedFinding = json_encode(
                $finding,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
            $this->write($findingDirectory . '/' . $findingId . '.json', $encodedFinding);

            $noteRepository->publish($root, new LearningNote(
                id: $noteId,
                patternKey: 'scale.case_' . $suffix,
                status: LearningNoteStatus::ACTIVE,
                scope: [$unitPath],
                tags: ['scale'],
                sourceFindings: [$findingId],
                sourceProposals: [],
                validationCase: new ValidationCase('Given scale evidence.', 'When rebuilding lineage.', 'Then preserve the relation.'),
                repositoryEvidence: [],
                content: new LearningNoteContent(
                    title: 'Scale precedent ' . $index,
                    context: 'Representative generated corpus for lineage rebuild measurement.',
                    guidance: 'Keep the owner rebuild deterministic at scale.',
                    whyItWorks: 'Each note has one validated source Finding and one bounded scope.',
                    whenToApply: 'When measuring lineage rebuild behavior.',
                    whenNotToApply: 'Outside deterministic benchmark evidence.',
                    verification: 'Rebuild the owner lineage and compare relation identity.',
                ),
                createdAt: self::BENCHMARK_DATE . 'T00:00:00+00:00',
                updatedAt: self::BENCHMARK_DATE . 'T00:00:00+00:00',
            ));
        }

        return $root;
    }

    /**
     * @param list<array{
     *   total_ms: float,
     *   peak_memory_bytes: int,
     *   graph_size_bytes: int,
     *   phases_ms: array<string, float>
     * }> $rawRepetitions
     * @return array{
     *   total_ms: array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float},
     *   peak_memory_bytes: array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float},
     *   phases_ms: array<string, array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float}>,
     *   phase_share_percent: array<string, float>,
     *   largest_phase: string,
     *   largest_phase_share_percent: float,
     *   largest_phase_cv_percent: float,
     *   profile_vs_public_anchor_percent: float
     * }
     */
    private function summarize(array $rawRepetitions, float $publicRebuildAnchorMs): array
    {
        $totalValues = array_map(static fn (array $row): float => $row['total_ms'], $rawRepetitions);
        $memoryValues = array_map(static fn (array $row): float => (float) $row['peak_memory_bytes'], $rawRepetitions);
        $totalStats = $this->statistics($totalValues);
        $memoryStats = $this->statistics($memoryValues);

        $phaseValues = [];
        foreach ($rawRepetitions as $row) {
            foreach ($row['phases_ms'] as $phase => $duration) {
                $phaseValues[$phase][] = $duration;
            }
        }

        $phaseStats = [];
        $phaseShares = [];
        $largestPhase = '';
        $largestShare = -1.0;
        foreach ($phaseValues as $phase => $values) {
            $stats = $this->statistics($values);
            $phaseStats[$phase] = $stats;
            $share = $totalStats['median'] <= 0.0 ? 0.0 : ($stats['median'] / $totalStats['median']) * 100.0;
            $phaseShares[$phase] = $share;
            if ($share > $largestShare) {
                $largestPhase = $phase;
                $largestShare = $share;
            }
        }

        if ($largestPhase === '') {
            throw new RuntimeException('Benchmark produced no phase measurements.');
        }

        $anchorDelta = $publicRebuildAnchorMs <= 0.0
            ? 0.0
            : (($totalStats['median'] - $publicRebuildAnchorMs) / $publicRebuildAnchorMs) * 100.0;

        return [
            'total_ms' => $totalStats,
            'peak_memory_bytes' => $memoryStats,
            'phases_ms' => $phaseStats,
            'phase_share_percent' => $phaseShares,
            'largest_phase' => $largestPhase,
            'largest_phase_share_percent' => $largestShare,
            'largest_phase_cv_percent' => $phaseStats[$largestPhase]['coefficient_of_variation_percent'],
            'profile_vs_public_anchor_percent' => $anchorDelta,
        ];
    }

    /**
     * @param list<float> $values
     * @return array{median: float, mean: float, min: float, max: float, stddev: float, coefficient_of_variation_percent: float}
     */
    private function statistics(array $values): array
    {
        if ($values === []) {
            throw new RuntimeException('Cannot summarize an empty benchmark sample.');
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
        $mean = array_sum($values) / $count;
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= $count;
        $stddev = sqrt($variance);

        return [
            'median' => $median,
            'mean' => $mean,
            'min' => $values[0],
            'max' => $values[$count - 1],
            'stddev' => $stddev,
            'coefficient_of_variation_percent' => $mean <= 0.0 ? 0.0 : ($stddev / $mean) * 100.0,
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return array{0: T, 1: float}
     */
    private function timed(callable $callback): array
    {
        $startedAt = microtime(true);
        $value = $callback();

        return [$value, (microtime(true) - $startedAt) * 1000.0];
    }

    private function invokePrivateString(
        ReflectionMethod $method,
        LearningLineageService $service,
        string $root,
    ): string {
        $value = $method->invoke($service, $root);
        if (!is_string($value)) {
            throw new RuntimeException('Private lineage identity method returned a non-string value.');
        }

        return $value;
    }

    /** @param list<GraphRelation> $relations */
    private function relationDigest(array $relations): string
    {
        $rows = [];
        foreach ($relations as $relation) {
            $rows[] = [
                'id' => $relation->id,
                'source_id' => $relation->sourceId,
                'kind' => $relation->kind,
                'target_ids' => $relation->targetIds,
            ];
        }

        return 'sha256:' . hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $report */
    private function renderMarkdown(array $report): string
    {
        $lines = [
            '# Learning lineage rebuild phase benchmark',
            '',
            '- Issue: `voku/agent-learning#88`',
            '- Git SHA: `' . (string) $report['git_sha'] . '`',
            '- PHP: `' . (string) $report['php_version'] . '`',
            '- Repetitions per scale: `' . (string) $report['repetitions'] . '`',
            '- Scale unit: one validated Finding + one active LearningNote',
            '',
            '| scale | public rebuild | profiled total median | largest phase | share | phase CV | graph bytes | peak memory median |',
            '|---:|---:|---:|---|---:|---:|---:|---:|',
        ];

        $results = $report['results'] ?? null;
        if (!is_array($results)) {
            throw new RuntimeException('Benchmark report is missing results.');
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new RuntimeException('Benchmark result row must be an array.');
            }
            $summary = $result['summary'] ?? null;
            if (!is_array($summary)) {
                throw new RuntimeException('Benchmark result row is missing summary data.');
            }
            $total = $summary['total_ms'] ?? null;
            if (!is_array($total)) {
                throw new RuntimeException('Benchmark result row is missing total statistics.');
            }
            $memory = $summary['peak_memory_bytes'] ?? null;
            if (!is_array($memory)) {
                throw new RuntimeException('Benchmark result row is missing memory statistics.');
            }

            $lines[] = sprintf(
                '| %d | %.3f ms | %.3f ms | `%s` | %.2f%% | %.2f%% | %d | %.0f |',
                (int) ($result['scale_records'] ?? 0),
                (float) ($result['public_rebuild_anchor_ms'] ?? 0.0),
                (float) ($total['median'] ?? 0.0),
                (string) ($summary['largest_phase'] ?? ''),
                (float) ($summary['largest_phase_share_percent'] ?? 0.0),
                (float) ($summary['largest_phase_cv_percent'] ?? 0.0),
                (int) ($result['graph_size_bytes'] ?? 0),
                (float) ($memory['median'] ?? 0.0),
            );
        }

        $lines[] = '';
        $lines[] = '## Phase medians';
        $lines[] = '';
        $lines[] = '| scale | source revision before | catalog load | active-note projection | relation projection | fingerprint + revision after | graph replace |';
        $lines[] = '|---:|---:|---:|---:|---:|---:|---:|';

        foreach ($results as $result) {
            if (!is_array($result) || !is_array($result['summary'] ?? null)) {
                continue;
            }
            $phaseStats = $result['summary']['phases_ms'] ?? null;
            if (!is_array($phaseStats)) {
                continue;
            }

            $lines[] = sprintf(
                '| %d | %.3f ms | %.3f ms | %.3f ms | %.3f ms | %.3f ms | %.3f ms |',
                (int) ($result['scale_records'] ?? 0),
                $this->phaseMedian($phaseStats, 'source_revision_before'),
                $this->phaseMedian($phaseStats, 'catalog_load'),
                $this->phaseMedian($phaseStats, 'active_note_projection'),
                $this->phaseMedian($phaseStats, 'relation_projection'),
                $this->phaseMedian($phaseStats, 'source_fingerprint_and_revision_after'),
                $this->phaseMedian($phaseStats, 'graph_replace'),
            );
        }

        $lines[] = '';
        $lines[] = 'The benchmark deliberately does not classify or optimize the result. Classification is a review step after the raw evidence exists; only `DOMINANT_PHASE` may authorize a later optimization slice.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $phaseStats */
    private function phaseMedian(array $phaseStats, string $phase): float
    {
        $stats = $phaseStats[$phase] ?? null;
        if (!is_array($stats)) {
            return 0.0;
        }

        return (float) ($stats['median'] ?? 0.0);
    }

    private function environmentString(string $name): string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? 'local' : $value;
    }

    private function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write file: ' . $path);
        }
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                if (!rmdir($file->getPathname())) {
                    throw new RuntimeException('Unable to remove benchmark directory: ' . $file->getPathname());
                }
                continue;
            }
            if (!unlink($file->getPathname())) {
                throw new RuntimeException('Unable to remove benchmark file: ' . $file->getPathname());
            }
        }
        if (!rmdir($root)) {
            throw new RuntimeException('Unable to remove benchmark root: ' . $root);
        }
    }
}

exit((new LineageRebuildBenchmark())->run());
