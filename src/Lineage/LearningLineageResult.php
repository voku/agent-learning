<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

final readonly class LearningLineageResult
{
    /**
     * @param list<string> $identityIds
     * @param array<int|string, int> $depthByIdentityId
     * @param list<LearningLineageRelation> $relations
     */
    public function __construct(
        public string $identityId,
        public array $identityIds,
        public array $depthByIdentityId,
        public array $relations,
        public int $maximumDepth,
        public int $maximumResults,
        public bool $truncated,
    ) {
    }

    /**
     * @return array{
     *   identity_id: string,
     *   identity_ids: list<string>,
     *   depth_by_identity_id: array<int|string, int>,
     *   identity_depths: list<array{identity_id: string, depth: int}>,
     *   relations: list<array{source_id: string, kind: string, target_id: string}>,
     *   maximum_depth: int,
     *   maximum_results: int,
     *   truncated: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'identity_id' => $this->identityId,
            'identity_ids' => $this->identityIds,
            'depth_by_identity_id' => $this->depthByIdentityId,
            'identity_depths' => $this->identityDepths(),
            'relations' => array_map(
                static fn (LearningLineageRelation $relation): array => $relation->toArray(),
                $this->relations,
            ),
            'maximum_depth' => $this->maximumDepth,
            'maximum_results' => $this->maximumResults,
            'truncated' => $this->truncated,
        ];
    }

    /** @return list<array{identity_id: string, depth: int}> */
    private function identityDepths(): array
    {
        $result = [];
        foreach ($this->depthByIdentityId as $identityId => $depth) {
            $result[] = [
                'identity_id' => (string) $identityId,
                'depth' => $depth,
            ];
        }

        return $result;
    }
}
