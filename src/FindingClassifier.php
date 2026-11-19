<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Throwable;

/**
 * Applies reusable-learning triage after raw Finding capture.
 */
final readonly class FindingClassifier
{
    public function __construct(
        private FindingParser $parser = new FindingParser(),
        private FindingLifecycle $lifecycle = new FindingLifecycle(),
        private ProposalParser $proposalParser = new ProposalParser(),
        private ProposalLifecycle $proposalLifecycle = new ProposalLifecycle(),
    ) {
    }

    /**
     * Classify a validated Finding with the triage a candidate Proposal already recorded.
     *
     * Consolidation writes `learning_decision`, `pattern_key` and `validation_case` onto the
     * candidate Proposal, but LearningNote preparation reads the classification from the
     * Finding. Without this, a reviewer had to retype the same three values by hand, and
     * Proposals classified ADD_LEARNING_NOTE stayed stuck as Memory candidates. This copies
     * the recorded triage; it neither approves, rejects nor applies the Proposal, and
     * publishing a LearningNote remains a separate explicit step.
     */
    public function classifyFromProposal(
        string $root,
        string $findingId,
        string $proposalId,
        ?LearningClassification $expected = null,
        ?string $taskIdPattern = null,
    ): Finding {
        $path = $this->resolveProposalPath($root, $proposalId);
        $proposal = $this->proposalParser->parseFile($path);
        if ($proposal->id !== $proposalId) {
            throw new ValidationException($path, null, $proposalId, 'proposal file does not carry the requested ID');
        }
        if ($proposal->status !== ProposalStatus::CANDIDATE) {
            throw new ValidationException(
                $path,
                null,
                $proposalId,
                'finding-classify --from-proposal requires a candidate proposal; current status is ' . $proposal->status->value,
            );
        }
        if (!in_array($findingId, $proposal->sourceFindings, true)) {
            throw new ValidationException($path, null, $proposalId, 'proposal does not cite finding ' . $findingId);
        }
        $classification = $proposal->learningDecision;
        if (!$classification instanceof LearningClassification) {
            throw new ValidationException($path, null, $proposalId, 'proposal records no learning_decision to classify from');
        }
        if ($expected instanceof LearningClassification && $expected !== $classification) {
            throw new ValidationException(
                $path,
                null,
                $proposalId,
                sprintf('proposal learning_decision is %s, not the requested %s', $classification->value, $expected->value),
            );
        }

        return $this->classify(
            root: $root,
            findingId: $findingId,
            classification: $classification,
            patternKey: $proposal->patternKey,
            validationCase: $proposal->validationCase,
            taskIdPattern: $taskIdPattern,
        );
    }

    public function classify(
        string $root,
        string $findingId,
        LearningClassification $classification,
        ?string $patternKey = null,
        ?ValidationCase $validationCase = null,
        ?string $taskIdPattern = null,
    ): Finding {
        $path = $this->resolveFindingPath($root, $findingId);
        $finding = $this->parser->parseFile($path);
        if ($finding->status !== FindingStatus::VALIDATED) {
            throw new ValidationException(
                $path,
                null,
                $findingId,
                'finding-classify requires status validated; current status is ' . $finding->status->value,
            );
        }

        $raw = $finding->raw;
        $raw['classification'] = $classification->value;

        if ($classification === LearningClassification::IGNORE) {
            unset($raw['pattern_key'], $raw['validation_case']);
        } else {
            if ($patternKey === null || trim($patternKey) === '') {
                throw new ValidationException($path, null, $findingId, 'classified learning requires pattern_key');
            }
            if (!$validationCase instanceof ValidationCase) {
                throw new ValidationException($path, null, $findingId, 'classified learning requires validation_case');
            }

            $raw['pattern_key'] = trim($patternKey);
            $raw['validation_case'] = $validationCase->toArray();
        }

        $updated = $this->parser->parseRecord($raw, $path);
        $validator = $taskIdPattern === null
            ? new FindingValidator()
            : new FindingValidator(taskIdPattern: $taskIdPattern);
        $validator->validate($updated, $path);

        $encoded = json_encode(
            $updated->raw,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->replaceAtomically($path, $encoded, $findingId);

        return $updated;
    }

    private function resolveProposalPath(string $root, string $proposalId): string
    {
        foreach ($this->proposalLifecycle->directories() as $directory) {
            $path = $root . '/proposals/' . $directory . '/' . $proposalId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }

        throw new ValidationException($root, null, $proposalId, 'proposal file not found: ' . $proposalId);
    }

    private function resolveFindingPath(string $root, string $findingId): string
    {
        foreach ($this->lifecycle->directories() as $directory) {
            $path = $root . '/findings/' . $directory . '/' . $findingId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }

        throw new ValidationException($root, null, $findingId, 'finding file not found: ' . $findingId);
    }

    private function replaceAtomically(string $path, string $content, string $findingId): void
    {
        $temporaryPath = dirname($path) . '/.' . basename($path) . '.classify.' . bin2hex(random_bytes(8));
        $handle = fopen($temporaryPath, 'xb');
        if ($handle === false) {
            throw new ValidationException($temporaryPath, null, $findingId, 'cannot create temporary finding file');
        }

        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new ValidationException($temporaryPath, null, $findingId, 'cannot write temporary finding file');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new ValidationException($temporaryPath, null, $findingId, 'cannot flush temporary finding file');
            }
            if (!fsync($handle)) {
                throw new ValidationException($temporaryPath, null, $findingId, 'cannot sync temporary finding file');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            $this->removeTemporaryFile($temporaryPath, $findingId, $throwable);
            throw $throwable;
        }

        if (!fclose($handle)) {
            $exception = new ValidationException($temporaryPath, null, $findingId, 'cannot close temporary finding file');
            $this->removeTemporaryFile($temporaryPath, $findingId, $exception);
            throw $exception;
        }

        if (!rename($temporaryPath, $path)) {
            $exception = new ValidationException($path, null, $findingId, 'cannot atomically replace finding file');
            $this->removeTemporaryFile($temporaryPath, $findingId, $exception);
            throw $exception;
        }
    }

    private function removeTemporaryFile(string $path, string $findingId, Throwable $cause): void
    {
        if (!is_file($path)) {
            return;
        }
        if (unlink($path)) {
            return;
        }

        throw new ValidationException(
            $path,
            null,
            $findingId,
            'cannot remove temporary finding file after failure: ' . $cause->getMessage(),
        );
    }
}
