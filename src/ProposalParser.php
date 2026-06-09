<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ProposalParser
{
    public function __construct(
        private readonly Json $json = new Json(),
        private readonly RecordAccess $recordAccess = new RecordAccess(),
    ) {
    }

    public function parseFile(string $path): Proposal
    {
        return $this->parseRecord($this->json->decodeObjectFile($path), $path);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function parseRecord(array $record, string $file, ?int $line = null): Proposal
    {
        $recordId = is_string($record['id'] ?? null) ? $record['id'] : null;
        $actionValue = $this->recordAccess->string($record, 'action', $file, $line, $recordId);
        $action = Action::tryFrom($actionValue);
        if ($action === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported action: ' . $actionValue);
        }
        $statusValue = $this->recordAccess->string($record, 'status', $file, $line, $recordId);
        $status = ProposalStatus::tryFrom($statusValue);
        if ($status === null) {
            throw new ValidationException($file, $line, $recordId, 'unsupported proposal status: ' . $statusValue);
        }

        return new Proposal(
            $this->recordAccess->string($record, 'id', $file, $line, $recordId),
            $this->recordAccess->string($record, 'created_at', $file, $line, $recordId),
            $action,
            $this->recordAccess->optionalString($record, 'target_type', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'target', $file, $line, $recordId),
            isset($record['scope']) ? $this->recordAccess->stringList($record, 'scope', $file, $line, $recordId) : [],
            $this->recordAccess->stringList($record, 'source_findings', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'old', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'new', $file, $line, $recordId),
            $this->recordAccess->string($record, 'reason', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'boundary', $file, $line, $recordId),
            isset($record['validation']) ? $this->recordAccess->stringList($record, 'validation', $file, $line, $recordId) : [],
            $status,
            $this->recordAccess->string($record, 'proposed_by', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'approved_by', $file, $line, $recordId),
            $this->recordAccess->optionalString($record, 'approved_at', $file, $line, $recordId),
            $record,
        );
    }
}
