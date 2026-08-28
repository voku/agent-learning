<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class RecallSelectionEvent
{
    /**
     * @param list<string> $taskFiles
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $compilationId,
        public string $taskId,
        public string $guidanceId,
        public GuidanceType $guidanceType,
        public bool $eligible,
        public bool $selected,
        public ?string $selectionReason,
        public ?string $exclusionReason,
        public array $taskFiles,
        public string $recordedAt,
        public array $raw,
        public ?string $outcomeWithheldReason = null,
    ) {
    }
}
