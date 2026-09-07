<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

/** Learning-owned relation projection; storage relation IDs remain private. */
final readonly class LearningLineageRelation
{
    public function __construct(
        public string $sourceId,
        public string $kind,
        public string $targetId,
    ) {
    }

    /** @return array{source_id: string, kind: string, target_id: string} */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'kind' => $this->kind,
            'target_id' => $this->targetId,
        ];
    }
}
