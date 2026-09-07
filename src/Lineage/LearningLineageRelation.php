<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

final readonly class LearningLineageRelation
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $kind,
        public string $targetId,
    ) {
    }

    /** @return array{id: string, source_id: string, kind: string, target_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->sourceId,
            'kind' => $this->kind,
            'target_id' => $this->targetId,
        ];
    }
}
