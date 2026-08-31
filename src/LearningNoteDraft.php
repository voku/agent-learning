<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteDraft
{
    /**
     * @param list<string> $sourceFindings
     * @param list<string> $sourceProposals
     * @param list<string> $tags
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
     */
    public function __construct(
        public array $sourceFindings,
        public array $sourceProposals,
        public array $tags,
        public array $repositoryEvidence,
        public LearningNoteContent $content,
        public ?string $id = null,
    ) {
        if ($sourceFindings === []) {
            throw new ValidationException('learning-note-draft', null, null, 'sourceFindings must not be empty');
        }
    }
}
