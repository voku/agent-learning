<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class DecisionHistoryValidator
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
        $retiredPath = $root . '/history/retired-proposals.jsonl';
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

        foreach ($this->jsonRecords($retiredPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $retiredPath, null);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($retiredPath, null, $proposalId, 'history references unknown proposal');
            }
            if (trim((string)($record['reason'] ?? '')) === '') {
                throw new ValidationException($retiredPath, null, $proposalId, 'retired proposal requires a reason');
            }
        }

        // A re-anchor changes an applied proof rather than a decision, so the log
        // has to say who repaired it and why; an unexplained re-pin would be
        // indistinguishable from quietly following the target wherever it drifts.
        $reanchoredPath = $root . '/history/reanchored-proposals.jsonl';
        foreach ($this->jsonRecords($reanchoredPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $reanchoredPath, null);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($reanchoredPath, null, $proposalId, 'history references unknown proposal');
            }
            if (trim((string)($record['reason'] ?? '')) === '') {
                throw new ValidationException($reanchoredPath, null, $proposalId, 're-anchored proposal requires a reason');
            }
            if (trim((string)($record['reanchored_by'] ?? '')) === '') {
                throw new ValidationException($reanchoredPath, null, $proposalId, 're-anchored proposal requires reanchored_by');
            }
            if (trim((string)($record['reanchored_at'] ?? '')) === '') {
                throw new ValidationException($reanchoredPath, null, $proposalId, 're-anchored proposal requires reanchored_at');
            }
            // The target proof is the whole point of the record: a re-anchor that
            // does not say which file it re-pinned, and to what, is an audit entry
            // that cannot be checked against the repository it claims to describe.
            if (trim((string)($record['target_source_ref'] ?? '')) === '') {
                throw new ValidationException($reanchoredPath, null, $proposalId, 're-anchored proposal requires target_source_ref');
            }
            $hash = strtolower(trim((string)($record['target_content_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new ValidationException($reanchoredPath, null, $proposalId, 're-anchored proposal requires target_content_hash as sha256 hex');
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
