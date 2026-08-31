<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNote
{
    /**
     * @param list<string>                         $scope
     * @param list<string>                         $tags
     * @param list<string>                         $sourceFindings
     * @param list<string>                         $sourceProposals
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
     */
    public function __construct(
        public string $id,
        public string $schemaVersion,
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
        public ?string $retiredAt = null,
        public ?string $retiredReason = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->schemaVersion,
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
        ];
        if ($this->retiredAt !== null) {
            $data['retired_at'] = $this->retiredAt;
        }
        if ($this->retiredReason !== null) {
            $data['retired_reason'] = $this->retiredReason;
        }

        return $data;
    }
}
