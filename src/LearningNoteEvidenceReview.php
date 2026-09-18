<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteEvidenceReview
{
    /** @param list<LearningNoteRepositoryEvidenceReview> $repositoryEvidence */
    public function __construct(
        public string $noteId,
        public LearningNoteEvidenceState $evidenceState,
        public array $repositoryEvidence,
    ) {
    }

    /**
     * @return array{
     *   note_id: string,
     *   evidence_state: string,
     *   repository_evidence: list<array{
     *     source_ref: string,
     *     recorded_sha256: string,
     *     current_sha256: ?string,
     *     state: string
     *   }>
     * }
     */
    public function toArray(): array
    {
        return [
            'note_id' => $this->noteId,
            'evidence_state' => $this->evidenceState->value,
            'repository_evidence' => array_map(
                static fn (LearningNoteRepositoryEvidenceReview $evidence): array => $evidence->toArray(),
                $this->repositoryEvidence,
            ),
        ];
    }
}
