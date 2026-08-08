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
            foreach ($allProposals as $candidate) {
                if ($candidate->target === $proposal->target && $candidate->status === ProposalStatus::APPROVED && strcmp($candidate->createdAt, $proposal->createdAt) > 0) {
                    throw new ValidationException($proposalPath, null, $proposalId, 'newer conflicting proposal already approved for target: ' . $proposal->target);
                }
            }
        }

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);
        $originalProposalContent = file_get_contents($proposalPath);
        $decisionsPath = $root . '/history/decisions.jsonl';
        $originalDecisionsContent = is_file($decisionsPath) ? file_get_contents($decisionsPath) : null;

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

        $decisionRecord = [
            'id' => $this->generateDecisionId($root, $now),
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
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            $this->restoreFile($decisionsPath, $originalDecisionsContent);
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal approval failed and was rolled back: ' . $e->getMessage());
        }
    }

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
        $originalProposalContent = file_get_contents($proposalPath);
        $rejectedPath = $root . '/history/rejected-proposals.jsonl';
        $originalRejectedContent = is_file($rejectedPath) ? file_get_contents($rejectedPath) : null;

        $data = $proposal->raw;
        $data['status'] = ProposalStatus::REJECTED->value;
        $data['reason'] = $reason;
        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/rejected';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $rejectionRecord = [
            'id' => $this->generateRejectionId($root, $now),
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
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            $this->restoreFile($rejectedPath, $originalRejectedContent);
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal rejection failed and was rolled back: ' . $e->getMessage());
        }
    }

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
        $originalProposalContent = file_get_contents($proposalPath);
        $acknowledgedPath = $root . '/history/acknowledged-proposals.jsonl';
        $originalAcknowledgedContent = is_file($acknowledgedPath) ? file_get_contents($acknowledgedPath) : null;

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

        $acknowledgementRecord = [
            'id' => $this->generateAcknowledgementId($root, $now),
            'proposal_id' => $proposalId,
            'acknowledged_by' => $actor,
            'acknowledged_at' => $nowStr,
            'reason' => $reason,
        ];
        $acknowledgementLine = json_encode($acknowledgementRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

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
            if (!is_dir(dirname($acknowledgedPath))) {
                mkdir(dirname($acknowledgedPath), 0777, true);
            }
            file_put_contents($acknowledgedPath, $acknowledgementLine, FILE_APPEND);
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            $this->restoreFile($acknowledgedPath, $originalAcknowledgedContent);
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal acknowledgement failed and was rolled back: ' . $e->getMessage());
        }
    }

    public function generateAcknowledgementId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateHistoryId($root . '/history/acknowledged-proposals.jsonl', 'acknowledgement', $date);
    }

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
        $originalProposalContent = file_get_contents($proposalPath);
        $retiredPath = $root . '/history/retired-proposals.jsonl';
        $originalRetiredContent = is_file($retiredPath) ? file_get_contents($retiredPath) : null;

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

        $retirementRecord = [
            'id' => $this->generateRetirementId($root, $now),
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
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            $this->restoreFile($retiredPath, $originalRetiredContent);
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal retirement failed and was rolled back: ' . $e->getMessage());
        }
    }

    public function generateRetirementId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateHistoryId($root . '/history/retired-proposals.jsonl', 'retirement', $date);
    }

    public function generateDecisionId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateHistoryId($root . '/history/decisions.jsonl', 'decision', $date);
    }

    public function generateRejectionId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateHistoryId($root . '/history/rejected-proposals.jsonl', 'rejection', $date);
    }

    /**
     * Mark an approved proposal as applied. For memory/skill guidance, the
     * validation JSON must bind the semantic proposal target to the actual
     * repository file via `target_source_ref`. Once that target is verified,
     * the proposal is retired in the same transaction so Recall cannot observe
     * both the proposal and its canonical home as active guidance.
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

        $isConstraint = $proposal->targetType === GuidanceType::CONSTRAINT->value;
        if ($isConstraint) {
            $this->validateConstraintAppliedMetadata($validationData, $validationFilePath, $proposalId);
        }

        $canonicalTarget = $isConstraint
            ? null
            : $this->verifyCanonicalTarget($root, $proposal, $validationData, $validationFilePath);

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);
        $originalProposalContent = file_get_contents($proposalPath);
        $decisionsPath = $root . '/history/decisions.jsonl';
        $originalDecisionsContent = is_file($decisionsPath) ? file_get_contents($decisionsPath) : null;
        $retiredHistoryPath = $root . '/history/retired-proposals.jsonl';
        $originalRetiredContent = is_file($retiredHistoryPath) ? file_get_contents($retiredHistoryPath) : null;

        $data = $proposal->raw;
        $data['status'] = $isConstraint ? ProposalStatus::APPLIED->value : ProposalStatus::RETIRED->value;
        $data['applied_by'] = $actor;
        $data['applied_at'] = $nowStr;
        $data['commit'] = $commit;
        $data['applied_validation'] = $validationData;

        if ($canonicalTarget !== null) {
            $data['target_source_ref'] = $canonicalTarget['source_ref'];
            $data['target_content_hash'] = $canonicalTarget['sha256'];
            $data['proposal_reason'] = $proposal->reason;
            $data['reason'] = 'Canonical ' . $proposal->targetType . ' target verified during apply: ' . $canonicalTarget['source_ref'];
            $data['retired_by'] = $actor;
            $data['retired_at'] = $nowStr;
        }

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $targetDir = $root . '/proposals/' . ($isConstraint ? 'applied' : 'retired');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $proposalId . '.json';

        $decisionRecord = [
            'id' => $this->generateDecisionId($root, $now),
            'proposal_id' => $proposalId,
            'status' => 'applied',
            'approved_by' => $proposal->approvedBy ?? $actor,
            'approved_at' => $proposal->approvedAt ?? $nowStr,
            'applied_by' => $actor,
            'applied_at' => $nowStr,
            'commit' => $commit,
            'validation' => $validationData,
        ];
        if ($canonicalTarget !== null) {
            $decisionRecord['target_source_ref'] = $canonicalTarget['source_ref'];
            $decisionRecord['target_content_hash'] = $canonicalTarget['sha256'];
        }
        $decisionLine = json_encode($decisionRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $retirementLine = null;
        if ($canonicalTarget !== null) {
            $retirementRecord = [
                'id' => $this->generateRetirementId($root, $now),
                'proposal_id' => $proposalId,
                'retired_by' => $actor,
                'retired_at' => $nowStr,
                'reason' => $data['reason'],
                'target_source_ref' => $canonicalTarget['source_ref'],
                'target_content_hash' => $canonicalTarget['sha256'],
            ];
            $retirementLine = json_encode($retirementRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

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
            if ($retirementLine !== null) {
                file_put_contents($retiredHistoryPath, $retirementLine, FILE_APPEND);
            }

            $this->validateRepository($root);
        } catch (\Throwable $e) {
            if (is_file($targetPath) && $targetPath !== $proposalPath) {
                rename($targetPath, $proposalPath);
            }
            file_put_contents($proposalPath, $originalProposalContent);
            $this->restoreFile($decisionsPath, $originalDecisionsContent);
            $this->restoreFile($retiredHistoryPath, $originalRetiredContent);
            throw new ValidationException($proposalPath, null, $proposalId, 'proposal apply failed and was rolled back: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed $validationData
     * @return array{source_ref: string, sha256: string}
     */
    private function verifyCanonicalTarget(string $root, Proposal $proposal, mixed $validationData, string $validationFilePath): array
    {
        if (!is_array($validationData)) {
            throw new ValidationException($validationFilePath, null, $proposal->id, 'guidance apply validation must be a JSON object');
        }

        $sourceRef = $validationData['target_source_ref'] ?? null;
        if (!is_string($sourceRef) || trim($sourceRef) === '') {
            throw new ValidationException($validationFilePath, null, $proposal->id, 'memory/skill apply requires target_source_ref in validation evidence');
        }
        $sourceRef = str_replace('\\', '/', trim($sourceRef));
        if (str_starts_with($sourceRef, '/') || preg_match('/^[A-Za-z]:\//', $sourceRef) === 1 || in_array('..', explode('/', $sourceRef), true)) {
            throw new ValidationException($validationFilePath, null, $proposal->id, 'target_source_ref must be a relative path inside the project root');
        }

        $projectRoot = rtrim((new LearningRootResolver())->resolve($root)->projectRoot, '/\\');
        $targetPath = $projectRoot . '/' . ltrim($sourceRef, '/');
        if (!is_file($targetPath)) {
            throw new ValidationException($targetPath, null, $proposal->id, 'canonical guidance target file does not exist');
        }

        $realProjectRoot = realpath($projectRoot);
        $realTargetPath = realpath($targetPath);
        if ($realProjectRoot === false || $realTargetPath === false || !str_starts_with($realTargetPath, rtrim($realProjectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new ValidationException($targetPath, null, $proposal->id, 'canonical guidance target resolves outside the project root');
        }

        $targetContent = file_get_contents($realTargetPath);
        if ($targetContent === false) {
            throw new ValidationException($targetPath, null, $proposal->id, 'canonical guidance target cannot be read');
        }

        if ($proposal->action === Action::ADD) {
            $this->assertContains($targetContent, $proposal->new, $targetPath, $proposal->id, 'added guidance wording is not present in canonical target');
        } elseif ($proposal->action === Action::REPLACE) {
            $this->assertContains($targetContent, $proposal->new, $targetPath, $proposal->id, 'replacement guidance wording is not present in canonical target');
            if ($proposal->old !== null && str_contains($targetContent, $proposal->old)) {
                throw new ValidationException($targetPath, null, $proposal->id, 'replaced guidance wording is still present in canonical target');
            }
        } elseif ($proposal->action === Action::DELETE) {
            if ($proposal->old !== null && str_contains($targetContent, $proposal->old)) {
                throw new ValidationException($targetPath, null, $proposal->id, 'deleted guidance wording is still present in canonical target');
            }
        } else {
            throw new ValidationException($proposal->id, null, $proposal->id, 'unsupported durable action for canonical guidance handoff');
        }

        $contentHash = hash_file('sha256', $realTargetPath);
        if ($contentHash === false) {
            throw new ValidationException($targetPath, null, $proposal->id, 'canonical guidance target could not be hashed');
        }

        return ['source_ref' => $sourceRef, 'sha256' => $contentHash];
    }

    private function assertContains(string $content, ?string $needle, string $file, string $proposalId, string $message): void
    {
        if ($needle === null || $needle === '' || !str_contains($content, $needle)) {
            throw new ValidationException($file, null, $proposalId, $message);
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

    private function generateHistoryId(string $path, string $kind, ?DateTimeImmutable $date): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $prefix = $kind . '.' . $dateObj->format('Y-m-d') . '.';
        $maxNum = 0;
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                $id = is_array($decoded) ? ($decoded['id'] ?? '') : '';
                if (is_string($id) && str_starts_with($id, $prefix)) {
                    $suffix = substr($id, strlen($prefix));
                    if (is_numeric($suffix)) {
                        $maxNum = max($maxNum, (int) $suffix);
                    }
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1);
    }

    private function restoreFile(string $path, string|false|null $original): void
    {
        if ($original === null || $original === false) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        file_put_contents($path, $original);
    }

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
