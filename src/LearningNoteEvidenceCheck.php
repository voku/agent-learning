<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteEvidenceCheck
{
    public function __construct(
        public string $sourceRef,
        public string $expectedSha256,
        public ?string $actualSha256,
        public LearningNoteEvidenceState $state,
    ) {
    }

    /** @return array{source_ref: string, expected_sha256: string, actual_sha256: ?string, state: string} */
    public function toArray(): array
    {
        return [
            'source_ref' => $this->sourceRef,
            'expected_sha256' => $this->expectedSha256,
            'actual_sha256' => $this->actualSha256,
            'state' => $this->state->value,
        ];
    }
}
