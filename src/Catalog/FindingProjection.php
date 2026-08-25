<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

use voku\AgentLearning\Finding;

final readonly class FindingProjection
{
    /**
     * @param list<string> $scope
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $proposalIds
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $taskId,
        public string $session,
        public string $createdAt,
        public string $observation,
        public ?string $validatedConclusion,
        public array $scope,
        public array $evidence,
        public array $proposalIds,
    ) {
    }

    /** @param list<string> $proposalIds */
    public static function fromFinding(Finding $finding, array $proposalIds): self
    {
        sort($proposalIds, SORT_STRING);

        return new self(
            $finding->id,
            $finding->status->value,
            $finding->taskId,
            $finding->session,
            $finding->createdAt,
            $finding->observation,
            $finding->validatedConclusion,
            $finding->scope,
            $finding->evidence,
            $proposalIds,
        );
    }
}
