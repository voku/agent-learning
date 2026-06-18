<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class StalenessThresholds
{
    public function __construct(
        public int $eligibleSessions = 3,
        public int $negativeSignals = 2,
    ) {
    }
}
