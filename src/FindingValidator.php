<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final class FindingValidator
{
    /**
     * @var list<string>
     */
    private const array CONFIDENCE = ['low', 'medium', 'high'];

    /**
     * @var list<string>
     */
    private const array VALIDATION_STATUS = ['unverified', 'validated', 'invalidated'];

    private const string TARGET_PACKAGE_PATTERN = '/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/';

    public function __construct(
        private readonly FindingParser $parser = new FindingParser(),
        private readonly EvidenceValidator $evidenceValidator = new EvidenceValidator(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
        // Three accepted shapes: a ticket key (ITPNG-426), an explicit TODO
        // reference (TODO@agent-loop/map-artifact-layout), and a governed
        // workflow task id (roles-impact-approver-view-001). The last one needs
        // at least three hyphen-separated segments so a descriptive work slug
        // stays distinguishable from a malformed ticket key such as
        // "project-123", which remains rejected.
        private readonly string $taskIdPattern = '/^(?:[A-Z][A-Z0-9_-]*-\d+|TODO@[\w:\/.-]+|[A-Za-z0-9][A-Za-z0-9._]*(?:-[A-Za-z0-9._]+){2,})$/',
    ) {
    }

    public static function isValidTargetPackage(string $package): bool
    {
        return preg_match(self::TARGET_PACKAGE_PATTERN, $package) === 1;
    }

    public function validateFile(string $path): Finding
    {
        $finding = $this->parser->parseFile($path);
        $this->validate($finding, $path);

        return $finding;
    }

    public function validate(Finding $finding, string $file, ?int $line = null): void
    {
        $this->redactionGuard->assertSafeValue($finding->raw, $file, $line, $finding->id);
        if (preg_match(RecordIdGenerator::pattern('finding'), $finding->id) !== 1) {
            throw new ValidationException($file, $line, $finding->id, 'finding id must match finding.YYYY-MM-DD.<suffix>');
        }
        if (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $finding->createdAt) === false) {
            throw new ValidationException($file, $line, $finding->id, 'malformed timestamp field: created_at');
        }
        if (preg_match($this->taskIdPattern, $finding->taskId) !== 1) {
            throw new ValidationException($file, $line, $finding->id, 'missing task references');
        }
        if (trim($finding->observation) === '') {
            throw new ValidationException($file, $line, $finding->id, 'empty observation');
        }
        if (trim($finding->hypothesis) === '') {
            throw new ValidationException($file, $line, $finding->id, 'empty hypothesis');
        }
        if ($finding->observation === $finding->hypothesis) {
            throw new ValidationException($file, $line, $finding->id, 'observation and hypothesis must remain separate');
        }
        if (!in_array($finding->confidence, self::CONFIDENCE, true)) {
            throw new ValidationException($file, $line, $finding->id, 'unsupported confidence');
        }
        if (!in_array($finding->validationStatus, self::VALIDATION_STATUS, true)) {
            throw new ValidationException($file, $line, $finding->id, 'unsupported validation_status');
        }
        $this->assertLifecycleCombination($finding, $file, $line);
        if ($finding->validationStatus === 'validated' && ($finding->validatedConclusion === null || trim($finding->validatedConclusion) === '')) {
            throw new ValidationException($file, $line, $finding->id, 'validated finding requires validated_conclusion');
        }
        if ($finding->validatedConclusion !== null && trim($finding->validatedConclusion) === trim($finding->hypothesis)) {
            throw new ValidationException($file, $line, $finding->id, 'hypothesis presented as validated fact');
        }

        $this->assertExternalTarget($finding, $file, $line);
        $this->assertLearningTriage($finding, $file, $line);
        $this->assertLineageReferences($finding->id, $finding->raw, $file, $line);
        $this->evidenceValidator->validate($finding->evidence, $file, $line, $finding->id);
    }

    private function assertExternalTarget(Finding $finding, string $file, ?int $line): void
    {
        if ($finding->targetPackage === null) {
            if ($finding->testedRef !== null) {
                throw new ValidationException($file, $line, $finding->id, 'tested_ref requires target_package');
            }

            return;
        }
        if (!self::isValidTargetPackage($finding->targetPackage)) {
            throw new ValidationException($file, $line, $finding->id, 'target_package must be a lowercase vendor/package identity');
        }
        if ($finding->testedRef !== null && trim($finding->testedRef) === '') {
            throw new ValidationException($file, $line, $finding->id, 'tested_ref must be non-empty when present');
        }
    }

    private function assertLearningTriage(Finding $finding, string $file, ?int $line): void
    {
        if ($finding->patternKey !== null && preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $finding->patternKey) !== 1) {
            throw new ValidationException($file, $line, $finding->id, 'pattern_key must use stable dot-separated lowercase segments');
        }

        if ($finding->classification === null) {
            return;
        }

        if ($finding->classification === LearningClassification::IGNORE) {
            return;
        }

        if ($finding->patternKey === null || trim($finding->patternKey) === '') {
            throw new ValidationException($file, $line, $finding->id, 'classified learning requires pattern_key');
        }

        if (!$finding->validationCase instanceof ValidationCase) {
            throw new ValidationException($file, $line, $finding->id, 'classified learning requires validation_case');
        }
    }

    private function assertLifecycleCombination(Finding $finding, string $file, ?int $line): void
    {
        $allowedValidationStatuses = match ($finding->status) {
            FindingStatus::CANDIDATE => ['unverified'],
            FindingStatus::VALIDATED, FindingStatus::CONSOLIDATED => ['validated'],
            FindingStatus::INVALIDATED => ['invalidated'],
            FindingStatus::SUPERSEDED, FindingStatus::REJECTED => ['validated', 'invalidated'],
            FindingStatus::ARCHIVED => self::VALIDATION_STATUS,
        };

        if (!in_array($finding->validationStatus, $allowedValidationStatuses, true)) {
            throw new ValidationException(
                $file,
                $line,
                $finding->id,
                'finding status '
                . $finding->status->value
                . ' cannot use validation_status='
                . $finding->validationStatus
            );
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function assertLineageReferences(string $id, array $raw, string $file, ?int $line): void
    {
        foreach (['conflicts_with', 'supersedes_findings'] as $field) {
            $references = $raw[$field] ?? null;
            if ($references === null) {
                continue;
            }
            if (!is_array($references) || $references === []) {
                throw new ValidationException($file, $line, $id, $field . ' must be a non-empty list of finding IDs');
            }
            foreach ($references as $reference) {
                if (!is_string($reference) || trim($reference) === '' || $reference === $id) {
                    throw new ValidationException($file, $line, $id, $field . ' must contain distinct non-empty finding IDs');
                }
            }
        }
        $contradictedProposal = $raw['contradicts_proposal_id'] ?? null;
        if ($contradictedProposal !== null && (!is_string($contradictedProposal) || trim($contradictedProposal) === '')) {
            throw new ValidationException($file, $line, $id, 'contradicts_proposal_id must be a non-empty proposal ID');
        }
    }
}
