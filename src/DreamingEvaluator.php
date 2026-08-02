<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;

final class DreamingEvaluator
{
    public function __construct(
        private readonly GuidanceEvolutionEvaluator $evolutionEvaluator = new GuidanceEvolutionEvaluator(),
        private readonly EvidenceQualityAuditor $evidenceQualityAuditor = new EvidenceQualityAuditor(),
        private readonly ReplacementCandidatePolicy $replacementPolicy = new ReplacementCandidatePolicy(),
        private readonly GuidanceConflictPolicy $conflictPolicy = new GuidanceConflictPolicy(),
    ) {
    }

    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @param list<string> $suppressedDecisionKeys
     */
    public function evaluate(
        array $findingsById,
        array $proposalsById,
        array $selectionEvents,
        array $outcomeEvents,
        array $suppressedDecisionKeys = [],
        ?string $projectRoot = null,
        int $reviewHorizonDays = 90,
        ?DateTimeImmutable $now = null,
    ): DreamRunResult {
        $now ??= new DateTimeImmutable('now');
        $evolution = $this->evolutionEvaluator->evaluate($findingsById, $proposalsById, $selectionEvents, $outcomeEvents);
        $decisions = $evolution->decisions;
        foreach ($this->replacementPolicy->evaluate($proposalsById, $findingsById, $outcomeEvents) as $decision) {
            $decisions[] = $decision;
        }
        foreach ($this->conflictPolicy->evaluate($findingsById, $proposalsById) as $decision) {
            $decisions[] = $decision;
        }
        $rawDecisionCount = count($decisions);
        $decisions = $this->uniqueDecisions($decisions);
        $duplicateDecisionCount = $rawDecisionCount - count($decisions);
        $suppressedLookup = array_fill_keys($suppressedDecisionKeys, true);
        $suppressed = [];
        $reviewable = [];
        foreach ($decisions as $decision) {
            if ($decision->type === EvolutionDecisionType::NO_ACTION) {
                continue;
            }
            if (isset($suppressedLookup[$decision->stableKey()])) {
                $suppressed[] = $decision;
                continue;
            }
            $reviewable[] = $decision;
        }

        $warnings = $this->evidenceQualityAuditor->audit($findingsById, $proposalsById, $selectionEvents, $outcomeEvents, $projectRoot, $reviewHorizonDays, $now);
        return new DreamRunResult(
            count($evolution->summaries),
            $warnings,
            $reviewable,
            $suppressed,
            $this->metrics($evolution->summaries, $proposalsById, $selectionEvents, $outcomeEvents, $reviewable, $suppressed, $duplicateDecisionCount, $findingsById, $now),
            'The run observes immutable histories and structurally valid repository state. It cannot prove that a human-reviewed candidate should be accepted.',
        );
    }

    /**
     * @param list<EvolutionDecision> $decisions
     * @return list<EvolutionDecision>
     */
    private function uniqueDecisions(array $decisions): array
    {
        $unique = [];
        foreach ($decisions as $decision) {
            $unique[$decision->stableKey()] = $decision;
        }
        ksort($unique);

        return array_values($unique);
    }

    /**
     * @param array<string, GuidanceUsageSummary> $summaries
     * @param array<string, Proposal> $proposalsById
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @param list<EvolutionDecision> $decisions
     * @param list<EvolutionDecision> $suppressed
     * @param array<string, Finding> $findingsById
     */
    private function metrics(array $summaries, array $proposalsById, array $selectionEvents, array $outcomeEvents, array $decisions, array $suppressed, int $duplicateDecisionCount, array $findingsById, DateTimeImmutable $now): DreamMetrics
    {
        $activeByTier = [];
        $candidateAges = [];
        $decisionDurations = [];
        foreach ($proposalsById as $proposal) {
            if (in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true) && $proposal->targetType !== null) {
                $activeByTier[$proposal->targetType] = ($activeByTier[$proposal->targetType] ?? 0) + 1;
            }
            if ($proposal->status === ProposalStatus::CANDIDATE) {
                $candidateAges[] = max(0, (int)$now->diff(new DateTimeImmutable($proposal->createdAt))->format('%a'));
            }
            $decisionAt = $proposal->approvedAt ?? $proposal->raw['rejected_at'] ?? $proposal->raw['retired_at'] ?? null;
            if (!is_string($decisionAt)) {
                continue;
            }
            $decisionTime = new DateTimeImmutable($decisionAt);
            foreach ($proposal->sourceFindings as $findingId) {
                if (!isset($findingsById[$findingId])) {
                    continue;
                }
                $seconds = $decisionTime->getTimestamp() - (new DateTimeImmutable($findingsById[$findingId]->createdAt))->getTimestamp();
                if ($seconds >= 0) {
                    $decisionDurations[] = intdiv($seconds, 3600);
                }
            }
        }
        ksort($activeByTier);
        rsort($candidateAges);
        sort($decisionDurations);
        $median = null;
        if ($decisionDurations !== []) {
            $middle = intdiv(count($decisionDurations), 2);
            $median = count($decisionDurations) % 2 === 1
                ? $decisionDurations[$middle]
                : intdiv($decisionDurations[$middle - 1] + $decisionDurations[$middle], 2);
        }
        $signalCounts = ['helpful' => 0, 'harmful' => 0, 'irrelevant' => 0, 'not_used' => 0, 'unknown' => 0];
        foreach ($summaries as $summary) {
            $signalCounts['helpful'] += $summary->helpfulCount;
            $signalCounts['harmful'] += $summary->harmfulCount;
            $signalCounts['irrelevant'] += $summary->irrelevantCount;
            $signalCounts['not_used'] += $summary->notUsedCount;
            $signalCounts['unknown'] += $summary->unknownCount;
        }
        $reviewableDecisionCount = count($decisions) + count($suppressed);
        $staleCount = count(array_filter(array_merge($decisions, $suppressed), static fn (EvolutionDecision $decision): bool => $decision->type === EvolutionDecisionType::STALE_CANDIDATE));

        $selectedIdentities = [];
        foreach ($selectionEvents as $event) {
            if ($event->selected) {
                $selectedIdentities[$event->compilationId . "\0" . $event->guidanceId] = true;
            }
        }
        $matchedOutcomeIdentities = [];
        foreach ($outcomeEvents as $event) {
            $identity = $event->compilationId . "\0" . $event->guidanceId;
            if (isset($selectedIdentities[$identity])) {
                $matchedOutcomeIdentities[$identity] = true;
            }
        }
        $selectedCount = count($selectedIdentities);
        $explicitOutcomeCount = count($matchedOutcomeIdentities);
        $candidateCount = count(array_filter($proposalsById, static fn (Proposal $proposal): bool => $proposal->status === ProposalStatus::CANDIDATE));

        return new DreamMetrics(
            $selectedCount,
            $explicitOutcomeCount,
            $selectedCount === 0 ? null : $explicitOutcomeCount / $selectedCount,
            $candidateCount,
            $candidateAges[0] ?? null,
            $staleCount,
            $reviewableDecisionCount === 0 ? 0.0 : $staleCount / $reviewableDecisionCount,
            count($suppressed),
            $duplicateDecisionCount,
            $reviewableDecisionCount,
            $median,
            $activeByTier,
            $signalCounts,
        );
    }
}
