<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNotePreparation
{
    /**
     * @param list<Finding>                        $findings
     * @param list<string>                         $scope
     * @param list<string>                         $tags
     * @param list<string>                         $relatedProposalIds
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
     */
    public function __construct(
        public string $patternKey,
        public ValidationCase $validationCase,
        public array $findings,
        public array $scope,
        public array $tags,
        public array $relatedProposalIds,
        public array $repositoryEvidence,
        public ?LearningNoteProjection $existingNote,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pattern_key' => $this->patternKey,
            'validation_case' => $this->validationCase->toArray(),
            'scope' => $this->scope,
            'tags' => $this->tags,
            'source_findings' => array_map(static fn (Finding $finding): array => $finding->raw, $this->findings),
            'related_proposal_ids' => $this->relatedProposalIds,
            'repository_evidence' => array_map(
                static fn (LearningNoteRepositoryEvidence $evidence): array => $evidence->toArray(),
                $this->repositoryEvidence,
            ),
            'existing_note' => $this->existingNote === null ? null : [
                'id' => $this->existingNote->id,
                'pattern_key' => $this->existingNote->patternKey,
                'status' => $this->existingNote->status->value,
                'scope' => $this->existingNote->scope,
                'tags' => $this->existingNote->tags,
                'source_findings' => $this->existingNote->sourceFindings,
                'content' => $this->existingNote->content->toArray(),
                'source_digest' => $this->existingNote->sourceDigest,
            ],
        ];
    }
}
