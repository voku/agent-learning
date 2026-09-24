<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\GuidanceUsageSummary;
use voku\AgentLearning\Proposal;

final readonly class GuidanceProjection
{
    /**
     * @param list<string> $scope
     * @param list<string> $sourceFindingIds
     */
    public function __construct(
        public string $id,
        public GuidanceType $type,
        public string $status,
        public array $scope,
        public string $sourceProposalId,
        public array $sourceFindingIds,
        public ?string $canonicalTarget,
        public ?string $content,
        public GuidanceUsageProjection $usage,
        // When this guidance became durable: the applied_at its source proposal
        // carries. Null while the proposal is approved but not yet applied.
        public ?string $appliedAt = null,
    ) {
    }

    private static function appliedAt(Proposal $proposal): ?string
    {
        $value = $proposal->raw['applied_at'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public static function fromProposal(Proposal $proposal, ?GuidanceUsageSummary $usage): ?self
    {
        $type = $proposal->targetType === null ? null : GuidanceType::tryFrom($proposal->targetType);
        if ($type === null) {
            return null;
        }

        return new self(
            $proposal->id,
            $type,
            $proposal->status->value,
            $proposal->scope,
            $proposal->id,
            $proposal->sourceFindings,
            $proposal->target,
            $proposal->new,
            GuidanceUsageProjection::fromSummary($usage),
            self::appliedAt($proposal),
        );
    }
}
