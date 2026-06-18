<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Result indicating no durable learning is needed or already covered.
 */
final readonly class NoDurableLearningResult implements ConsolidationResult
{
    /**
     * @param list<string> $sourceFindings
     * @param list<string> $remainingUncertainty
     */
    public function __construct(
        public array $sourceFindings,
        public string $reason,
        public array $remainingUncertainty = [],
        public ?string $existingGuidanceId = null,
        public ?LearningClassification $learningDecision = null,
        public ?string $patternKey = null,
        public ?ValidationCase $validationCase = null,
    ) {
    }

    public function getAction(): Action
    {
        return Action::NO_DURABLE_LEARNING;
    }

    public function getSourceFindings(): array
    {
        return $this->sourceFindings;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRemainingUncertainty(): array
    {
        return $this->remainingUncertainty;
    }
}
