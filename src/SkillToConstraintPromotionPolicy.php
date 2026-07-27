<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class SkillToConstraintPromotionPolicy
{
    public function __construct(
        private readonly PromotionThresholds $thresholds = new PromotionThresholds(),
    ) {
    }

    public function evaluate(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): ?EvolutionDecision
    {
        if ($summary->guidanceType !== GuidanceType::SKILL || !$sourceProposal instanceof Proposal) {
            return null;
        }
        if (
            $summary->selectedCount < $this->thresholds->skillToConstraintSelectedSessions
            ||
            $summary->appliedCount < $this->thresholds->skillToConstraintAppliedSessions
            ||
            $summary->helpfulCount === 0
            ||
            $summary->harmfulCount !== 0
            ||
            !$this->hasConstraintEvidence($sourceProposal)
        ) {
            return null;
        }

        $proposalExtras = ['constraint' => $sourceProposal->raw['constraint_candidate']];
        $scopeJustification = $sourceProposal->raw['scope_justification'] ?? null;
        if (is_string($scopeJustification) && trim($scopeJustification) !== '') {
            $proposalExtras['scope_justification'] = $scopeJustification;
        }

        return new EvolutionDecision(
            EvolutionDecisionType::PROMOTION_CANDIDATE,
            $summary->guidanceId,
            GuidanceType::SKILL,
            GuidanceType::CONSTRAINT,
            $summary->evidenceEventIds,
            $summary->distinctTaskIds,
            'Skill guidance was repeatedly selected, applied, and helpful, and its behavior is declared objectively detectable.',
            'A reviewer must confirm false-positive risk and provide or inspect local rule fixtures before activation.',
            $sourceProposal->scope,
            $sourceProposal->validation,
            $sourceProposal->sourceFindings,
            Action::ADD,
            null,
            $sourceProposal->new,
            $proposalExtras,
        );
    }

    private function hasConstraintEvidence(Proposal $sourceProposal): bool
    {
        $falsePositiveRisk = $sourceProposal->raw['false_positive_risk'] ?? null;

        return ($sourceProposal->raw['objectively_detectable'] ?? false) === true
            && ($falsePositiveRisk === 'low' || is_string($sourceProposal->raw['false_positive_justification'] ?? null))
            && $sourceProposal->validation !== []
            && (
                is_array($sourceProposal->raw['local_rule_examples'] ?? null)
                || is_array($sourceProposal->raw['fixtures'] ?? null)
            )
            && is_array($sourceProposal->raw['constraint_candidate'] ?? null)
            && ($sourceProposal->raw['repetitive_manual_review'] ?? false) === true;
    }
}
