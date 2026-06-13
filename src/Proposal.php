<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class Proposal
{
    /**
     * @param list<string>         $scope
     * @param list<string>         $sourceFindings
     * @param list<string>         $validation
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $createdAt,
        public Action $action,
        public ?string $targetType,
        public ?string $target,
        public array $scope,
        public array $sourceFindings,
        public ?string $old,
        public ?string $new,
        public string $reason,
        public ?string $boundary,
        public array $validation,
        public ProposalStatus $status,
        public string $proposedBy,
        public ?string $approvedBy,
        public ?string $approvedAt,
        public array $raw,
        public ?ConstraintSpecification $constraint = null,
    ) {
    }
}
