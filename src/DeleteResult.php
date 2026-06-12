<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Result indicating an existing durable guidance should be deleted.
 */
final readonly class DeleteResult implements ConsolidationResult
{
    /**
     * @param list<string> $sourceFindings
     * @param list<string> $scope
     * @param list<string> $validation
     * @param list<string> $remainingUncertainty
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
    ) {
    }

    public function getAction(): Action
    {
        return Action::DELETE;
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
