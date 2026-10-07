<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * One backlog finding with the deterministic facts a reviewer needs before
 * choosing what it becomes. Facts only: no field recommends a bucket or an owner.
 */
final readonly class FindingReviewRow
{
    /**
     * @param list<string>               $allowedTransitions finding statuses the lifecycle accepts next
     * @param list<string>               $proposals          proposals citing this finding, as "id(status)"
     * @param list<string>               $signals            machine-readable facts, e.g. "no_proposal"
     * @param list<ProposalWordingMatch> $wordingMatches     overlap of the conclusion with the probe files
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public int $ageDays,
        public string $conclusionExcerpt,
        public array $allowedTransitions,
        public array $proposals,
        public array $signals,
        public array $wordingMatches,
    ) {
    }
}
