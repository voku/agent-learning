<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Read-only owner evidence of applied-guidance proof maintenance across two snapshots.
 *
 * This is NOT a verdict on semantic equivalence of other text in the target,
 * human review, Git identity, or the authenticity of the recorded actor.
 */
final readonly class AppliedGuidanceMaintenanceProof
{
    /**
     * @param non-empty-string $targetSourceRef
     * @param non-empty-list<array{proposal_id: non-empty-string, reanchored_by: non-empty-string, reanchored_at: non-empty-string, reason: non-empty-string}> $reanchors
     */
    public function __construct(
        public string $targetSourceRef,
        public string $beforeSha256,
        public string $afterSha256,
        public array $reanchors,
    ) {
    }
}
