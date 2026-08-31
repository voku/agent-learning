<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNotePreparation
{
    /**
     * @param list<array{
     *   id: string,
     *   task_id: string,
     *   scope: list<string>,
     *   observation: string,
     *   hypothesis: string,
     *   validated_conclusion: string,
     *   evidence: list<array<string, mixed>>
     * }> $findings
     * @param list<string> $scope
     * @param list<array{id: string, pattern_key: string, scope_overlap: list<string>, tag_overlap: list<string>}> $overlapCandidates
     */
    public function __construct(
        public string $patternKey,
        public ValidationCase $validationCase,
        public array $scope,
        public array $findings,
        public ?LearningNoteProjection $existingNote,
        public array $overlapCandidates,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'pattern_key' => $this->patternKey,
            'validation_case' => $this->validationCase->toArray(),
            'scope' => $this->scope,
            'findings' => $this->findings,
            'existing_note' => $this->existingNote?->toArray(),
            'overlap_candidates' => $this->overlapCandidates,
        ];
    }
}
