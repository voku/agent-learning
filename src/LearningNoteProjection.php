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
        public array $scope,
        public array $tags,
        public array $sourceFindings,
        public string $title,
        public string $guidance,
        public string $digest,
        public LearningNoteEvidenceState $evidenceState,
    ) {
    }

    /**
     * @return array{
     *   id: string,
     *   pattern_key: string,
     *   status: string,
     *   scope: list<string>,
     *   tags: list<string>,
     *   source_findings: list<string>,
     *   title: string,
     *   guidance: string,
     *   digest: string,
     *   evidence_state: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pattern_key' => $this->patternKey,
            'status' => $this->status->value,
            'scope' => $this->scope,
            'tags' => $this->tags,
            'source_findings' => $this->sourceFindings,
            'title' => $this->title,
            'guidance' => $this->guidance,
            'digest' => $this->digest,
            'evidence_state' => $this->evidenceState->value,
        ];
    }
}
