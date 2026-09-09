<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

use voku\AgentLearning\LearningNoteProjection;

/** Bounded Learning-owned precedent projection for one exact persisted task id. */
final readonly class LearningTaskPrecedentResult
{
    /** @param list<LearningNoteProjection> $precedents */
    public function __construct(
        public string $taskId,
        public array $precedents,
        public LearningLineageResult $lineage,
        public bool $precedentsTruncated = false,
    ) {
    }

    /**
     * @return array{
     *   task_id: string,
     *   precedents: list<array<string, mixed>>,
     *   lineage: array<string, mixed>,
     *   precedents_truncated: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'precedents' => array_map(
                static fn (LearningNoteProjection $precedent): array => $precedent->toArray(),
                $this->precedents,
            ),
            'lineage' => $this->lineage->toArray(),
            'precedents_truncated' => $this->precedentsTruncated,
        ];
    }
}
