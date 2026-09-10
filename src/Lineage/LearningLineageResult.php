<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

use LogicException;

final readonly class LearningLineageResult
{
    /**
     * @param list<string> $identityIds
     * @param array<array-key, int> $depthByIdentityId Legacy associative projection; numeric-looking string ids are coerced by PHP array-key semantics.
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

    /** @return list<array{identity_id: string, depth: int}> */
    public function identityDepths(): array
    {
        $result = [];
        foreach ([$this->identityId, ...$this->identityIds] as $identityId) {
            if (!array_key_exists($identityId, $this->depthByIdentityId)) {
                throw new LogicException('Learning lineage depth projection is missing identity: ' . $identityId);
            }
            $result[] = [
                'identity_id' => $identityId,
                'depth' => $this->depthByIdentityId[$identityId],
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   identity_id: string,
     *   identity_ids: list<string>,
     *   depth_by_identity_id: array<array-key, int>,
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
}
