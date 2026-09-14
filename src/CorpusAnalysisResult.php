<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class CorpusAnalysisResult
{
    /**
     * @param array{
     *     total_findings: int,
     *     total_proposals: int,
     *     total_active_notes: int,
     *     total_active_constraints: int,
     *     findings_with_proposals: int,
     *     finding_to_proposal_rate: float,
     * } $summary
     * @param array<string, array{
     *     finding_count: int,
     *     distinct_tasks: int,
     *     findings_per_task_avg: float,
     *     findings_with_proposal: int,
     *     finding_to_proposal_rate: float,
     *     proposal_count: int,
     *     findings_per_proposal: array{avg: float, median: float, single_finding_count: int, multi_finding_count: int, single_finding_pct: float},
     *     tasks_per_proposal: array{avg: float, median: float, single_task_count: int, multi_task_count: int, single_task_pct: float, multi_task_pct: float},
     *     pure_1_to_1_count: int,
     *     pure_1_to_1_pct: float,
     *     proposal_outcomes: array<string, int>,
     *     finding_to_proposal_hours: array{median: float, p90: float},
     *     proposal_to_terminal_days: array{median: float, p90: float},
     * }> $cohorts
     * @param array{
     *     total_proposals: int,
     *     active_or_pending: array{applied: int, approved: int, candidate: int},
     *     terminal_proposals: array{rejected_before_activation: int, acknowledged_no_durable_learning: int, retired: int},
     *     retired_semantic_buckets: array{
     *         CAPTURED_IN_TARGET_HOME: int,
     *         COMPILED_DOWN_TO_CONSTRAINT: int,
     *         SUPERSEDED_BY_PROPOSAL: int,
     *         DUPLICATE_CONSOLIDATION: int,
     *         STALE_OR_DEFUNCT_TARGET: int,
     *         OTHER_EXPLICIT_REASON: int,
     *     },
     * } $lifecycleBreakdown
     * @param array{
     *     proposals_per_finding_distribution: array<int, int>,
     *     findings_per_proposal_distribution: array<int, int>,
     *     distinct_tasks_per_proposal_distribution: array<int, int>,
     *     classification: string,
     * } $consolidation
     */
    public function __construct(
        public array $summary,
        public array $cohorts,
        public array $lifecycleBreakdown,
        public array $consolidation,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'cohorts' => $this->cohorts,
            'lifecycle_breakdown' => $this->lifecycleBreakdown,
            'consolidation' => $this->consolidation,
        ];
    }

    public function toText(): string
    {
        $out = "=== Learning Corpus Analytics ===\n\n";
        $out .= sprintf(
            "Summary: %d Findings, %d Proposals (%d with proposals, %.1f%% rate), %d Active Notes, %d Active Constraints\n\n",
            $this->summary['total_findings'],
            $this->summary['total_proposals'],
            $this->summary['findings_with_proposals'],
            $this->summary['finding_to_proposal_rate'],
            $this->summary['total_active_notes'],
            $this->summary['total_active_constraints'],
        );

        $out .= "--- Cohort Evolution ---\n";
        $out .= sprintf("%-10s | %8s | %8s | %10s | %9s | %12s | %11s\n", 'Cohort', 'Findings', 'Tasks', 'F->P Rate', 'Proposals', 'Pure 1:1 (%)', 'Latency (h)');
        $out .= str_repeat('-', 80) . "\n";
        foreach ($this->cohorts as $cohort => $data) {
            $out .= sprintf(
                "%-10s | %8d | %8d | %9.1f%% | %9d | %11.1f%% | %11.1f\n",
                $cohort,
                $data['finding_count'],
                $data['distinct_tasks'],
                $data['finding_to_proposal_rate'],
                $data['proposal_count'],
                $data['pure_1_to_1_pct'],
                $data['finding_to_proposal_hours']['median'],
            );
        }
        $out .= "\n";

        $out .= "--- Lifecycle Terminal Breakdown (Deconstructing the 80.7%) ---\n";
        $terminal = $this->lifecycleBreakdown['terminal_proposals'];
        $retiredBuckets = $this->lifecycleBreakdown['retired_semantic_buckets'];
        $out .= sprintf("Active / Pending: %d applied, %d approved, %d candidate\n",
            $this->lifecycleBreakdown['active_or_pending']['applied'],
            $this->lifecycleBreakdown['active_or_pending']['approved'],
            $this->lifecycleBreakdown['active_or_pending']['candidate'],
        );
        $out .= sprintf("Terminal Proposals: %d total (%d retired, %d rejected, %d acknowledged)\n",
            $terminal['retired'] + $terminal['rejected_before_activation'] + $terminal['acknowledged_no_durable_learning'],
            $terminal['retired'],
            $terminal['rejected_before_activation'],
            $terminal['acknowledged_no_durable_learning'],
        );
        $out .= "  - Captured in Target Canonical Guidance: " . $retiredBuckets['CAPTURED_IN_TARGET_HOME'] . "\n";
        $out .= "  - Compiled Down to Constraint: " . $retiredBuckets['COMPILED_DOWN_TO_CONSTRAINT'] . "\n";
        $out .= "  - Superseded by Proposal: " . $retiredBuckets['SUPERSEDED_BY_PROPOSAL'] . "\n";
        $out .= "  - Duplicate Consolidation: " . $retiredBuckets['DUPLICATE_CONSOLIDATION'] . "\n";
        $out .= "  - Stale / Defunct Target: " . $retiredBuckets['STALE_OR_DEFUNCT_TARGET'] . "\n";
        $out .= "  - Other Explicit Reason: " . $retiredBuckets['OTHER_EXPLICIT_REASON'] . "\n\n";

        $out .= "--- Consolidation & Dream Diagnostics ---\n";
        $out .= "Classification: " . $this->consolidation['classification'] . "\n";
        $out .= "Findings per Proposal distribution: " . json_encode($this->consolidation['findings_per_proposal_distribution']) . "\n";
        $out .= "Tasks per Proposal distribution: " . json_encode($this->consolidation['distinct_tasks_per_proposal_distribution']) . "\n";
        $out .= "Proposals per Finding distribution: " . json_encode($this->consolidation['proposals_per_finding_distribution']) . "\n";

        return $out;
    }
}
