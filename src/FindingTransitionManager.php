<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Governs state transitions for Findings.
 */
final class FindingTransitionManager
{
    private readonly FindingLifecycle $lifecycle;

    public function __construct()
    {
        $this->lifecycle = new FindingLifecycle();
    }

    /**
     * Resolve the absolute path of a finding file.
     *
     * @param string $findingId
     * @param string $root
     * @return string
     * @throws ValidationException
     */
    public function resolveFindingPath(string $findingId, string $root): string
    {
        foreach ($this->lifecycle->directories() as $dir) {
            $path = $root . '/findings/' . $dir . '/' . $findingId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }
        throw new ValidationException('', null, $findingId, 'finding file not found: ' . $findingId);
    }

    /**
     * Transition a finding to a new status.
     *
     * @param string        $root
     * @param string        $findingId
     * @param FindingStatus $targetStatus
     * @param string        $actor
     * @throws ValidationException
     */
    public function transition(string $root, string $findingId, FindingStatus $targetStatus, string $actor): void
    {
        if (trim($actor) === '') {
            throw new ValidationException('', null, $findingId, 'actor name must be explicit');
        }

        $currentPath = $this->resolveFindingPath($findingId, $root);
        $originalContent = file_get_contents($currentPath);
        if ($originalContent === false) {
            throw new ValidationException($currentPath, null, $findingId, 'cannot read finding file');
        }

        $finding = (new FindingParser())->parseFile($currentPath);
        $currentStatus = $finding->status;

        if ($currentStatus === $targetStatus) {
            // Already in target state, no-op
            return;
        }

        if (!$this->isTransitionAllowed($currentStatus, $targetStatus)) {
            throw new ValidationException($currentPath, null, $findingId, sprintf('transition from %s to %s is not allowed', $currentStatus->value, $targetStatus->value));
        }

        // Prepare updated data
        $data = $finding->raw;
        $data['status'] = $targetStatus->value;
        if ($targetStatus === FindingStatus::VALIDATED || $targetStatus === FindingStatus::CONSOLIDATED) {
            $data['validation_status'] = 'validated';
        } elseif ($targetStatus === FindingStatus::INVALIDATED) {
            $data['validation_status'] = 'invalidated';
        } elseif ($targetStatus === FindingStatus::REJECTED) {
            if ($finding->validationStatus === 'unverified') {
                $data['validation_status'] = 'invalidated';
            }
        }

        $targetDir = $root . '/findings/' . $this->lifecycle->directoryFor($targetStatus);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . '/' . $findingId . '.json';

        $updatedContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Transaction/atomic block
        try {
            file_put_contents($currentPath, $updatedContent);
            if ($currentPath !== $targetPath) {
                if (is_file($targetPath)) {
                    throw new ValidationException($targetPath, null, $findingId, 'target file already exists');
                }
                if (!rename($currentPath, $targetPath)) {
                    throw new ValidationException($currentPath, null, $findingId, 'failed to move finding file to target directory');
                }
            }

            // Run repository validation
            $this->validateRepository($root);
        } catch (\Throwable $e) {
            // Rollback
            if (is_file($targetPath) && $targetPath !== $currentPath) {
                rename($targetPath, $currentPath);
            }
            file_put_contents($currentPath, $originalContent);
            throw new ValidationException($currentPath, null, $findingId, 'finding transition failed and was rolled back: ' . $e->getMessage());
        }
    }

    private function isTransitionAllowed(FindingStatus $current, FindingStatus $target): bool
    {
        if ($current === FindingStatus::CANDIDATE) {
            return in_array($target, [FindingStatus::VALIDATED, FindingStatus::INVALIDATED, FindingStatus::REJECTED], true);
        }

        if ($current === FindingStatus::VALIDATED) {
            return in_array($target, [FindingStatus::CONSOLIDATED, FindingStatus::SUPERSEDED, FindingStatus::ARCHIVED], true);
        }

        $terminalStates = [
            FindingStatus::VALIDATED,
            FindingStatus::INVALIDATED,
            FindingStatus::REJECTED,
            FindingStatus::CONSOLIDATED,
            FindingStatus::SUPERSEDED,
        ];

        if (in_array($current, $terminalStates, true) && $target === FindingStatus::ARCHIVED) {
            return true;
        }

        return false;
    }

    private function validateRepository(string $root): void
    {
        // Re-validate all findings, proposals, and history
        $findingsById = [];
        $findingValidator = new FindingValidator();
        foreach ($this->lifecycle->findingFiles($root) as $file) {
            $finding = $findingValidator->validateFile($file);
            $this->lifecycle->assertPathMatchesStatus($finding, $file, $root);
            if (isset($findingsById[$finding->id])) {
                throw new ValidationException($file, null, $finding->id, 'duplicate finding ID');
            }
            $findingsById[$finding->id] = $finding;
        }

        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);
        (new OutcomeRepository())->loadAll($root, $proposalsById);
    }
}
