<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class PromotionThresholds
{
    public function __construct(
        public int $findingToMemoryValidatedFindings = 2,
        public int $memoryToSkillSelectedSessions = 3,
        public int $memoryToSkillHelpfulSessions = 2,
        public int $skillToConstraintSelectedSessions = 3,
        public int $skillToConstraintAppliedSessions = 2,
    ) {
    }
}
