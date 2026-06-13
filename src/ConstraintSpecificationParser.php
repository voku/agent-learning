<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintSpecificationParser
{
    public function __construct(
        private readonly RecordAccess $recordAccess = new RecordAccess(),
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function parse(array $record, string $file, ?int $line = null, ?string $recordId = null): ConstraintSpecification
    {
        $ruleId = $this->nonEmptyString($record, 'rule_id', $file, $line, $recordId);

        $engineValue = $this->recordAccess->string($record, 'engine', $file, $line, $recordId);
        $engine = ConstraintEngine::tryFrom($engineValue);
        if ($engine === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported constraint engine: ' . $engineValue);
        }

        $detectabilityValue = $this->recordAccess->string($record, 'detectability', $file, $line, $recordId);
        $detectability = Detectability::tryFrom($detectabilityValue);
        if ($detectability === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported detectability: ' . $detectabilityValue);
        }

        $falsePositiveRiskValue = $this->recordAccess->string($record, 'false_positive_risk', $file, $line, $recordId);
        $falsePositiveRisk = FalsePositiveRisk::tryFrom($falsePositiveRiskValue);
        if ($falsePositiveRisk === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported false_positive_risk: ' . $falsePositiveRiskValue);
        }

        return new ConstraintSpecification(
            $ruleId,
            $engine,
            $this->nonEmptyString($record, 'rule_class_name', $file, $line, $recordId),
            $this->nonEmptyString($record, 'target_rule_path', $file, $line, $recordId),
            $this->nonEmptyStringList($record, 'registration_files', $file, $line, $recordId),
            $this->nonEmptyStringList($record, 'scope', $file, $line, $recordId),
            $this->nonEmptyString($record, 'violation', $file, $line, $recordId),
            $this->stringListWithNonEmptyItems($record, 'allowed_boundaries', $file, $line, $recordId),
            $detectability,
            $falsePositiveRisk,
            $this->nonEmptyStringList($record, 'validation_commands', $file, $line, $recordId),
            $this->stringListWithNonEmptyItems($record, 'example_rule_paths', $file, $line, $recordId),
        );
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return non-empty-string
     */
    private function nonEmptyString(array $record, string $field, string $file, ?int $line, ?string $recordId): string
    {
        $value = $this->recordAccess->string($record, $field, $file, $line, $recordId);
        if (trim($value) === '') {
            throw new ValidationException($file, $line, $recordId, 'empty constraint field: ' . $field);
        }

        /** @var non-empty-string $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<non-empty-string>
     */
    private function nonEmptyStringList(array $record, string $field, string $file, ?int $line, ?string $recordId): array
    {
        $items = $this->stringListWithNonEmptyItems($record, $field, $file, $line, $recordId);
        if ($items === []) {
            throw new ValidationException($file, $line, $recordId, 'empty constraint list field: ' . $field);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<non-empty-string>
     */
    private function stringListWithNonEmptyItems(array $record, string $field, string $file, ?int $line, ?string $recordId): array
    {
        $items = $this->recordAccess->stringList($record, $field, $file, $line, $recordId);
        foreach ($items as $item) {
            if (trim($item) === '') {
                throw new ValidationException($file, $line, $recordId, 'constraint list contains empty string: ' . $field);
            }
        }

        /** @var list<non-empty-string> $items */
        return $items;
    }
}
