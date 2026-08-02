<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class DreamMetrics
{
    /**
     * @param array<string, int> $activeGuidanceByTier
     * @param array<string, int> $outcomeSignals
     */
    public function __construct(
        public int $selectedGuidanceCount,
        public int $explicitOutcomeCount,
        public ?float $outcomeCompletenessRate,
        public int $candidateQueueCount,
        public ?int $oldestCandidateAgeDays,
        public int $staleCandidateCount,
        public float $staleCandidateRate,
        public int $suppressedDecisionCount,
        public int $duplicateDecisionCount,
        public int $reviewableDecisionCount,
        public ?int $medianFindingToDecisionHours,
        public array $activeGuidanceByTier,
        public array $outcomeSignals,
    ) {
    }
}
