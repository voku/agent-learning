<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

final readonly class TaskLearningProjection
{
    /**
     * @param list<FindingProjection> $findings
     * @param list<ProposalProjection> $proposals
     * @param list<GuidanceProjection> $guidance
     * @param list<string> $outcomeIds
     */
    public function __construct(
        public string $taskId,
        public array $findings,
        public array $proposals,
        public array $guidance,
        public array $outcomeIds,
    ) {
    }
}
