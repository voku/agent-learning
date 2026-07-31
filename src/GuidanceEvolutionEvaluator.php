<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class GuidanceEvolutionEvaluator
{
    public function __construct(
        private readonly GuidanceUsageProjector $projector = new GuidanceUsageProjector(),
        private readonly FindingToMemoryPromotionPolicy $findingToMemoryPolicy = new FindingToMemoryPromotionPolicy(),
        private readonly MemoryToSkillPromotionPolicy $memoryToSkillPolicy = new MemoryToSkillPromotionPolicy(),
        private readonly SkillToConstraintPromotionPolicy $skillToConstraintPolicy = new SkillToConstraintPromotionPolicy(),
        private readonly MemoryStalenessPolicy $memoryStalenessPolicy = new MemoryStalenessPolicy(),
        private readonly SkillStalenessPolicy $skillStalenessPolicy = new SkillStalenessPolicy(),
        private readonly ConstraintReviewPolicy $constraintReviewPolicy = new ConstraintReviewPolicy(),
    ) {
    }

    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     */
    public function evaluate(array $findingsById, array $proposalsById, array $selectionEvents, array $outcomeEvents): GuidanceEvolutionResult
    {
        $summaries = $this->projector->project($selectionEvents, $outcomeEvents);
        $decisions = $this->findingToMemoryPolicy->evaluate($findingsById);

        foreach ($summaries as $summary) {
            $sourceProposal = $proposalsById[$summary->guidanceId] ?? null;
            foreach ($this->summaryPolicies($summary, $sourceProposal) as $decision) {
                if ($decision instanceof EvolutionDecision) {
                    $decisions[] = $decision;
                    continue 2;
                }
            }
            $decisions[] = new EvolutionDecision(
                EvolutionDecisionType::NO_ACTION,
                $summary->guidanceId,
                $summary->guidanceType,
                null,
                $summary->evidenceEventIds,
                $summary->distinctTaskIds,
                'No conservative promotion, staleness, replacement, or conflict gate was met.',
                'The projection observes selection and explicit feedback only.',
                $sourceProposal instanceof Proposal ? $sourceProposal->scope : [],
                $sourceProposal instanceof Proposal ? $sourceProposal->validation : [],
                $sourceProposal instanceof Proposal ? $sourceProposal->sourceFindings : [],
            );
        }

        usort($decisions, static function (EvolutionDecision $a, EvolutionDecision $b): int {
            return [$a->guidanceId, $a->type->value, $a->stableKey()] <=> [$b->guidanceId, $b->type->value, $b->stableKey()];
        });

        return new GuidanceEvolutionResult($summaries, $decisions);
    }

    /**
     * @return list<EvolutionDecision|null>
     */
    private function summaryPolicies(GuidanceUsageSummary $summary, ?Proposal $sourceProposal): array
    {
        return [
            $this->memoryToSkillPolicy->evaluate($summary, $sourceProposal),
            $this->skillToConstraintPolicy->evaluate($summary, $sourceProposal),
            $this->memoryStalenessPolicy->evaluate($summary, $sourceProposal),
            $this->skillStalenessPolicy->evaluate($summary, $sourceProposal),
            $this->constraintReviewPolicy->evaluate($summary, $sourceProposal),
        ];
    }
}
