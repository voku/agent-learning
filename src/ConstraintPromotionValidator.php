<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintPromotionValidator
{
    /**
     * @param array<string, Finding> $findingsById
     */
    public function validate(Proposal $proposal, string $file, ?int $line, array $findingsById): void
    {
        if ($proposal->targetType !== GuidanceType::CONSTRAINT->value) {
            return;
        }

        $constraint = $proposal->constraint;
        if (!$constraint instanceof ConstraintSpecification) {
            throw new ValidationException($file, $line, $proposal->id, 'constraint proposal requires constraint specification');
        }

        if ($findingsById === []) {
            throw new ValidationException($file, $line, $proposal->id, 'constraint proposal requires source finding records');
        }

        if ($proposal->action !== Action::ADD) {
            throw new ValidationException($file, $line, $proposal->id, 'constraint proposal must add a constraint');
        }

        if (count(array_unique($proposal->sourceFindings)) < 2 && !$this->hasCriticalIncidentJustification($proposal)) {
            throw new ValidationException($file, $line, $proposal->id, 'constraint proposal requires several source findings or critical incident justification');
        }

        if ($constraint->falsePositiveRisk === FalsePositiveRisk::HIGH && !$this->hasFalsePositiveRiskJustification($proposal)) {
            throw new ValidationException($file, $line, $proposal->id, 'high false-positive risk requires justification');
        }

        if ($constraint->engine === ConstraintEngine::PHPSTAN && !$this->hasCommandContaining($constraint->validationCommands, 'phpstan')) {
            throw new ValidationException($file, $line, $proposal->id, 'phpstan constraint requires a phpstan validation command');
        }

        if ($constraint->engine === ConstraintEngine::PHPSTAN && !str_contains($constraint->targetRulePath, '/PHPStan/')) {
            throw new ValidationException($file, $line, $proposal->id, 'phpstan constraint target rule path must point to a PHPStan rule location');
        }

        if ($constraint->engine === ConstraintEngine::PHPCS && !$this->hasCommandContaining($constraint->validationCommands, 'codesniffer') && !$this->hasCommandContaining($constraint->validationCommands, 'phpcs')) {
            throw new ValidationException($file, $line, $proposal->id, 'phpcs constraint requires a phpcs validation command');
        }

        if ($constraint->engine === ConstraintEngine::PHPCS && !str_contains($constraint->targetRulePath, '/Sniffs/')) {
            throw new ValidationException($file, $line, $proposal->id, 'phpcs constraint target rule path must point to a Sniffs location');
        }

        if ($constraint->engine === ConstraintEngine::PHP_CS_FIXER && !$this->hasCommandContaining($constraint->validationCommands, 'php-cs-fixer')) {
            throw new ValidationException($file, $line, $proposal->id, 'php_cs_fixer constraint requires a php-cs-fixer validation command');
        }

        if ($constraint->engine === ConstraintEngine::PHP_CS_FIXER && !str_contains($constraint->targetRulePath, '/fixer/')) {
            throw new ValidationException($file, $line, $proposal->id, 'php_cs_fixer constraint target rule path must point to a fixer location');
        }
    }

    private function hasCriticalIncidentJustification(Proposal $proposal): bool
    {
        if (($proposal->raw['critical_incident'] ?? null) === true) {
            return true;
        }

        return $this->hasNonEmptyRawString($proposal, 'critical_incident_justification');
    }

    private function hasFalsePositiveRiskJustification(Proposal $proposal): bool
    {
        return $this->hasNonEmptyRawString($proposal, 'false_positive_risk_justification');
    }

    private function hasNonEmptyRawString(Proposal $proposal, string $field): bool
    {
        $value = $proposal->raw[$field] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return true;
        }

        $constraint = $proposal->raw['constraint'] ?? null;
        if (is_array($constraint)) {
            $constraintValue = $constraint[$field] ?? null;

            return is_string($constraintValue) && trim($constraintValue) !== '';
        }

        return false;
    }

    /**
     * @param list<non-empty-string> $commands
     */
    private function hasCommandContaining(array $commands, string $needle): bool
    {
        foreach ($commands as $command) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }
}
