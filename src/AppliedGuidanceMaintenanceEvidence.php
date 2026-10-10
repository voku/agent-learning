<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Owner-validated physical proof maintenance only.
 *
 * A reanchor actor is recorded attribution, NOT verified human approval.
 * No claim about semantic equivalence of unrelated target text is made.
 */
final readonly class AppliedGuidanceMaintenanceEvidence
{
    /**
     * @param non-empty-list<non-empty-string> $proposalIds
     * @param non-empty-array<non-empty-string, array{actor: non-empty-string, at: non-empty-string, reason: non-empty-string}> $reanchors
     */
    public function __construct(
        public string $targetSourceRef,
        public string $beforeSha256,
        public string $afterSha256,
        public array $proposalIds,
        public array $reanchors,
    ) {
    }
}
