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
        public int $candidateQueueCount,
        public ?int $oldestCandidateAgeDays,
        public int $staleCandidateCount,
        public int $suppressedDecisionCount,
        public int $duplicateDecisionCount,
        public ?int $medianFindingToDecisionHours,
        public array $activeGuidanceByTier,
        public array $outcomeSignals,
    ) {
    }
}
