<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Governs state transitions for Proposals (Approve, Reject, Apply).
 */
final class ProposalTransitionManager
{
    private readonly ProposalLifecycle $lifecycle;

    public function __construct()
    {
        $this->lifecycle = new ProposalLifecycle();
    }

    /**
     * Resolve the absolute path of a proposal file.
     *
     * @param string $proposalId
     * @param string $root
     * @return string
     * @throws ValidationException
     */
    public function resolveProposalPath(string $proposalId, string $root): string
    {
        foreach ($this->lifecycle->directories() as $dir) {
            $path = $root . '/proposals/' . $dir . '/' . $proposalId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }
        throw new ValidationException('', null, $proposalId, 'proposal file not found: ' . $proposalId);
    }

    /**
     * Approve a candidate proposal.
     *
     * @param string $root
     * @param string $proposalId
     * @param string $actor
     * @throws ValidationException
     */
    public function approve(string $root, string $proposalId, string $actor): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $proposalId, 'actor name must be explicit');
        }

        $proposalPath = $this->resolveProposalPath($proposalId, $root);
        $proposal = (new ProposalParser())->parseFile($proposalPath);

        if ($proposal->status !== ProposalStatus::CANDIDATE) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal is not a candidate');
        }

        if ($proposal->action === Action::NO_DURABLE_LEARNING || $proposal->action === Action::REJECT) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal with action ' . $proposal->action->value . ' cannot be approved');
        }

        // Validate source findings
        $findingsById = $this->loadValidatedFindings($root);
        foreach ($proposal->sourceFindings as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if ($finding === null) {
                throw new ValidationException($proposalPath, null, $proposalId, 'source finding does not exist: ' . $findingId);
            }
            if ($finding->status !== FindingStatus::VALIDATED && $finding->status !== FindingStatus::CONSOLIDATED && $finding->status !== FindingStatus::ARCHIVED) {
                throw new ValidationException($proposalPath, null, $proposalId, 'source finding is not validated, consolidated, or archived: ' . $findingId);
            }
        }

        // Check if a newer approved proposal for the same target exists
        if ($proposal->target !== null) {
            $allProposals = (new ProposalRepository())->loadAll($root, $findingsById);
            foreach ($allProposals as $p) {
                if ($p->target === $proposal->target && $p->status === ProposalStatus::APPROVED && strcmp($p->createdAt, $proposal->createdAt) > 0) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'newer conflicting proposal already approved for target: ' . $proposal->target);
                }
            }
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);

        // Backup current state
        $originalProposalContent = file_get_contents($proposalPath);
        $decisionsPath = $root . '/history/decisions.jsonl';
        $originalDecisionsContent = is_file($decisionsPath) ? file_get_contents($decisionsPath) : null;

        // Prepare updated proposal JSON
        $data = $proposal->raw;
        $data['status'] = ProposalStatus::APPROVED->value;
        $data['approved_by'] = $actor;
        $data['approved_at'] = $nowStr;

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/approved';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $decisionId = $this->generateDecisionId($root, $now);
        $decisionRecord = [
            'id' => $decisionId,
            'proposal_id' => $proposalId,
            'status' => 'approved',
            'approved_by' => $actor,
            'approved_at' => $nowStr,
        ];
        $decisionLine = json_encode($decisionRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        try {
            file_put_contents($proposalPath, $updatedContent);
            if ($proposalPath !== $targetPath) {
                if (is_file($targetPath)) {
                    throw new ValidationException($targetPath, null, $proposalId, 'target file already exists');
                }
                if (!rename($proposalPath, $targetPath)) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'failed to move proposal file');
                }
            }
            if (!is_dir(dirname($decisionsPath))) {
                mkdir(dirname($decisionsPath), 0777, true);
            }
            file_put_contents($decisionsPath, $decisionLine, FILE_APPEND);

            // Re-validate entire repo
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            // Rollback
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            if ($originalDecisionsContent === null) {
                if (is_file($decisionsPath)) {
                    unlink($decisionsPath);
                }
            } else {
                file_put_contents($decisionsPath, $originalDecisionsContent);
            }
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal approval failed and was rolled back: ' . $e->getMessage());
        }
    }

    /**
     * Reject a candidate proposal.
     *
     * @param string $root
     * @param string $proposalId
     * @param string $actor
     * @param string $reason
     * @throws ValidationException
     */
    public function reject(string $root, string $proposalId, string $actor, string $reason): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $proposalId, 'actor name must be explicit');
        }
        if (trim($reason) === '') {
            throw new ValidationException('', null, $proposalId, 'rejection reason must be explicit');
        }

        $proposalPath = $this->resolveProposalPath($proposalId, $root);
        $proposal = (new ProposalParser())->parseFile($proposalPath);

        if ($proposal->status !== ProposalStatus::CANDIDATE) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal is not a candidate');
        }

        $now = new DateTimeImmutable('now');

        // Backup current state
        $originalProposalContent = file_get_contents($proposalPath);
        $rejectedPath = $root . '/history/rejected-proposals.jsonl';
        $originalRejectedContent = is_file($rejectedPath) ? file_get_contents($rejectedPath) : null;

        // Prepare updated proposal JSON
        $data = $proposal->raw;
        $data['status'] = ProposalStatus::REJECTED->value;
        $data['reason'] = $reason;

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/rejected';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $rejectionId = $this->generateRejectionId($root, $now);
        $rejectionRecord = [
            'id' => $rejectionId,
            'proposal_id' => $proposalId,
            'reason' => $reason,
        ];
        $rejectionLine = json_encode($rejectionRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        try {
            file_put_contents($proposalPath, $updatedContent);
            if ($proposalPath !== $targetPath) {
                if (is_file($targetPath)) {
                    throw new ValidationException($targetPath, null, $proposalId, 'target file already exists');
                }
                if (!rename($proposalPath, $targetPath)) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'failed to move proposal file');
                }
            }
            if (!is_dir(dirname($rejectedPath))) {
                mkdir(dirname($rejectedPath), 0777, true);
            }
            file_put_contents($rejectedPath, $rejectionLine, FILE_APPEND);

            // Re-validate entire repo
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            // Rollback
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            if ($originalRejectedContent === null) {
                if (is_file($rejectedPath)) {
                    unlink($rejectedPath);
                }
            } else {
                file_put_contents($rejectedPath, $originalRejectedContent);
            }
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal rejection failed and was rolled back: ' . $e->getMessage());
        }
    }

    /**
     * Retire an applied proposal once its durable change is fully captured in its
     * target skill/doc/memory home, so it stops being read into every future active
     * recall guidance pool. Distinct from `reject()` (which discards a candidate that
     * never took effect) and from `FindingStatus::ARCHIVED`/`CONSOLIDATED` (which only
     * cover findings, not proposals).
     *
     * @param string $root
     * @param string $proposalId
     * @param string $actor
     * @param string $reason
     * @throws ValidationException
     */
    public function retire(string $root, string $proposalId, string $actor, string $reason): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $proposalId, 'actor name must be explicit');
        }
        if (trim($reason) === '') {
            throw new ValidationException('', null, $proposalId, 'retirement reason must be explicit');
        }

        $proposalPath = $this->resolveProposalPath($proposalId, $root);
        $proposal = (new ProposalParser())->parseFile($proposalPath);

        if ($proposal->status !== ProposalStatus::APPLIED) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal is not applied');
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);

        // Backup current state
        $originalProposalContent = file_get_contents($proposalPath);
        $retiredPath = $root . '/history/retired-proposals.jsonl';
        $originalRetiredContent = is_file($retiredPath) ? file_get_contents($retiredPath) : null;

        // Prepare updated proposal JSON
        $data = $proposal->raw;
        $data['status'] = ProposalStatus::RETIRED->value;
        $data['reason'] = $reason;
        $data['retired_by'] = $actor;
        $data['retired_at'] = $nowStr;

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/retired';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $retirementId = $this->generateRetirementId($root, $now);
        $retirementRecord = [
            'id' => $retirementId,
            'proposal_id' => $proposalId,
            'retired_by' => $actor,
            'retired_at' => $nowStr,
            'reason' => $reason,
        ];
        $retirementLine = json_encode($retirementRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        try {
            file_put_contents($proposalPath, $updatedContent);
            if ($proposalPath !== $targetPath) {
                if (is_file($targetPath)) {
                    throw new ValidationException($targetPath, null, $proposalId, 'target file already exists');
                }
                if (!rename($proposalPath, $targetPath)) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'failed to move proposal file');
                }
            }
            if (!is_dir(dirname($retiredPath))) {
                mkdir(dirname($retiredPath), 0777, true);
            }
            file_put_contents($retiredPath, $retirementLine, FILE_APPEND);

            // Re-validate entire repo
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            // Rollback
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            if ($originalRetiredContent === null) {
                if (is_file($retiredPath)) {
                    unlink($retiredPath);
                }
            } else {
                file_put_contents($retiredPath, $originalRetiredContent);
            }
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal retirement failed and was rolled back: ' . $e->getMessage());
        }
    }

    public function generateRetirementId(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'retirement.' . $dateStr . '.';

        $path = $root . '/history/retired-proposals.jsonl';
        $maxNum = 0;
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $decoded = json_decode($line, true);
                    $id = $decoded['id'] ?? '';
                    if (str_starts_with($id, $prefix)) {
                        $suffix = substr($id, strlen($prefix));
                        if (is_numeric($suffix)) {
                            $maxNum = max($maxNum, (int)$suffix);
                        }
                    }
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1);
    }

    public function generateDecisionId(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'decision.' . $dateStr . '.';

        $path = $root . '/history/decisions.jsonl';
        $maxNum = 0;
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $decoded = json_decode($line, true);
                    $id = $decoded['id'] ?? '';
                    if (str_starts_with($id, $prefix)) {
                        $suffix = substr($id, strlen($prefix));
                        if (is_numeric($suffix)) {
                            $maxNum = max($maxNum, (int)$suffix);
                        }
                    }
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1);
    }

    public function generateRejectionId(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'rejection.' . $dateStr . '.';

        $path = $root . '/history/rejected-proposals.jsonl';
        $maxNum = 0;
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $decoded = json_decode($line, true);
                    $id = $decoded['id'] ?? '';
                    if (str_starts_with($id, $prefix)) {
                        $suffix = substr($id, strlen($prefix));
                        if (is_numeric($suffix)) {
                            $maxNum = max($maxNum, (int)$suffix);
                        }
                    }
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1);
    }

    /**
     * Mark an approved proposal as applied.
     *
     * @param string $root
     * @param string $proposalId
     * @param string $actor
     * @param string $commit
     * @param string $validationFilePath
     * @throws ValidationException
     */
    public function apply(string $root, string $proposalId, string $actor, string $commit, string $validationFilePath): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $proposalId, 'actor name must be explicit');
        }
        if (trim($commit) === '') {
            throw new ValidationException('', null, $proposalId, 'commit reference must be explicit');
        }
        if (!is_file($validationFilePath)) {
            throw new ValidationException($validationFilePath, null, $proposalId, 'validation file not found');
        }

        $validationContent = file_get_contents($validationFilePath);
        if ($validationContent === false) {
            throw new ValidationException($validationFilePath, null, $proposalId, 'cannot read validation file');
        }
        try {
            $validationData = json_decode($validationContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException($validationFilePath, null, $proposalId, 'validation file is not valid JSON: ' . $e->getMessage());
        }

        $proposalPath = $this->resolveProposalPath($proposalId, $root);
        $proposal = (new ProposalParser())->parseFile($proposalPath);

        if ($proposal->status !== ProposalStatus::APPROVED) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal is not approved');
        }

        if ($proposal->targetType === GuidanceType::CONSTRAINT->value) {
            $this->validateConstraintAppliedMetadata($validationData, $validationFilePath, $proposalId);
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);

        $contentHash = null;
        if ($proposal->target !== null) {
            $targetPathCandidate1 = $root . '/' . $proposal->target;
            $targetPathCandidate2 = dirname($root) . '/' . $proposal->target;
            $targetFile = null;
            if (is_file($targetPathCandidate1)) {
                $targetFile = $targetPathCandidate1;
            } elseif (is_file($targetPathCandidate2)) {
                $targetFile = $targetPathCandidate2;
            }
            if ($targetFile !== null) {
                $contentHash = hash_file('sha256', $targetFile);
            }
        }

        $originalProposalContent = file_get_contents($proposalPath);
        $decisionsPath = $root . '/history/decisions.jsonl';
        $originalDecisionsContent = is_file($decisionsPath) ? file_get_contents($decisionsPath) : null;

        $data = $proposal->raw;
        $data['status'] = ProposalStatus::APPLIED->value;
        $data['applied_by'] = $actor;
        $data['applied_at'] = $nowStr;
        $data['commit'] = $commit;
        $data['applied_validation'] = $validationData;
        if ($contentHash !== null) {
            $data['target_content_hash'] = $contentHash;
        }

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/applied';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $decisionId = $this->generateDecisionId($root, $now);
        $decisionRecord = [
            'id' => $decisionId,
            'proposal_id' => $proposalId,
            'status' => 'applied',
            'approved_by' => $proposal->approvedBy ?? $actor,
            'approved_at' => $proposal->approvedAt ?? $nowStr,
            'applied_by' => $actor,
            'applied_at' => $nowStr,
            'commit' => $commit,
            'validation' => $validationData,
        ];
        if ($contentHash !== null) {
            $decisionRecord['target_content_hash'] = $contentHash;
        }
        $decisionLine = json_encode($decisionRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        try {
            file_put_contents($proposalPath, $updatedContent);
            if ($proposalPath !== $targetPath) {
                if (is_file($targetPath)) {
                    throw new ValidationException($targetPath, null, $proposalId, 'target file already exists');
                }
                if (!rename($proposalPath, $targetPath)) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'failed to move proposal file');
                }
            }
            if (!is_dir(dirname($decisionsPath))) {
                mkdir(dirname($decisionsPath), 0777, true);
            }
            file_put_contents($decisionsPath, $decisionLine, FILE_APPEND);

            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            if ($originalDecisionsContent === null) {
                if (is_file($decisionsPath)) {
                    unlink($decisionsPath);
                }
            } else {
                file_put_contents($decisionsPath, $originalDecisionsContent);
            }
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal apply failed and was rolled back: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, Finding>
     */
    private function loadValidatedFindings(string $root): array
    {
        $findingsById = [];
        $findingLifecycle = new FindingLifecycle();
        $findingValidator = new FindingValidator();
        foreach ($findingLifecycle->findingFiles($root) as $file) {
            $finding = $findingValidator->validateFile($file);
            $findingLifecycle->assertPathMatchesStatus($finding, $file, $root);
            $findingsById[$finding->id] = $finding;
        }
        return $findingsById;
    }

    private function validateRepository(string $root): void
    {
        $findingsById = $this->loadValidatedFindings($root);
        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);
        (new OutcomeRepository())->loadAll($root, $proposalsById);
    }

    /**
     * @param mixed $validationData
     */
    private function validateConstraintAppliedMetadata(mixed $validationData, string $file, string $proposalId): void
    {
        if (!is_array($validationData)) {
            throw new ValidationException($file, null, $proposalId, 'constraint applied validation must be a JSON object');
        }

        foreach (['generated_files', 'tests', 'content_hashes'] as $field) {
            $value = $validationData[$field] ?? null;
            if (!is_array($value) || $value === []) {
                throw new ValidationException($file, null, $proposalId, 'constraint applied validation requires non-empty array: ' . $field);
            }
        }

        foreach (['registration_file', 'commit'] as $field) {
            $value = $validationData[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new ValidationException($file, null, $proposalId, 'constraint applied validation requires non-empty string: ' . $field);
            }
        }

        $validationResult = $validationData['validation_result'] ?? null;
        if (!is_array($validationResult) || $validationResult === []) {
            throw new ValidationException($file, null, $proposalId, 'constraint applied validation requires validation_result object');
        }
    }
}
