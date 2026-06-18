<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class GuidanceEvolutionResult
{
    /**
     * @param array<string, GuidanceUsageSummary> $summaries
     * @param list<EvolutionDecision> $decisions
     */
    public function __construct(
        public array $summaries,
        public array $decisions,
    ) {
    }
}
