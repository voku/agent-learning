<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNote
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     * @param list<string> $sourceFindings
     * @param list<string> $sourceProposals
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
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
        public array $repositoryEvidence,
        public LearningNoteContent $content,
        public string $createdAt,
        public string $updatedAt,
        public ?string $retiredReason = null,
    ) {
    }

    /**
     * @return array{
     *   schema_version: '1.0',
     *   id: string,
     *   pattern_key: string,
     *   status: string,
     *   scope: list<string>,
     *   tags: list<string>,
     *   source_findings: list<string>,
     *   source_proposals: list<string>,
     *   validation_case: array{given: string, when: string, then: string},
     *   repository_evidence: list<array{source_ref: string, sha256: string}>,
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
     *   created_at: string,
     *   updated_at: string,
     *   retired_reason: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $this->id,
            'pattern_key' => $this->patternKey,
            'status' => $this->status->value,
            'scope' => $this->scope,
            'tags' => $this->tags,
            'source_findings' => $this->sourceFindings,
            'source_proposals' => $this->sourceProposals,
            'validation_case' => $this->validationCase->toArray(),
            'repository_evidence' => array_map(
                static fn (LearningNoteRepositoryEvidence $evidence): array => $evidence->toArray(),
                $this->repositoryEvidence,
            ),
            'content' => $this->content->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'retired_reason' => $this->retiredReason,
        ];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
