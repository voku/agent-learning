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
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);
        $outcomes = (new OutcomeRepository())->loadAll($root, $proposalsById);

        return new LearningRepositoryValidationResult($root, $findingsById, $proposalsById, $outcomes);
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
}
