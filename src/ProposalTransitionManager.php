<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Closure;
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

        $decisionLine = fn (): string => json_encode([
            'id' => $this->generateDecisionId($root, $now),
            'proposal_id' => $proposalId,
            'status' => 'approved',
            'approved_by' => $actor,
            'approved_at' => $nowStr,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

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

        $rejectionLine = fn (): string => json_encode([
            'id' => $this->generateRejectionId($root, $now),
            'proposal_id' => $proposalId,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

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

        $acknowledgementLine = fn (): string => json_encode([
            'id' => $this->generateAcknowledgementId($root, $now),
            'proposal_id' => $proposalId,
            'acknowledged_by' => $actor,
            'acknowledged_at' => $nowStr,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $acknowledgedPath, $acknowledgementLine, 'acknowledgement');
    }

    public function generateAcknowledgementId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateSequentialHistoryId(
            $root . '/history/acknowledged-proposals.jsonl',
            'acknowledgement.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d') . '.',
        );
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

        $retirementLine = fn (): string => json_encode([
            'id' => $this->generateRetirementId($root, $now),
            'proposal_id' => $proposalId,
            'retired_by' => $actor,
            'retired_at' => $nowStr,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $retiredPath, $retirementLine, 'retirement');
    }

    /**
     * Re-anchor every applied guidance proof pinned to one target file.
     *
     * `applied_validation.target_content_hash` pins the whole target, so a shared
     * guidance home such as `MEMORY.md` cannot be edited again - not even to
     * repair an evidence path a directory move invalidated - without every
     * applied record on that file reporting drift it did not cause. Retiring
     * answers a curation question nobody asked, and `apply()` is closed to a
     * record that is already applied, so the proof had no way back to the truth.
     *
     * The unit of repair is the target rather than one proposal, because drift is
     * a property of the file: repairing one of several proofs on the same target
     * would leave the root invalid and could therefore never commit.
     *
     * This is a proof repair, not a decision. Each proposal's own guidance wording
     * must still be present - the same assertion the validator makes - and the
     * approval, application and validation evidence stay exactly as they were.
     * Only the hash, an explicit actor and an explicit reason are added.
     *
     * @return list<string> the repaired proposal ids, in stable order
     * @throws ValidationException when no applied proof names the target, the
     *                             target is missing, or one of them no longer
     *                             carries the guidance it claims
     */
    public function reanchorTarget(string $root, string $sourceRef, string $actor, string $reason): array
    {
        $sourceRef = trim(str_replace('\\', '/', $sourceRef));
        if ($sourceRef === '') {
            throw new ValidationException($root, null, null, 'target source ref must be explicit');
        }
        if (trim($actor) === '') {
            throw new ValidationException($root, null, null, 'actor name must be explicit');
        }
        if (trim($reason) === '') {
            throw new ValidationException($root, null, null, 're-anchor reason must be explicit');
        }

        $projectRoot = (new LearningRootResolver())->resolve($root)->projectRoot;
        if ($this->escapesProjectRoot($sourceRef)) {
            throw new ValidationException($root, null, null, 'target source ref must stay inside the project root: ' . $sourceRef);
        }
        $canonicalTarget = $this->canonicalTargetPath($projectRoot, $sourceRef);
        $hash = $canonicalTarget === null ? false : hash_file('sha256', $canonicalTarget);
        if ($hash === false) {
            throw new ValidationException($root, null, null, 'applied guidance target does not exist: ' . $sourceRef);
        }
        $hash = strtolower($hash);

        $now = new DateTimeImmutable('now');
        $nowStr = $now->format(DateTimeInterface::ATOM);
        $parser = new ProposalParser();
        $guidanceValidator = new AppliedGuidanceTargetValidator();

        $writes = [];
        $repaired = [];
        foreach ($this->appliedGuidanceFiles($root) as $proposalPath) {
            $record = $parser->parseFile($proposalPath);
            if (!in_array($record->targetType, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)) {
                continue;
            }

            $validation = $record->raw['applied_validation'] ?? null;
            if (!is_array($validation)) {
                continue;
            }
            // Proofs are matched by the file they resolve to, not by how they
            // spell it. `MEMORY.md` and `./MEMORY.md` are both valid in-root
            // references to the same target, and repairing only one spelling
            // would leave the others stale - which the repository validation at
            // the end of the transaction would then roll the whole repair back
            // for, naming a proposal the caller never had a way to reach.
            $recordRef = $validation['target_source_ref'] ?? null;
            if (!is_string($recordRef) || $this->escapesProjectRoot($recordRef)) {
                continue;
            }
            if ($this->canonicalTargetPath($projectRoot, $recordRef) !== $canonicalTarget) {
                continue;
            }

            $validation['target_content_hash'] = $hash;
            $validation['reanchored_by'] = $actor;
            $validation['reanchored_at'] = $nowStr;
            $validation['reanchor_reason'] = $reason;
            $data = $record->raw;
            $data['applied_validation'] = $validation;

            // A target that lost the rule must fail before anything is written,
            // rather than be re-pinned to a file that no longer proves it.
            $guidanceValidator->validate($parser->parseRecord($data, $proposalPath), $root, $proposalPath);

            $writes[$proposalPath] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $repaired[] = $record->id;
        }

        if ($repaired === []) {
            throw new ValidationException($root, null, null, 'no applied memory/skill proof names target: ' . $sourceRef);
        }

        // Allocated inside the transaction's lock rather than here: ids are read
        // from the log that this write is about to extend, so allocating them
        // before the lock is exactly the window where two runs agree on the same
        // sequence number.
        $historyLines = function () use ($root, $now, $repaired, $actor, $nowStr, $reason, $sourceRef, $hash): string {
            $lines = '';
            foreach ($repaired as $sequence => $proposalId) {
                $lines .= json_encode([
                    'id' => $this->generateReanchorId($root, $now, $sequence),
                    'proposal_id' => $proposalId,
                    'reanchored_by' => $actor,
                    'reanchored_at' => $nowStr,
                    'reason' => $reason,
                    'target_source_ref' => $sourceRef,
                    'target_content_hash' => $hash,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            }

            return $lines;
        };

        $this->persistReanchor($root, $writes, $historyLines);

        return $repaired;
    }

    /**
     * @param array<string, string> $writes proposal path => repaired content
     * @throws ValidationException when the repaired root does not validate
     */
    private function persistReanchor(string $root, array $writes, Closure $historyLines): void
    {
        $this->withRootLock($root, function () use ($root, $writes, $historyLines): void {
            $this->persistReanchorLocked($root, $writes, $historyLines);
        });
    }

    /**
     * @param array<string, string> $writes proposal path => repaired content
     * @param Closure(): string     $historyLines
     * @throws ValidationException when the repaired root does not validate
     */
    private function persistReanchorLocked(string $root, array $writes, Closure $historyLines): void
    {
        $historyPath = $root . '/history/reanchored-proposals.jsonl';
        $originalProposals = [];
        foreach (array_keys($writes) as $path) {
            $original = file_get_contents($path);
            if ($original === false) {
                throw new ValidationException($path, null, null, 'cannot read proposal file');
            }
            $originalProposals[$path] = $original;
        }
        $originalHistory = is_file($historyPath) ? file_get_contents($historyPath) : null;
        if ($originalHistory === false) {
            throw new ValidationException($historyPath, null, null, 'cannot read proposal history file');
        }

        try {
            foreach ($writes as $path => $content) {
                if (file_put_contents($path, $content) === false) {
                    throw new ValidationException($path, null, null, 'failed to write proposal file');
                }
            }
            $historyDir = dirname($historyPath);
            if (!is_dir($historyDir) && !mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
                throw new ValidationException($historyPath, null, null, 'failed to create proposal history directory');
            }
            if (file_put_contents($historyPath, $historyLines(), FILE_APPEND) === false) {
                throw new ValidationException($historyPath, null, null, 'failed to append proposal history');
            }

            $this->validateRepository($root);
        } catch (\Throwable $exception) {
            foreach ($originalProposals as $path => $content) {
                file_put_contents($path, $content);
            }
            if ($originalHistory === null) {
                if (is_file($historyPath)) {
                    unlink($historyPath);
                }
            } else {
                file_put_contents($historyPath, $originalHistory);
            }

            throw new ValidationException($root, null, null, 'proposal re-anchor failed and was rolled back: ' . $exception->getMessage());
        }
    }

    /**
     * Whether a target source ref points outside the project root.
     *
     * Mirrors `AppliedGuidanceTargetValidator`: an absolute path, a drive letter
     * or any `..` segment is not an in-root reference.
     */
    private function escapesProjectRoot(string $sourceRef): bool
    {
        $normalized = str_replace('\\', '/', trim($sourceRef));

        return $normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('~^[A-Za-z]:/~', $normalized) === 1
            || in_array('..', explode('/', $normalized), true);
    }

    /**
     * The real path an in-root target source ref names, or null when it does not
     * resolve to a file inside the project root.
     */
    private function canonicalTargetPath(string $projectRoot, string $sourceRef): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($sourceRef)), '/');
        $realProjectRoot = realpath($projectRoot);
        $realTargetPath = realpath(rtrim($projectRoot, '/\\') . '/' . $normalized);
        if ($realProjectRoot === false || $realTargetPath === false || !is_file($realTargetPath)) {
            return null;
        }

        $projectPrefix = rtrim(str_replace('\\', '/', $realProjectRoot), '/') . '/';
        $target = str_replace('\\', '/', $realTargetPath);

        return str_starts_with($target, $projectPrefix) ? $target : null;
    }

    /**
     * @return list<string>
     */
    private function appliedGuidanceFiles(string $root): array
    {
        $directory = $root . '/proposals/applied';
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * One re-anchor repairs every proof on a target, and the log has not been
     * written yet while those ids are allocated, so the caller passes how many
     * records it already allocated in this transaction.
     */
    public function generateReanchorId(string $root, ?DateTimeImmutable $date = null, int $offset = 0): string
    {
        return $this->generateSequentialHistoryId(
            $root . '/history/reanchored-proposals.jsonl',
            'reanchor.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d') . '.',
            $offset,
        );
    }

    public function generateRetirementId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateSequentialHistoryId(
            $root . '/history/retired-proposals.jsonl',
            'retirement.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d') . '.',
        );
    }

    public function generateDecisionId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateSequentialHistoryId(
            $root . '/history/decisions.jsonl',
            'decision.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d') . '.',
        );
    }

    public function generateRejectionId(string $root, ?DateTimeImmutable $date = null): string
    {
        return $this->generateSequentialHistoryId(
            $root . '/history/rejected-proposals.jsonl',
            'rejection.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d') . '.',
        );
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

        $decisionLine = fn (): string => json_encode([
            'id' => $this->generateDecisionId($root, $now),
            'proposal_id' => $proposalId,
            'status' => 'applied',
            'approved_by' => $proposal->approvedBy ?? $actor,
            'approved_at' => $proposal->approvedAt ?? $nowStr,
            'applied_by' => $actor,
            'applied_at' => $nowStr,
            'commit' => $commit,
            'validation' => $validationData,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->persistTransition($root, $proposalId, $proposalPath, $targetPath, $updatedContent, $decisionsPath, $decisionLine, 'apply');
    }

    /**
     * Next sequential id for a dated history prefix in a JSONL transition log.
     *
     * Every transition log allocates its ids the same way; keeping one scan means
     * a new transition cannot quietly disagree with the existing ones about what
     * "next" is.
     */
    private function generateSequentialHistoryId(string $path, string $prefix, int $offset = 0): string
    {
        $maxNum = 0;
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $decoded = json_decode($line, true);
                    $id = is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : '';
                    if (str_starts_with($id, $prefix)) {
                        $suffix = substr($id, strlen($prefix));
                        if (is_numeric($suffix)) {
                            $maxNum = max($maxNum, (int) $suffix);
                        }
                    }
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1 + $offset);
    }

    /**
     * Runs one transition's history-id allocation and its writes under a single
     * root-scoped exclusive lock.
     *
     * Every transition allocates its next id by scanning the log it is about to
     * append to, so allocation and persistence have to be one critical section:
     * two runs that both read the log before either appends agree on the same
     * sequence number and write duplicate audit ids. The lock is per learning
     * root because that, not the individual log file, is the unit transitions
     * validate as a whole.
     *
     * The lock file lives beside the logs it guards, so a project that already
     * ignores `history/` ignores it too.
     *
     * @param Closure(): void $operation
     * @throws ValidationException when the lock cannot be taken
     */
    private function withRootLock(string $root, Closure $operation): void
    {
        $lockDirectory = $root . '/history';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0777, true) && !is_dir($lockDirectory)) {
            throw new ValidationException($lockDirectory, null, null, 'failed to create the transition lock directory');
        }

        $lockPath = $lockDirectory . '/.transition.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new ValidationException($lockPath, null, null, 'cannot open the transition lock');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ValidationException($lockPath, null, null, 'cannot acquire the transition lock');
            }

            try {
                $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes one transition's proposal and history under the root lock.
     *
     * `$historyLine` is a closure rather than a finished string on purpose: it
     * allocates the record's id from the log this call is about to append to, so
     * it must run inside the lock. Building the line at the call site would put
     * the allocation back outside it, which is the race this indirection exists
     * to close.
     *
     * @param (Closure(): string)|null $historyLine
     * @throws ValidationException when the write fails or leaves the root invalid
     */
    private function persistTransition(
        string $root,
        string $proposalId,
        string $proposalPath,
        string $targetPath,
        string $updatedContent,
        ?string $historyPath,
        ?Closure $historyLine,
        string $transition,
    ): void {
        $this->withRootLock($root, function () use (
            $root,
            $proposalId,
            $proposalPath,
            $targetPath,
            $updatedContent,
            $historyPath,
            $historyLine,
            $transition,
        ): void {
            $this->persistTransitionLocked(
                $root,
                $proposalId,
                $proposalPath,
                $targetPath,
                $updatedContent,
                $historyPath,
                $historyLine,
                $transition,
            );
        });
    }

    /**
     * @param (Closure(): string)|null $historyLine
     * @throws ValidationException
     */
    private function persistTransitionLocked(
        string $root,
        string $proposalId,
        string $proposalPath,
        string $targetPath,
        string $updatedContent,
        ?string $historyPath,
        ?Closure $historyLine,
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
                if (file_put_contents($historyPath, $historyLine(), FILE_APPEND) === false) {
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
