<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteStatusReport
{
    /** @param list<LearningNoteEvidenceCheck> $checks */
    public function __construct(
        public string $noteId,
        public LearningNoteEvidenceState $state,
        public array $checks,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'note_id' => $this->noteId,
            'state' => $this->state->value,
            'checks' => array_map(static fn (LearningNoteEvidenceCheck $check): array => $check->toArray(), $this->checks),
        ];
    }
}
