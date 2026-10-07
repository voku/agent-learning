<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * One pending proposal with the deterministic facts a reviewer needs before
 * choosing a transition. Facts only: no field recommends approving, rejecting
 * or acknowledging, because that judgement stays with a named human.
 */
final readonly class ProposalReviewRow
{
    /**
     * @param list<string>                       $allowedTransitions transition commands the lifecycle accepts for this status/action
     * @param list<string>                       $signals            machine-readable facts, e.g. "corrected_by:proposal.x"
     * @param list<ProposalWordingMatch>         $wordingMatches     overlap of the proposed wording with the target and probe files
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $action,
        public ?string $targetType,
        public ?string $target,
        public int $ageDays,
        public int $sourceFindingCount,
        public string $reasonExcerpt,
        public array $allowedTransitions,
        public array $signals,
        public array $wordingMatches,
    ) {
    }
}
