<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final class RecallSelectionEventParser
{
    public function __construct(
        private readonly RecordAccess $recordAccess = new RecordAccess(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function parse(array $record, string $file, ?int $line = null): RecallSelectionEvent
    {
        $id = is_string($record['id'] ?? null) ? $record['id'] : null;
        $this->redactionGuard->assertSafeValue($record, $file, $line, $id);
        if (($record['schema_version'] ?? null) !== '1.0') {
            throw new ValidationException($file, $line, $id, 'unsupported recall selection schema version');
        }
        if (!is_string($id) || preg_match('/^recall-selection\.\d{4}-\d{2}-\d{2}\.\d{3}$/', $id) !== 1) {
            throw new ValidationException($file, $line, $id, 'recall selection id must match recall-selection.YYYY-MM-DD.NNN');
        }

        $guidanceTypeValue = $this->recordAccess->string($record, 'guidance_type', $file, $line, $id);
        $guidanceType = GuidanceType::tryFrom($guidanceTypeValue);
        if (!$guidanceType instanceof GuidanceType) {
            throw new ValidationException($file, $line, $id, 'unknown guidance type: ' . $guidanceTypeValue);
        }

        $recordedAt = $this->recordAccess->string($record, 'recorded_at', $file, $line, $id);
        if (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $recordedAt) === false) {
            throw new ValidationException($file, $line, $id, 'malformed timestamp field: recorded_at');
        }

        $taskId = $this->recordAccess->string($record, 'task_id', $file, $line, $id);
        if (trim($taskId) === '') {
            throw new ValidationException($file, $line, $id, 'invalid task reference');
        }

        $eligible = $this->bool($record, 'eligible', $file, $line, $id);
        $selected = $this->bool($record, 'selected', $file, $line, $id);
        if ($selected && !$eligible) {
            throw new ValidationException($file, $line, $id, 'selected recall selection must be eligible');
        }

        $outcomeWithheldReason = $this->recordAccess->optionalString(
            $record,
            'outcome_withheld_reason',
            $file,
            $line,
            $id,
        );
        if ($outcomeWithheldReason !== null && trim($outcomeWithheldReason) === '') {
            throw new ValidationException($file, $line, $id, 'outcome_withheld_reason must be non-empty when present');
        }

        return new RecallSelectionEvent(
            $id,
            $this->recordAccess->string($record, 'compilation_id', $file, $line, $id),
            $taskId,
            $this->recordAccess->string($record, 'guidance_id', $file, $line, $id),
            $guidanceType,
            $eligible,
            $selected,
            $this->recordAccess->optionalString($record, 'selection_reason', $file, $line, $id),
            $this->recordAccess->optionalString($record, 'exclusion_reason', $file, $line, $id),
            $this->recordAccess->stringList($record, 'task_files', $file, $line, $id),
            $recordedAt,
            $record,
            $outcomeWithheldReason,
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function bool(array $record, string $key, string $file, ?int $line, ?string $recordId): bool
    {
        $value = $record[$key] ?? null;
        if (!is_bool($value)) {
            throw new ValidationException($file, $line, $recordId, 'field must be boolean: ' . $key);
        }

        return $value;
    }
}
