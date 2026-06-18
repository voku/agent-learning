<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final class GuidanceOutcomeEventParser
{
    public function __construct(
        private readonly RecordAccess $recordAccess = new RecordAccess(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function parse(array $record, string $file, ?int $line = null): GuidanceOutcomeEvent
    {
        $id = is_string($record['id'] ?? null) ? $record['id'] : null;
        $this->redactionGuard->assertSafeValue($record, $file, $line, $id);
        if (($record['schema_version'] ?? null) !== '1.0') {
            throw new ValidationException($file, $line, $id, 'unsupported guidance outcome schema version');
        }
        if (!is_string($id) || preg_match('/^guidance-outcome\.\d{4}-\d{2}-\d{2}\.\d{3}$/', $id) !== 1) {
            throw new ValidationException($file, $line, $id, 'guidance outcome id must match guidance-outcome.YYYY-MM-DD.NNN');
        }

        $outcomeValue = $this->recordAccess->string($record, 'outcome', $file, $line, $id);
        $outcome = OutcomeValue::tryFrom($outcomeValue);
        if (!$outcome instanceof OutcomeValue) {
            throw new ValidationException($file, $line, $id, 'unknown guidance outcome value: ' . $outcomeValue);
        }

        $recordedAt = $this->recordAccess->string($record, 'recorded_at', $file, $line, $id);
        if (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $recordedAt) === false) {
            throw new ValidationException($file, $line, $id, 'malformed timestamp field: recorded_at');
        }

        $taskId = $this->recordAccess->string($record, 'task_id', $file, $line, $id);
        if (trim($taskId) === '') {
            throw new ValidationException($file, $line, $id, 'invalid task reference');
        }

        $applied = $record['applied'] ?? null;
        if (!is_bool($applied)) {
            throw new ValidationException($file, $line, $id, 'field must be boolean: applied');
        }

        return new GuidanceOutcomeEvent(
            $id,
            $this->recordAccess->string($record, 'compilation_id', $file, $line, $id),
            $taskId,
            $this->recordAccess->string($record, 'guidance_id', $file, $line, $id),
            $outcome,
            $applied,
            $this->recordAccess->optionalString($record, 'comment', $file, $line, $id),
            $this->recordAccess->string($record, 'commit', $file, $line, $id),
            $this->recordAccess->string($record, 'recorded_by', $file, $line, $id),
            $recordedAt,
            $record,
        );
    }
}
