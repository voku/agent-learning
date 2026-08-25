<?php

declare(strict_types=1);

namespace voku\AgentLearning\Catalog;

final readonly class LearningOverview
{
    /**
     * @param array<string, int> $findingCounts
     * @param array<string, int> $proposalCounts
     * @param array<string, int> $guidanceCounts
     * @param list<string> $findingAttentionIds
     * @param list<string> $proposalAttentionIds
     * @param list<string> $recentDurableGuidanceIds
     * @param list<string> $recentFindingIds
     * @param list<string> $recentProposalIds
     */
    public function __construct(
        public array $findingCounts,
        public array $proposalCounts,
        public array $guidanceCounts,
        public array $findingAttentionIds,
        public array $proposalAttentionIds,
        public array $recentDurableGuidanceIds,
        public array $recentFindingIds = [],
        public array $recentProposalIds = [],
    ) {
    }
}
