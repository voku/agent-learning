<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class GuidanceOutcomeEvent
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $compilationId,
        public string $taskId,
        public string $guidanceId,
        public OutcomeValue $outcome,
        public bool $applied,
        public ?string $comment,
        public string $commit,
        public string $recordedBy,
        public string $recordedAt,
        public array $raw,
    ) {
    }
}
