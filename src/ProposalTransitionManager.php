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

        $decisionsPath = $root . '/history/decisions.jsonl';

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

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $decisionsPath, $decisionLine, 'approval');
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
        $rejectedPath = $root . '/history/rejected-proposals.jsonl';

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

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $rejectedPath, $rejectionLine, 'rejection');
    }

    /**
     * Formally close a candidate NO_DURABLE_LEARNING proposal without approving or
     * rejecting it. Distinct from `reject()`: NO_DURABLE_LEARNING already represents a
     * correct, considered conclusion ("nothing durable to change here"), not a decision
     * the maintainer disagreed with. Before this transition existed, closing such a
     * proposal required misusing `reject()`, producing an audit trail that reads as
     * disapproval for what is actually acceptance of the analysis.
     *
     * @param string $root
     * @param string $proposalId
     * @param string $actor
     * @param string $reason
     * @throws ValidationException
     */
    public function acknowledge(string $root, string $proposalId, string $actor, string $reason): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $proposalId, 'actor name must be explicit');
        }
        if (trim($reason) === '') {
            throw new ValidationException('', null, $proposalId, 'acknowledgement reason must be explicit');
        }

        $proposalPath = $this->resolveProposalPath($proposalId, $root);
        $proposal = (new ProposalParser())->parseFile($proposalPath);

        if ($proposal->status !== ProposalStatus::CANDIDATE) {
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal is not a candidate');
        }

        if ($proposal->action !== Action::NO_DURABLE_LEARNING) {
            throw new ValidationException($proposalPath, null, $proposalId, 'only a NO_DURABLE_LEARNING proposal can be acknowledged; use approve() or reject() instead');
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);

        $acknowledgedPath = $root . '/history/acknowledged-proposals.jsonl';

        $data = $proposal->raw;
        $data['status'] = ProposalStatus::ACKNOWLEDGED->value;
        $data['acknowledged_by'] = $actor;
        $data['acknowledged_at'] = $nowStr;
        $data['reason'] = $reason;

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/acknowledged';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $acknowledgementId = $this->generateAcknowledgementId($root, $now);
        $acknowledgementRecord = [
            'id' => $acknowledgementId,
            'proposal_id' => $proposalId,
            'acknowledged_by' => $actor,
            'acknowledged_at' => $nowStr,
            'reason' => $reason,
        ];
        $acknowledgementLine = json_encode($acknowledgementRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $acknowledgedPath, $acknowledgementLine, 'acknowledgement');
    }

    public function generateAcknowledgementId(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'acknowledgement.' . $dateStr . '.';

        $path = $root . '/history/acknowledged-proposals.jsonl';
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

        $retiredPath = $root . '/history/retired-proposals.jsonl';

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

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $retiredPath, $retirementLine, 'retirement');
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

        if (!in_array($proposal->targetType, [
            GuidanceType::MEMORY->value,
            GuidanceType::SKILL->value,
            GuidanceType::CONSTRAINT->value,
        ], true)) {
            throw new ValidationException(
                $proposalPath,
                null,
                $proposalId,
                'new applied guidance requires target_type=memory, skill, or constraint',
            );
        }

        if ($proposal->targetType === GuidanceType::CONSTRAINT->value) {
            $this->validateConstraintAppliedMetadata($validationData, $validationFilePath, $proposalId);
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);

        $decisionsPath = $root . '/history/decisions.jsonl';

        $data = $proposal->raw;
        $data['status'] = ProposalStatus::APPLIED->value;
        $data['applied_by'] = $actor;
        $data['applied_at'] = $nowStr;
        $data['commit'] = $commit;
        $data['applied_validation'] = $validationData;

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
        $decisionLine = json_encode($decisionRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $decisionsPath, $decisionLine, 'apply');
    }

    private function persistTransition(
        string $root,
        string $proposalId,
        string $proposalPath,
        string $targetPath,
        string $updatedContent,
        ?string $historyPath,
        ?string $historyLine,
        string $transition,
    ): void {
        $originalProposalContent = file_get_contents($proposalPath);
        if ($originalProposalContent === false) {
            throw new ValidationException($proposalPath, null, $proposalId, 'cannot read proposal file');
        }

        $originalHistoryContent = null;
        if ($historyPath !== null && is_file($historyPath)) {
            $originalHistoryContent = file_get_contents($historyPath);
            if ($originalHistoryContent === false) {
                throw new ValidationException($historyPath, null, $proposalId, 'cannot read proposal history file');
            }
        }

        $moved = false;
        try {
            if ($proposalPath !== $targetPath && is_file($targetPath)) {
                throw new ValidationException($targetPath, null, $proposalId, 'target file already exists');
            }
            if (file_put_contents($proposalPath, $updatedContent) === false) {
                throw new ValidationException($proposalPath, null, $proposalId, 'failed to write proposal file');
            }
            if ($proposalPath !== $targetPath) {
                if (!rename($proposalPath, $targetPath)) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'failed to move proposal file');
                }
                $moved = true;
            }
            if ($historyPath !== null && $historyLine !== null) {
                $historyDir = dirname($historyPath);
                if (!is_dir($historyDir) && !mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
                    throw new ValidationException($historyPath, null, $proposalId, 'failed to create proposal history directory');
                }
                if (file_put_contents($historyPath, $historyLine, FILE_APPEND) === false) {
                    throw new ValidationException($historyPath, null, $proposalId, 'failed to append proposal history');
                }
            }

            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if ($moved && is_file($targetPath)) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            if ($historyPath !== null) {
                if ($originalHistoryContent === null) {
                    if (is_file($historyPath)) {
                        unlink($historyPath);
                    }
                } else {
                    file_put_contents($historyPath, $originalHistoryContent);
                }
            }
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal ' . $transition . ' failed and was rolled back: ' . $e->getMessage());
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
