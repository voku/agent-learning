<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class DecisionRecorder
{
    public function __construct(
        private readonly JsonlValidator $jsonlValidator = new JsonlValidator(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param array<string, Proposal> $proposalsById
     */
    public function validateHistory(string $root, array $proposalsById): void
    {
        $approvedPath = $root . '/history/decisions.jsonl';
        $rejectedPath = $root . '/history/rejected-proposals.jsonl';
        $approvedProposals = [];
        foreach ($this->jsonRecords($approvedPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $approvedPath, null);
            $status = $this->requireString($record, 'status', $approvedPath, $proposalId);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($approvedPath, null, $proposalId, 'history references unknown proposal');
            }
            if (($status === 'approved' || $status === 'applied') && (trim((string)($record['approved_by'] ?? '')) === '' || trim((string)($record['approved_at'] ?? '')) === '')) {
                throw new ValidationException($approvedPath, null, $proposalId, 'approved proposal requires approved_by and approved_at');
            }
            if ($status === 'approved') {
                $approvedProposals[$proposalId] = true;
            }
            if ($status === 'applied' && !isset($approvedProposals[$proposalId])) {
                throw new ValidationException($approvedPath, null, $proposalId, 'applied decision requires prior approved decision');
            }
        }

        foreach ($this->jsonRecords($rejectedPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $rejectedPath, null);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($rejectedPath, null, $proposalId, 'history references unknown proposal');
            }
            if (trim((string)($record['reason'] ?? '')) === '') {
                throw new ValidationException($rejectedPath, null, $proposalId, 'rejected proposal requires a reason');
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonRecords(string $path): array
    {
        $records = $this->jsonlValidator->parseFile($path);
        foreach ($records as $record) {
            $this->redactionGuard->assertSafeValue($record, $path, null, is_string($record['id'] ?? null) ? $record['id'] : null);
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function requireString(array $record, string $field, string $file, ?string $recordId): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($file, null, $recordId, 'history record requires string field: ' . $field);
        }

        return $value;
    }
}
