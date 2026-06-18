<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Result indicating findings should be rejected.
 */
final readonly class RejectResult implements ConsolidationResult
{
    /**
     * @param list<string> $sourceFindings
     * @param list<string> $scope
     * @param list<string> $validation
     * @param list<string> $remainingUncertainty
     * @param array<string, bool|float|int|string|list<string>> $promotionGateEvidence
     */
    public function __construct(
        public array $sourceFindings,
        public string $reason,
        public string $targetType,
        public string $target,
        public array $scope,
        public ?string $old,
        public ?string $new,
        public ?string $boundary,
        public array $validation = [],
        public array $remainingUncertainty = [],
        public ?ConstraintSpecification $constraint = null,
        public array $promotionGateEvidence = [],
        public ?LearningClassification $learningDecision = null,
        public ?string $patternKey = null,
        public ?ValidationCase $validationCase = null,
    ) {
    }

    public function getAction(): Action
    {
        return Action::REJECT;
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
