<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class FindingParser
{
    public function __construct(
        private readonly Json $json = new Json(),
        private readonly RecordAccess $recordAccess = new RecordAccess(),
    ) {
    }

    public function parseFile(string $path): Finding
    {
        return $this->parseRecord($this->json->decodeObjectFile($path), $path);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function parseRecord(array $record, string $file, ?int $line = null): Finding
    {
        $recordId = is_string($record['id'] ?? null) ? $record['id'] : null;
        $statusValue = $this->recordAccess->string($record, 'status', $file, $line, $recordId);
        $status = FindingStatus::tryFrom($statusValue);
        if ($status === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported finding status: ' . $statusValue);
        }

        return new Finding(
            $this->recordAccess->string($record, 'id', $file, $line, $recordId),
            $this->recordAccess->string($record, 'task_id', $file, $line, $recordId),
            $this->recordAccess->string($record, 'session', $file, $line, $recordId),
            $this->recordAccess->string($record, 'created_at', $file, $line, $recordId),
            $this->recordAccess->string($record, 'created_by', $file, $line, $recordId),
            $this->recordAccess->stringList($record, 'scope', $file, $line, $recordId),
            $this->recordAccess->string($record, 'observation', $file, $line, $recordId),
            $this->recordAccess->objectList($record, 'evidence', $file, $line, $recordId),
            $this->recordAccess->string($record, 'hypothesis', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'validated_conclusion', $file, $line, $recordId),
            $this->recordAccess->string($record, 'confidence', $file, $line, $recordId),
            $this->recordAccess->string($record, 'validation_status', $file, $line, $recordId),
            $status,
            $this->recordAccess->string($record, 'sensitivity', $file, $line, $recordId),
            $record,
        );
    }
}
