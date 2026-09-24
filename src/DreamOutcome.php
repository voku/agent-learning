<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * One completed Dream run: the evaluation, the history projection it was read
 * against, and the candidate Proposal ids it wrote (none for a read-only run).
 */
final readonly class DreamOutcome
{
    /**
     * @param list<string> $writtenCandidateIds
     */
    public function __construct(
        public DreamRunResult $result,
        public HistoryProjection $projection,
        public int $projectionRuntimeMilliseconds,
        public array $writtenCandidateIds,
        public bool $candidatesWritten,
    ) {
    }
}
