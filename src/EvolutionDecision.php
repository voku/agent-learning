<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class EvolutionDecision
{
    /**
     * @param list<string> $evidenceEventIds
     * @param list<string> $independentTaskIds
     * @param list<string> $proposedScope
     * @param list<string> $validationRequirements
     * @param list<string> $sourceFindings
     * @param array<string, mixed> $proposalExtras
     */
    public function __construct(
        public EvolutionDecisionType $type,
        public string $guidanceId,
        public GuidanceType $sourceTier,
        public ?GuidanceType $targetTier,
        public array $evidenceEventIds,
        public array $independentTaskIds,
        public string $reason,
        public string $remainingUncertainty,
        public array $proposedScope,
        public array $validationRequirements,
        public array $sourceFindings = [],
        public ?Action $proposalAction = null,
        public ?string $oldText = null,
        public ?string $newText = null,
        public array $proposalExtras = [],
    ) {
    }
}
