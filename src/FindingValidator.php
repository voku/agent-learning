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

    public function __construct(
        private readonly FindingParser $parser = new FindingParser(),
        private readonly EvidenceValidator $evidenceValidator = new EvidenceValidator(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
        private readonly string $taskIdPattern = '/^(?:[A-Z][A-Z0-9_-]*-\d+|TODO@[\w:\/.-]+)$/',
    ) {
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
        if (preg_match('/^finding\.\d{4}-\d{2}-\d{2}\.\d{3}$/', $finding->id) !== 1) {
            throw new ValidationException($file, $line, $finding->id, 'finding id must match finding.YYYY-MM-DD.NNN');
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

        $this->evidenceValidator->validate($finding->evidence, $file, $line, $finding->id);
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
}
