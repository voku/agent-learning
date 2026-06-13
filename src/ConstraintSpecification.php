<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class ConstraintSpecification
{
    /**
     * @param non-empty-string       $ruleId
     * @param list<non-empty-string> $scope
     * @param list<non-empty-string> $allowedBoundaries
     * @param list<non-empty-string> $validationCommands
     * @param list<non-empty-string> $exampleRulePaths
     * @param non-empty-string       $ruleClassName
     * @param non-empty-string       $targetRulePath
     * @param list<non-empty-string> $registrationFiles
     */
    public function __construct(
        public string $ruleId,
        public ConstraintEngine $engine,
        public string $ruleClassName,
        public string $targetRulePath,
        public array $registrationFiles,
        public array $scope,
        public string $violation,
        public array $allowedBoundaries,
        public Detectability $detectability,
        public FalsePositiveRisk $falsePositiveRisk,
        public array $validationCommands,
        public array $exampleRulePaths,
    ) {
    }

    /**
     * @return array{
     *     rule_id: non-empty-string,
     *     engine: string,
     *     rule_class_name: non-empty-string,
     *     target_rule_path: non-empty-string,
     *     registration_files: list<non-empty-string>,
     *     scope: list<non-empty-string>,
     *     violation: string,
     *     allowed_boundaries: list<non-empty-string>,
     *     detectability: string,
     *     false_positive_risk: string,
     *     validation_commands: list<non-empty-string>,
     *     example_rule_paths: list<non-empty-string>
     * }
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'engine' => $this->engine->value,
            'rule_class_name' => $this->ruleClassName,
            'target_rule_path' => $this->targetRulePath,
            'registration_files' => $this->registrationFiles,
            'scope' => $this->scope,
            'violation' => $this->violation,
            'allowed_boundaries' => $this->allowedBoundaries,
            'detectability' => $this->detectability->value,
            'false_positive_risk' => $this->falsePositiveRisk->value,
            'validation_commands' => $this->validationCommands,
            'example_rule_paths' => $this->exampleRulePaths,
        ];
    }
}
