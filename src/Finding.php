<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class Finding
{
    /**
     * @param list<string>               $scope
     * @param list<array<string, mixed>> $evidence
     * @param array<string, mixed>       $raw
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public string $session,
        public string $createdAt,
        public string $createdBy,
        public array $scope,
        public string $observation,
        public array $evidence,
        public string $hypothesis,
        public ?string $validatedConclusion,
        public string $confidence,
        public string $validationStatus,
        public FindingStatus $status,
        public string $sensitivity,
        public array $raw,
        public ?LearningClassification $classification = null,
        public ?string $patternKey = null,
        public ?ValidationCase $validationCase = null,
        public ?string $targetPackage = null,
        public ?string $testedVersion = null,
        public ?string $testedCommit = null,
    ) {
    }
}
