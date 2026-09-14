<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CorpusAnalyticsService
{
    public function __construct(
        private readonly JsonlRecordReader $jsonlReader = new JsonlRecordReader(),
    ) {
    }

    public function analyze(string $root): CorpusAnalysisResult
    {
        $retiredHistory = [];
        foreach ($this->jsonlReader->read($root . '/history/retired-proposals.jsonl') as $r) {
            if (isset($r['proposal_id']) && is_string($r['proposal_id'])) {
                $retiredHistory[$r['proposal_id']] = $r;
            }
        }

        $rejectedHistory = [];
        foreach ($this->jsonlReader->read($root . '/history/rejected-proposals.jsonl') as $r) {
            if (isset($r['proposal_id']) && is_string($r['proposal_id'])) {
                $rejectedHistory[$r['proposal_id']] = $r;
            }
        }

        $acknowledgedHistory = [];
        foreach ($this->jsonlReader->read($root . '/history/acknowledged-proposals.jsonl') as $r) {
            if (isset($r['proposal_id']) && is_string($r['proposal_id'])) {
                $acknowledgedHistory[$r['proposal_id']] = $r;
            }
        }

        /** @var array<string, array{id: string, status: string, task_id: string, created_at: ?DateTimeImmutable, cohort: string, proposals: list<string>}> $findings */
        $findings = [];
        $findingDirs = ['archived', 'candidate', 'consolidated', 'invalidated', 'superseded', 'validated'];
        foreach ($findingDirs as $dir) {
            $dirPath = $root . '/findings/' . $dir;
            if (!is_dir($dirPath)) {
                continue;
            }
            foreach ($this->jsonFiles($dirPath) as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                /** @var array<string, mixed>|null $data */
                $data = json_decode($content, true);
                if (!$data || !isset($data['id']) || !is_string($data['id'])) {
                    continue;
                }
                $id = $data['id'];
                $createdAt = isset($data['created_at']) && is_string($data['created_at'])
                    ? new DateTimeImmutable($data['created_at'])
                    : null;
                $cohort = $createdAt ? $createdAt->format('Y-m') : 'unknown';
                $findings[$id] = [
                    'id' => $id,
                    'status' => is_string($data['status'] ?? null) ? (string)$data['status'] : $dir,
                    'task_id' => is_string($data['task_id'] ?? null) ? (string)$data['task_id'] : 'unknown',
                    'created_at' => $createdAt,
                    'cohort' => $cohort,
                    'proposals' => [],
                ];
            }
        }

        /** @var array<string, array{id: string, status: string, action: string, target_type: ?string, target: ?string, created_at: ?DateTimeImmutable, cohort: string, source_findings: list<string>, reason: string, superseded_by: ?string, terminal_info: array<string, mixed>|null}> $proposals */
        $proposals = [];
        $proposalDirs = ['acknowledged', 'applied', 'approved', 'candidate', 'rejected', 'retired'];
        foreach ($proposalDirs as $dir) {
            $dirPath = $root . '/proposals/' . $dir;
            if (!is_dir($dirPath)) {
                continue;
            }
            foreach ($this->jsonFiles($dirPath) as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                /** @var array<string, mixed>|null $data */
                $data = json_decode($content, true);
                if (!$data || !isset($data['id']) || !is_string($data['id'])) {
                    continue;
                }
                $id = $data['id'];
                $createdAt = isset($data['created_at']) && is_string($data['created_at'])
                    ? new DateTimeImmutable($data['created_at'])
                    : null;
                $cohort = $createdAt ? $createdAt->format('Y-m') : 'unknown';
                /** @var list<string> $sourceFindings */
                $sourceFindings = is_array($data['source_findings'] ?? null) ? array_values(array_filter($data['source_findings'], 'is_string')) : [];

                $proposals[$id] = [
                    'id' => $id,
                    'status' => is_string($data['status'] ?? null) ? (string)$data['status'] : $dir,
                    'action' => is_string($data['action'] ?? null) ? (string)$data['action'] : 'UNKNOWN',
                    'target_type' => is_string($data['target_type'] ?? null) ? (string)$data['target_type'] : null,
                    'target' => is_string($data['target'] ?? null) ? (string)$data['target'] : null,
                    'created_at' => $createdAt,
                    'cohort' => $cohort,
                    'source_findings' => $sourceFindings,
                    'reason' => is_string($data['reason'] ?? null) ? (string)$data['reason'] : '',
                    'superseded_by' => is_string($data['superseded_by'] ?? null) ? (string)$data['superseded_by'] : null,
                    'terminal_info' => $retiredHistory[$id] ?? $rejectedHistory[$id] ?? $acknowledgedHistory[$id] ?? null,
                ];

                foreach ($sourceFindings as $fid) {
                    if (isset($findings[$fid])) {
                        $findings[$fid]['proposals'][] = $id;
                    }
                }
            }
        }

        $activeNotesCount = count($this->jsonFiles($root . '/notes/active'));
        $constraintFiles = $this->jsonFiles($root . '/constraints/active');
        $activeConstraintsCount = count($constraintFiles);
        $proposalsWithConstraints = [];
        foreach ($constraintFiles as $cf) {
            $cContent = file_get_contents($cf);
            if ($cContent === false) {
                continue;
            }
            /** @var array<string, mixed>|null $cData */
            $cData = json_decode($cContent, true);
            if (is_array($cData) && isset($cData['source_proposal']) && is_string($cData['source_proposal'])) {
                $proposalsWithConstraints[$cData['source_proposal']] = true;
            }
        }

        $findingsWithProposals = count(array_filter($findings, static fn (array $f): bool => count($f['proposals']) > 0));
        $totalFindings = count($findings);
        $totalProposals = count($proposals);

        // Cohort grouping
        $cohortKeys = array_unique(array_merge(
            array_column($findings, 'cohort'),
            array_column($proposals, 'cohort'),
        ));
        sort($cohortKeys);

        $cohorts = [];
        foreach ($cohortKeys as $c) {
            if ($c === 'unknown' && count($cohortKeys) > 1) {
                continue;
            }
            $cFindings = array_filter($findings, static fn (array $f): bool => $f['cohort'] === $c);
            $cProposals = array_filter($proposals, static fn (array $p): bool => $p['cohort'] === $c);

            $tasks = [];
            foreach ($cFindings as $f) {
                $tasks[$f['task_id']] = true;
            }

            $cFindingsWithP = array_filter($cFindings, static fn (array $f): bool => count($f['proposals']) > 0);

            $findingsPerP = [];
            $tasksPerP = [];
            $pSingleFinding = 0;
            $pMultiFinding = 0;
            $pSingleTask = 0;
            $pMultiTask = 0;
            $pSingleFindingSingleTask = 0;

            foreach ($cProposals as $p) {
                $fCount = count($p['source_findings']);
                $findingsPerP[] = $fCount;
                if ($fCount === 1) {
                    ++$pSingleFinding;
                } else {
                    ++$pMultiFinding;
                }

                $pTasks = [];
                foreach ($p['source_findings'] as $fid) {
                    if (isset($findings[$fid])) {
                        $pTasks[$findings[$fid]['task_id']] = true;
                    }
                }
                $tCount = count($pTasks);
                $tasksPerP[] = $tCount;
                if ($tCount === 1) {
                    ++$pSingleTask;
                } elseif ($tCount > 1) {
                    ++$pMultiTask;
                }

                if ($fCount === 1 && $tCount === 1) {
                    ++$pSingleFindingSingleTask;
                }
            }

            $outcomes = [];
            foreach ($cProposals as $p) {
                $st = $p['status'];
                $outcomes[$st] = ($outcomes[$st] ?? 0) + 1;
            }
            ksort($outcomes);

            $fToPDurations = [];
            foreach ($cProposals as $p) {
                if (!$p['created_at']) {
                    continue;
                }
                foreach ($p['source_findings'] as $fid) {
                    if (isset($findings[$fid]) && $findings[$fid]['created_at']) {
                        $diff = $p['created_at']->getTimestamp() - $findings[$fid]['created_at']->getTimestamp();
                        if ($diff >= 0) {
                            $fToPDurations[] = $diff / 3600.0;
                        }
                    }
                }
            }

            $pToTerminalDays = [];
            foreach ($cProposals as $p) {
                if (!$p['created_at']) {
                    continue;
                }
                $termAtStr = $p['terminal_info']['retired_at'] ?? $p['terminal_info']['rejected_at'] ?? $p['terminal_info']['acknowledged_at'] ?? null;
                if (is_string($termAtStr)) {
                    $termAt = new DateTimeImmutable($termAtStr);
                    $diff = $termAt->getTimestamp() - $p['created_at']->getTimestamp();
                    if ($diff >= 0) {
                        $pToTerminalDays[] = $diff / 86400.0;
                    }
                }
            }

            $pCount = count($cProposals);
            $cohorts[$c] = [
                'finding_count' => count($cFindings),
                'distinct_tasks' => count($tasks),
                'findings_per_task_avg' => count($tasks) > 0 ? round(count($cFindings) / count($tasks), 2) : 0.0,
                'findings_with_proposal' => count($cFindingsWithP),
                'finding_to_proposal_rate' => count($cFindings) > 0 ? round(count($cFindingsWithP) / count($cFindings) * 100, 1) : 0.0,
                'proposal_count' => $pCount,
                'findings_per_proposal' => [
                    'avg' => $pCount > 0 ? round(array_sum($findingsPerP) / $pCount, 2) : 0.0,
                    'median' => self::median($findingsPerP),
                    'single_finding_count' => $pSingleFinding,
                    'multi_finding_count' => $pMultiFinding,
                    'single_finding_pct' => $pCount > 0 ? round($pSingleFinding / $pCount * 100, 1) : 0.0,
                ],
                'tasks_per_proposal' => [
                    'avg' => $pCount > 0 ? round(array_sum($tasksPerP) / $pCount, 2) : 0.0,
                    'median' => self::median($tasksPerP),
                    'single_task_count' => $pSingleTask,
                    'multi_task_count' => $pMultiTask,
                    'single_task_pct' => $pCount > 0 ? round($pSingleTask / $pCount * 100, 1) : 0.0,
                    'multi_task_pct' => $pCount > 0 ? round($pMultiTask / $pCount * 100, 1) : 0.0,
                ],
                'pure_1_to_1_count' => $pSingleFindingSingleTask,
                'pure_1_to_1_pct' => $pCount > 0 ? round($pSingleFindingSingleTask / $pCount * 100, 1) : 0.0,
                'proposal_outcomes' => $outcomes,
                'finding_to_proposal_hours' => [
                    'median' => round(self::median($fToPDurations), 1),
                    'p90' => round(self::p90($fToPDurations), 1),
                ],
                'proposal_to_terminal_days' => [
                    'median' => round(self::median($pToTerminalDays), 1),
                    'p90' => round(self::p90($pToTerminalDays), 1),
                ],
            ];
        }

        // Deconstruct the 80.7% (Issue #116)
        $compileDownCount = 0;
        $supersededCount = 0;
        $capturedInTargetCount = 0;
        $duplicateCount = 0;
        $staleOrDefunctCount = 0;
        $otherExplicitCount = 0;

        foreach ($proposals as $p) {
            if ($p['status'] !== 'retired') {
                continue;
            }
            $r = is_string($p['terminal_info']['reason'] ?? null)
                ? (string)$p['terminal_info']['reason']
                : $p['reason'];
            $lower = strtolower($r);

            if (isset($proposalsWithConstraints[$p['id']]) || str_contains($lower, 'constraint') || str_contains($lower, 'phpstan') || str_contains($lower, 'project rule') || str_contains($lower, 'compile-down')) {
                ++$compileDownCount;
            } elseif (str_contains($lower, 'duplicate')) {
                ++$duplicateCount;
            } elseif (str_contains($lower, 'superseded') || str_contains($lower, 'superseded_by') || !empty($p['superseded_by']) || str_contains($lower, 'rationale corrected') || str_contains($lower, 'wrong abstraction')) {
                ++$supersededCount;
            } elseif (str_contains($lower, 'fully captured') || str_contains($lower, 'captured in') || str_contains($lower, 'target skill') || str_contains($lower, 'live file')) {
                ++$capturedInTargetCount;
            } elseif (str_contains($lower, 'no longer present') || str_contains($lower, 'stale') || str_contains($lower, 'renamed') || str_contains($lower, 'obsolete')) {
                ++$staleOrDefunctCount;
            } else {
                ++$otherExplicitCount;
            }
        }

        $lifecycleBreakdown = [
            'total_proposals' => $totalProposals,
            'active_or_pending' => [
                'applied' => count(array_filter($proposals, static fn (array $p): bool => $p['status'] === 'applied')),
                'approved' => count(array_filter($proposals, static fn (array $p): bool => $p['status'] === 'approved')),
                'candidate' => count(array_filter($proposals, static fn (array $p): bool => $p['status'] === 'candidate')),
            ],
            'terminal_proposals' => [
                'rejected_before_activation' => count($rejectedHistory),
                'acknowledged_no_durable_learning' => count($acknowledgedHistory),
                'retired' => count($retiredHistory),
            ],
            'retired_semantic_buckets' => [
                'CAPTURED_IN_TARGET_HOME' => $capturedInTargetCount,
                'COMPILED_DOWN_TO_CONSTRAINT' => $compileDownCount,
                'SUPERSEDED_BY_PROPOSAL' => $supersededCount,
                'DUPLICATE_CONSOLIDATION' => $duplicateCount,
                'STALE_OR_DEFUNCT_TARGET' => $staleOrDefunctCount,
                'OTHER_EXPLICIT_REASON' => $otherExplicitCount,
            ],
        ];

        // Issue #117 Consolidation analysis
        $fToPCounts = [];
        foreach ($findings as $f) {
            $cnt = count($f['proposals']);
            $fToPCounts[$cnt] = ($fToPCounts[$cnt] ?? 0) + 1;
        }
        ksort($fToPCounts);

        $pToFCounts = [];
        foreach ($proposals as $p) {
            $cnt = count($p['source_findings']);
            $pToFCounts[$cnt] = ($pToFCounts[$cnt] ?? 0) + 1;
        }
        ksort($pToFCounts);

        $pToTCounts = [];
        foreach ($proposals as $p) {
            $pTasks = [];
            foreach ($p['source_findings'] as $fid) {
                if (isset($findings[$fid])) {
                    $pTasks[$findings[$fid]['task_id']] = true;
                }
            }
            $cnt = count($pTasks);
            $pToTCounts[$cnt] = ($pToTCounts[$cnt] ?? 0) + 1;
        }
        ksort($pToTCounts);

        // Classification
        $lastCohortKey = !empty($cohortKeys) ? end($cohortKeys) : null;
        $lastRate = $lastCohortKey && isset($cohorts[$lastCohortKey]) ? $cohorts[$lastCohortKey]['finding_to_proposal_rate'] : 100.0;
        $classification = ($lastRate < 35.0 && count($cohorts) > 1)
            ? 'HISTORICAL_ONLY'
            : 'CURRENT_EVALUATION';

        $consolidation = [
            'proposals_per_finding_distribution' => $fToPCounts,
            'findings_per_proposal_distribution' => $pToFCounts,
            'distinct_tasks_per_proposal_distribution' => $pToTCounts,
            'classification' => $classification,
        ];

        return new CorpusAnalysisResult(
            summary: [
                'total_findings' => $totalFindings,
                'total_proposals' => $totalProposals,
                'total_active_notes' => $activeNotesCount,
                'total_active_constraints' => $activeConstraintsCount,
                'findings_with_proposals' => $findingsWithProposals,
                'finding_to_proposal_rate' => $totalFindings > 0 ? round($findingsWithProposals / $totalFindings * 100, 1) : 0.0,
            ],
            cohorts: $cohorts,
            lifecycleBreakdown: $lifecycleBreakdown,
            consolidation: $consolidation,
        );
    }

    /**
     * @param list<int|float> $arr
     */
    private static function median(array $arr): float
    {
        sort($arr);
        $n = count($arr);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return ($n % 2 === 1) ? (float)$arr[$mid] : ($arr[$mid - 1] + $arr[$mid]) / 2.0;
    }

    /**
     * @param list<int|float> $arr
     */
    private static function p90(array $arr): float
    {
        sort($arr);
        $n = count($arr);
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int)ceil($n * 0.90) - 1;

        return (float)$arr[max(0, min($n - 1, $idx))];
    }

    /**
     * @return list<string>
     */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json' || $fileInfo->getSize() === 0) {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
        sort($files);

        return $files;
    }
}
