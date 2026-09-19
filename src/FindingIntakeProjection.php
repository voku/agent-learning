<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class FindingIntakeProjection
{
    /**
     * @param list<array{id: non-empty-string, requires: list<non-empty-string>}> $availableActions
     */
    public function __construct(
        public FindingStatus $status,
        public string $validationStatus,
        public ?LearningClassification $classification,
        public array $availableActions,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     validation_status: string,
     *     classification: ?string,
     *     available_actions: list<array{id: non-empty-string, requires: list<non-empty-string>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'validation_status' => $this->validationStatus,
            'classification' => $this->classification?->value,
            'available_actions' => $this->availableActions,
        ];
    }
}
