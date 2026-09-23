<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class GuidanceUsageSummary
{
    /**
     * @param list<string> $distinctTaskIds
     * @param list<string> $evidenceEventIds
     * @param list<string> $attributableHelpfulEventIds helpful outcome events whose decision-time attribution
     *                                                  says the guidance was read first and nothing else
     *                                                  prescribed the decision; the audit set for causal value
     */
    public function __construct(
        public string $guidanceId,
        public GuidanceType $guidanceType,
        public int $eligibleCount,
        public int $selectedCount,
        public int $appliedCount,
        public int $helpfulCount,
        public int $irrelevantCount,
        public int $harmfulCount,
        public int $notUsedCount,
        public int $unknownCount,
        public int $validationSuccessCount,
        public int $violationDetectedCount,
        public int $distinctTaskCount,
        public ?string $lastEligibleAt,
        public ?string $lastSelectedAt,
        public ?string $lastHelpfulAt,
        public array $distinctTaskIds,
        public array $evidenceEventIds,
        public array $attributableHelpfulEventIds = [],
    ) {
    }
}
