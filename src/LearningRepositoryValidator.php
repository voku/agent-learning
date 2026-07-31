<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class LearningRepositoryValidator
{
    public function __construct(
        private readonly FindingLifecycle $findingLifecycle = new FindingLifecycle(),
    ) {
    }

    public function validate(string $root, ?string $taskIdPattern = null): LearningRepositoryValidationResult
    {
        $findingsById = $this->validateFindings($root, $taskIdPattern);
        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);
        $this->validateConflictLineage($findingsById, $proposalsById, $root);
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);
        $outcomes = (new OutcomeRepository())->loadAll($root, $proposalsById);
        $recallSelectionEvents = (new RecallSelectionEventRepository())->load($root);
        $guidanceOutcomeEvents = (new GuidanceOutcomeEventRepository())->load($root);
        (new GuidanceUsageProjector())->project($recallSelectionEvents, $guidanceOutcomeEvents);

        return new LearningRepositoryValidationResult(
            $root,
            $findingsById,
            $proposalsById,
            $outcomes,
            $recallSelectionEvents,
            $guidanceOutcomeEvents,
        );
    }

    /** @return array<string, Finding> */
    public function validateFindings(string $root, ?string $taskIdPattern = null): array
    {
        $validator = $taskIdPattern === null ? new FindingValidator() : new FindingValidator(taskIdPattern: $taskIdPattern);
        $findingsById = [];
        foreach ($this->findingLifecycle->findingFiles($root) as $file) {
            $finding = $validator->validateFile($file);
            $this->findingLifecycle->assertPathMatchesStatus($finding, $file, $root);
            if (isset($findingsById[$finding->id])) {
                throw new ValidationException($file, null, $finding->id, 'duplicate finding ID');
            }
            $findingsById[$finding->id] = $finding;
        }

        return $findingsById;
    }

    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     */
    private function validateConflictLineage(array $findingsById, array $proposalsById, string $root): void
    {
        foreach ($findingsById as $finding) {
            foreach ($this->conflictReferences($finding->raw) as $reference) {
                if (!isset($findingsById[$reference])) {
                    throw new ValidationException($root, null, $finding->id, 'conflicts_with references unknown finding: ' . $reference);
                }
            }
        }
        foreach ($proposalsById as $proposal) {
            foreach ($this->conflictReferences($proposal->raw) as $reference) {
                if (!isset($proposalsById[$reference])) {
                    throw new ValidationException($root, null, $proposal->id, 'conflicts_with references unknown proposal: ' . $reference);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private function conflictReferences(array $raw): array
    {
        $references = $raw['conflicts_with'] ?? [];
        if (!is_array($references)) {
            return [];
        }

        return array_values(array_filter($references, 'is_string'));
    }
}
