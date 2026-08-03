<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Builds compact, deterministic views over immutable learning evidence.
 *
 * The projections intentionally retain IDs and content digests rather than
 * copying full finding/proposal bodies. Raw files remain the audit source.
 */
final class HistoryProjectionBuilder
{
    private const string SNAPSHOT_PATH = 'history/active-guidance.snapshot.json';

    private const string CHRONICLE_PATH = 'history/chronicle.jsonl';

    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     */
    public function build(string $root, array $findingsById, array $proposalsById): HistoryProjection
    {
        $sourceFiles = $this->sourceFiles($root);
        $sourceBytes = array_sum(array_column($sourceFiles, 'bytes'));
        $inputDigest = hash('sha256', $this->json($sourceFiles));

        ksort($proposalsById);
        $activeGuidance = [];
        $chronicle = [];
        foreach ($proposalsById as $proposal) {
            if (in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true)) {
                $activeGuidance[] = [
                    'guidance_id' => $proposal->id,
                    'guidance_type' => $proposal->targetType,
                    'status' => $proposal->status->value,
                    'scope' => $this->sorted($proposal->scope),
                    'source_finding_ids' => $this->sorted($proposal->sourceFindings),
                    'content_digest' => $this->digest($proposal->raw),
                ];
                continue;
            }
            if (in_array($proposal->status, [ProposalStatus::RETIRED, ProposalStatus::REJECTED, ProposalStatus::ACKNOWLEDGED], true)) {
                $chronicle[] = $this->proposalChronicleRecord($proposal);
            }
        }

        ksort($findingsById);
        foreach ($findingsById as $finding) {
            if (!in_array($finding->status, [FindingStatus::SUPERSEDED, FindingStatus::ARCHIVED, FindingStatus::REJECTED, FindingStatus::INVALIDATED, FindingStatus::CONSOLIDATED], true)) {
                continue;
            }
            $chronicle[] = [
                'schema_version' => '1.0',
                'record_type' => 'finding-history',
                'source_id' => $finding->id,
                'lifecycle_status' => $finding->status->value,
                'task_id' => $finding->taskId,
                'source_ids' => $this->findingSourceIds($finding),
                'content_digest' => $this->digest($finding->raw),
                'summary' => 'Finding is ' . $finding->status->value . '; consult immutable source evidence for the complete record.',
            ];
        }
        usort($chronicle, static fn (array $left, array $right): int => [$left['record_type'], $left['source_id']] <=> [$right['record_type'], $right['source_id']]);

        $snapshot = $this->json([
            'schema_version' => '1.0',
            'projection_type' => 'active-guidance-snapshot',
            'source_digest' => $inputDigest,
            'active_guidance' => $activeGuidance,
        ]);
        $chronicleContents = implode("\n", array_map(fn (array $record): string => $this->json($record, false), $chronicle));
        if ($chronicleContents !== '') {
            $chronicleContents .= "\n";
        }
        $manifest = $this->json([
            'schema_version' => '1.0',
            'projection_type' => 'agent-learning-history-manifest',
            'source_digest' => $inputDigest,
            'source_files' => $sourceFiles,
            'active_guidance_snapshot' => [
                'path' => self::SNAPSHOT_PATH,
                'sha256' => hash('sha256', $snapshot),
            ],
            'chronicle' => [
                'path' => self::CHRONICLE_PATH,
                'sha256' => hash('sha256', $chronicleContents),
            ],
        ]);

        return new HistoryProjection(
            $inputDigest,
            $snapshot,
            $chronicleContents,
            $manifest,
            count($activeGuidance),
            count($chronicle),
            $sourceFiles,
            $sourceBytes,
        );
    }

    public function write(string $root, HistoryProjection $projection): void
    {
        $historyDirectory = rtrim($root, '/') . '/history';
        if (!is_dir($historyDirectory) && !mkdir($historyDirectory, 0777, true) && !is_dir($historyDirectory)) {
            throw new ValidationException($historyDirectory, null, null, 'cannot create history projection directory');
        }
        $this->writeFile($historyDirectory . '/active-guidance.snapshot.json', $projection->snapshot);
        $this->writeFile($historyDirectory . '/chronicle.jsonl', $projection->chronicle);
        $this->writeFile($historyDirectory . '/projection-manifest.json', $projection->manifest);
    }

    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     */
    public function assertFresh(string $root, array $findingsById, array $proposalsById): HistoryProjection
    {
        $projection = $this->build($root, $findingsById, $proposalsById);
        $historyDirectory = rtrim($root, '/') . '/history';
        foreach ([
            'active-guidance.snapshot.json' => $projection->snapshot,
            'chronicle.jsonl' => $projection->chronicle,
            'projection-manifest.json' => $projection->manifest,
        ] as $file => $expected) {
            $path = $historyDirectory . '/' . $file;
            $actual = is_file($path) ? file_get_contents($path) : false;
            if ($actual !== $expected) {
                throw new ValidationException($path, null, null, 'history projection is missing or stale; run history-rebuild');
            }
        }

        return $projection;
    }

    /**
     * @return list<array{path: string, bytes: int, sha256: string}>
     */
    private function sourceFiles(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];
        foreach (['findings', 'proposals'] as $directory) {
            $base = $root . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                    continue;
                }
                $this->appendSourceFile($files, $root, $fileInfo->getPathname());
            }
        }
        foreach (['history/recall-selections.jsonl', 'history/outcomes.jsonl'] as $relativePath) {
            $path = $root . '/' . $relativePath;
            if (is_file($path)) {
                $this->appendSourceFile($files, $root, $path);
            }
        }
        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $files;
    }

    /** @param list<array{path: string, bytes: int, sha256: string}> $files */
    private function appendSourceFile(array &$files, string $root, string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ValidationException($path, null, null, 'cannot read history projection source');
        }
        $files[] = [
            'path' => substr(str_replace('\\', '/', $path), strlen($root) + 1),
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    /** @return array<string, mixed> */
    private function proposalChronicleRecord(Proposal $proposal): array
    {
        return [
            'schema_version' => '1.0',
            'record_type' => 'guidance-history',
            'source_id' => $proposal->id,
            'lifecycle_status' => $proposal->status->value,
            'source_ids' => $this->sorted($proposal->sourceFindings),
            'content_digest' => $this->digest($proposal->raw),
            'summary' => 'Guidance is ' . $proposal->status->value . '; consult immutable source evidence for the complete record.',
        ];
    }

    /** @return list<string> */
    private function findingSourceIds(Finding $finding): array
    {
        $ids = [$finding->id];
        foreach (['supersedes_findings', 'conflicts_with'] as $field) {
            $references = $finding->raw[$field] ?? [];
            if (!is_array($references)) {
                continue;
            }
            foreach ($references as $reference) {
                if (is_string($reference)) {
                    $ids[] = $reference;
                }
            }
        }

        return $this->sorted($ids);
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->json($this->sortRecursively($value), false));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @param array<mixed> $value */
    private function json(array $value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags) . "\n";
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $contents) === false || !rename($temporary, $path)) {
            throw new ValidationException($path, null, null, 'cannot write history projection');
        }
    }
}
