<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

use voku\AgentLearning\GuidanceUsageSummary;

final readonly class GuidanceUsageProjection
{
    /** @param list<string> $taskIds */
    public function __construct(
        public int $eligible,
        public int $selected,
        public int $applied,
        public int $helpful,
        public int $irrelevant,
        public int $harmful,
        public int $notUsed,
        public int $unknown,
        public int $validationSuccess,
        public int $violationDetected,
        public array $taskIds,
    ) {
    }

    public static function fromSummary(?GuidanceUsageSummary $summary): self
    {
        if ($summary === null) {
            return new self(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, []);
        }

        return new self(
            $summary->eligibleCount,
            $summary->selectedCount,
            $summary->appliedCount,
            $summary->helpfulCount,
            $summary->irrelevantCount,
            $summary->harmfulCount,
            $summary->notUsedCount,
            $summary->unknownCount,
            $summary->validationSuccessCount,
            $summary->violationDetectedCount,
            $summary->distinctTaskIds,
        );
    }
}
