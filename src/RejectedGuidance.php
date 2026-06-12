<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Metadata and proposal details for a rejected guidance proposal.
 */
final readonly class RejectedGuidance
{
    /**
     * @param non-empty-string $id Rejection record ID (e.g. rejection.2026-06-08.002)
     * @param Proposal         $proposal The original rejected proposal
     * @param string           $rejectionReason The reason why this proposal was rejected
     */
    public function __construct(
        public string $id,
        public Proposal $proposal,
        public string $rejectionReason,
    ) {
    }
}
