<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteProjection
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     * @param list<string> $sourceFindings
     */
    public function __construct(
        public string $id,
        public string $patternKey,
        public LearningNoteStatus $status,
        public LearningNoteEvidenceState $evidenceState,
        public array $scope,
        public array $tags,
        public array $sourceFindings,
        public LearningNoteContent $content,
        public string $sourceDigest,
    ) {
    }
}
