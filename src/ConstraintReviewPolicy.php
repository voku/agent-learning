<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintReviewPolicy
{
    public function evaluate(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): ?EvolutionDecision
    {
        if ($summary->guidanceType !== GuidanceType::CONSTRAINT || $summary->harmfulCount === 0) {
            return null;
        }

        return new EvolutionDecision(
            EvolutionDecisionType::STALE_CANDIDATE,
            $summary->guidanceId,
            GuidanceType::CONSTRAINT,
            null,
            $summary->evidenceEventIds,
            $summary->distinctTaskIds,
            'Constraint guidance has harmful or false-positive review evidence and should be reviewed.',
            'Hard constraints must not become stale because of inactivity; review requires concrete negative evidence.',
            $sourceProposal instanceof Proposal ? $sourceProposal->scope : [],
            ['inspect false positives, suppressions, bypasses, disabled registration, and validation health'],
            $sourceProposal instanceof Proposal ? $sourceProposal->sourceFindings : [],
            null,
            null,
            null,
        );
    }
}
