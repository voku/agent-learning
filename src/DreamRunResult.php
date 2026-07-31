<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class DreamRunResult
{
    /**
     * @param list<DreamWarning> $warnings
     * @param list<EvolutionDecision> $decisions
     * @param list<EvolutionDecision> $suppressedDecisions
     */
    public function __construct(
        public int $evaluatedGuidanceCount,
        public array $warnings,
        public array $decisions,
        public array $suppressedDecisions,
        public DreamMetrics $metrics,
        public string $remainingUncertainty,
    ) {
    }
}
