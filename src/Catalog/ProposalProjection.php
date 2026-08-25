<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

use voku\AgentLearning\Proposal;

final readonly class ProposalProjection
{
    /**
     * @param list<string> $scope
     * @param list<string> $sourceFindingIds
     * @param list<string> $sourceTaskIds
     * @param list<string> $validation
     * @param list<string> $supersedesProposalIds
     * @param list<string> $conflictsWithProposalIds
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $createdAt,
        public string $action,
        public ?string $targetType,
        public ?string $target,
        public array $scope,
        public array $sourceFindingIds,
        public array $sourceTaskIds,
        public ?string $proposedChange,
        public string $reason,
        public ?string $boundary,
        public array $validation,
        public string $proposedBy,
        public ?string $approvedBy,
        public ?string $approvedAt,
        public array $supersedesProposalIds,
        public array $conflictsWithProposalIds,
        public ?string $correctsProposalId,
    ) {
    }

    /** @param list<string> $sourceTaskIds */
    public static function fromProposal(Proposal $proposal, array $sourceTaskIds): self
    {
        sort($sourceTaskIds, SORT_STRING);

        $supersedes = [];
        $rawSupersedes = $proposal->raw['supersedes_proposal_id'] ?? null;
        if (is_string($rawSupersedes) && $rawSupersedes !== '') {
            $supersedes[] = $rawSupersedes;
        }
        sort($supersedes, SORT_STRING);

        $conflicts = [];
        $rawConflicts = $proposal->raw['conflicts_with'] ?? [];
        if (is_array($rawConflicts)) {
            foreach ($rawConflicts as $reference) {
                if (is_string($reference) && $reference !== '') {
                    $conflicts[] = $reference;
                }
            }
        }
        $conflicts = array_values(array_unique($conflicts));
        sort($conflicts, SORT_STRING);

        $corrects = $proposal->raw['corrects_proposal_id'] ?? null;

        return new self(
            $proposal->id,
            $proposal->status->value,
            $proposal->createdAt,
            $proposal->action->value,
            $proposal->targetType,
            $proposal->target,
            $proposal->scope,
            $proposal->sourceFindings,
            $sourceTaskIds,
            $proposal->new,
            $proposal->reason,
            $proposal->boundary,
            $proposal->validation,
            $proposal->proposedBy,
            $proposal->approvedBy,
            $proposal->approvedAt,
            $supersedes,
            $conflicts,
            is_string($corrects) && $corrects !== '' ? $corrects : null,
        );
    }
}
