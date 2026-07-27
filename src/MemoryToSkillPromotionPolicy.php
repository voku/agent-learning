<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class MemoryToSkillPromotionPolicy
{
    public function __construct(
        private readonly PromotionThresholds $thresholds = new PromotionThresholds(),
    ) {
    }

    public function evaluate(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): ?EvolutionDecision
    {
        if ($summary->guidanceType !== GuidanceType::MEMORY || !$sourceProposal instanceof Proposal) {
            return null;
        }
        if (
            $summary->selectedCount < $this->thresholds->memoryToSkillSelectedSessions
            ||
            $summary->helpfulCount < $this->thresholds->memoryToSkillHelpfulSessions
            ||
            $summary->harmfulCount !== 0
            ||
            $summary->distinctTaskCount < 2
            ||
            $sourceProposal->sourceFindings === []
            ||
            !$this->isRecurringProcedure($sourceProposal)
        ) {
            return null;
        }

        $proposalExtras = [];
        $scopeJustification = $sourceProposal->raw['scope_justification'] ?? null;
        if (is_string($scopeJustification) && trim($scopeJustification) !== '') {
            $proposalExtras['scope_justification'] = $scopeJustification;
        }

        return new EvolutionDecision(
            EvolutionDecisionType::PROMOTION_CANDIDATE,
            $summary->guidanceId,
            GuidanceType::MEMORY,
            GuidanceType::SKILL,
            $summary->evidenceEventIds,
            $summary->distinctTaskIds,
            'Memory guidance was selected in closed sessions and explicitly marked helpful across independent tasks.',
            'The projection cannot prove internal model attention; reviewer must confirm this is a reusable procedure.',
            $sourceProposal->scope,
            $sourceProposal->validation,
            $sourceProposal->sourceFindings,
            Action::ADD,
            null,
            $sourceProposal->new,
            $proposalExtras,
        );
    }

    private function isRecurringProcedure(Proposal $sourceProposal): bool
    {
        return ($sourceProposal->raw['recurring_procedure'] ?? false) === true
            || str_contains(strtolower((string)$sourceProposal->new), 'step')
            || str_contains(strtolower((string)$sourceProposal->new), 'procedure');
    }
}
