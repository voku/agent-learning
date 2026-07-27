<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class SkillStalenessPolicy
{
    public function __construct(
        private readonly StalenessThresholds $thresholds = new StalenessThresholds(),
    ) {
    }

    public function evaluate(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): ?EvolutionDecision
    {
        if ($summary->guidanceType !== GuidanceType::SKILL) {
            return null;
        }
        if ($summary->eligibleCount < $this->thresholds->eligibleSessions) {
            return null;
        }
        if (($summary->irrelevantCount + $summary->notUsedCount) < $this->thresholds->negativeSignals || $summary->helpfulCount > 0) {
            return null;
        }

        $proposalExtras = [];
        $scopeJustification = $sourceProposal?->raw['scope_justification'] ?? null;
        if (is_string($scopeJustification) && trim($scopeJustification) !== '') {
            $proposalExtras['scope_justification'] = $scopeJustification;
        }

        return new EvolutionDecision(
            EvolutionDecisionType::STALE_CANDIDATE,
            $summary->guidanceId,
            GuidanceType::SKILL,
            null,
            $summary->evidenceEventIds,
            $summary->distinctTaskIds,
            'Skill guidance was repeatedly eligible but marked irrelevant or not used, with no helpful outcome.',
            'This is only a review candidate; inactivity does not prove the skill should be deleted.',
            $sourceProposal instanceof Proposal ? $sourceProposal->scope : [],
            ['manual stale-guidance review'],
            $sourceProposal instanceof Proposal ? $sourceProposal->sourceFindings : [],
            Action::DELETE,
            $sourceProposal?->new,
            null,
            $proposalExtras,
        );
    }
}
