<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class MemoryStalenessPolicy
{
    public function __construct(
        private readonly StalenessThresholds $thresholds = new StalenessThresholds(),
    ) {
    }

    public function evaluate(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): ?EvolutionDecision
    {
        if ($summary->guidanceType !== GuidanceType::MEMORY) {
            return null;
        }
        if ($summary->eligibleCount < $this->thresholds->eligibleSessions) {
            return null;
        }
        if (($summary->irrelevantCount + $summary->notUsedCount) < $this->thresholds->negativeSignals || $summary->helpfulCount > 0) {
            return null;
        }

        return new EvolutionDecision(
            EvolutionDecisionType::STALE_CANDIDATE,
            $summary->guidanceId,
            GuidanceType::MEMORY,
            null,
            $summary->evidenceEventIds,
            $summary->distinctTaskIds,
            'Memory guidance was repeatedly eligible but marked irrelevant or not used, with no helpful outcome.',
            'This is only a review candidate; inactivity does not prove the memory should be deleted.',
            $sourceProposal instanceof Proposal ? $sourceProposal->scope : [],
            ['manual stale-guidance review'],
            $sourceProposal instanceof Proposal ? $sourceProposal->sourceFindings : [],
            Action::DELETE,
            $sourceProposal?->new,
            null,
        );
    }
}
