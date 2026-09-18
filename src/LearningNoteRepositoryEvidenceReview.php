<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteRepositoryEvidenceReview
{
    public function __construct(
        public string $sourceRef,
        public string $recordedSha256,
        public ?string $currentSha256,
        public LearningNoteEvidenceState $state,
    ) {
    }

    /**
     * @return array{
     *   source_ref: string,
     *   recorded_sha256: string,
     *   current_sha256: ?string,
     *   state: string
     * }
     */
    public function toArray(): array
    {
        return [
            'source_ref' => $this->sourceRef,
            'recorded_sha256' => $this->recordedSha256,
            'current_sha256' => $this->currentSha256,
            'state' => $this->state->value,
        ];
    }
}
