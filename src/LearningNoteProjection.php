<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteProjection
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     * @param list<string> $sourceFindings
     * @param list<string> $sourceProposals
     */
    public function __construct(
        public string $id,
        public string $patternKey,
        public LearningNoteStatus $status,
        public array $scope,
        public array $tags,
        public array $sourceFindings,
        public array $sourceProposals,
        public ValidationCase $validationCase,
        public LearningNoteContent $content,
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
     *   source_proposals: list<string>,
     *   validation_case: array{given: string, when: string, then: string},
     *   content: array{
     *     title: string,
     *     context: string,
     *     guidance: string,
     *     why_it_works: string,
     *     when_to_apply: string,
     *     when_not_to_apply: string,
     *     verification: string,
     *     symptoms: ?string,
     *     failed_approaches: list<string>,
     *     root_cause: ?string,
     *     examples: list<string>
     *   },
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
            'source_proposals' => $this->sourceProposals,
            'validation_case' => $this->validationCase->toArray(),
            'content' => $this->content->toArray(),
            'digest' => $this->digest,
            'evidence_state' => $this->evidenceState->value,
        ];
    }
}
