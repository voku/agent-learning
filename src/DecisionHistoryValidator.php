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
        // The version-controlled proposal is the canonical record of its approval;
        // the history journal is a supplementary, possibly machine-local audit trail.
        // A journal may therefore be absent, but whatever it says must agree with the
        // committed proposal: it can never stand in for, or contradict, the approval.
        foreach ($this->jsonRecords($approvedPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $approvedPath, null);
            $status = $this->requireString($record, 'status', $approvedPath, $proposalId);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($approvedPath, null, $proposalId, 'history references unknown proposal');
            }
            if ($status === 'approved' || $status === 'applied') {
                $approvedBy = $this->nonEmptyString($record, 'approved_by');
                $approvedAt = $this->nonEmptyString($record, 'approved_at');
                if ($approvedBy === null || $approvedAt === null) {
                    throw new ValidationException($approvedPath, null, $proposalId, 'approved proposal requires approved_by and approved_at');
                }
                $this->assertAgreesWithCommittedApproval($proposalsById[$proposalId], $approvedBy, $approvedAt, $approvedPath);
            }
        }

        foreach ($this->jsonRecords($rejectedPath) as $record) {
            $proposalId = $this->requireString($record, 'proposal_id', $rejectedPath, null);
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($rejectedPath, null, $proposalId, 'history references unknown proposal');
            }
            if ($proposalsById[$proposalId]->status !== ProposalStatus::REJECTED) {
                throw new ValidationException($rejectedPath, null, $proposalId, 'history rejection contradicts committed proposal status: ' . $proposalsById[$proposalId]->status->value);
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

    private function assertAgreesWithCommittedApproval(Proposal $proposal, string $approvedBy, string $approvedAt, string $file): void
    {
        if ($proposal->approvedBy === null || $proposal->approvedAt === null) {
            throw new ValidationException($file, null, $proposal->id, 'history approval has no committed approval on the proposal');
        }
        if ($approvedBy !== $proposal->approvedBy || $approvedAt !== $proposal->approvedAt) {
            throw new ValidationException(
                $file,
                null,
                $proposal->id,
                'history approval contradicts the committed proposal approval: ' . $approvedBy . ' at ' . $approvedAt
                . ' vs ' . $proposal->approvedBy . ' at ' . $proposal->approvedAt,
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function nonEmptyString(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
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
