<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Creates one validated Finding without exposing its storage schema to consumers.
 */
final readonly class FindingCreator
{
    public function __construct(
        private RecordIdGenerator $idGenerator = new RecordIdGenerator(),
        private FindingParser $parser = new FindingParser(),
        private FindingLifecycle $lifecycle = new FindingLifecycle(),
    ) {
    }

    /**
     * @param list<string>               $scope
     * @param list<array<string, mixed>> $evidence
     */
    public function createValidated(
        string $root,
        string $taskId,
        string $session,
        string $createdBy,
        array $scope,
        string $observation,
        array $evidence,
        string $hypothesis,
        string $validatedConclusion,
        string $confidence,
        string $sensitivity,
        ?string $id = null,
        ?string $taskIdPattern = null,
        ?LearningClassification $classification = null,
        ?string $patternKey = null,
        ?ValidationCase $validationCase = null,
    ): FindingCreationResult {
        $id ??= $this->idGenerator->generate('finding');
        $directory = $root . '/findings/' . $this->lifecycle->directoryFor(FindingStatus::VALIDATED);
        $path = $directory . '/' . $id . '.json';
        $raw = [
            'id' => $id,
            'task_id' => $taskId,
            'session' => $session,
            'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'created_by' => $createdBy,
            'scope' => $scope,
            'observation' => $observation,
            'evidence' => $evidence,
            'hypothesis' => $hypothesis,
            'validated_conclusion' => $validatedConclusion,
            'confidence' => $confidence,
            'validation_status' => 'validated',
            'status' => FindingStatus::VALIDATED->value,
            'sensitivity' => $sensitivity,
        ];
        if ($classification instanceof LearningClassification) {
            $raw['classification'] = $classification->value;
        }
        if ($patternKey !== null) {
            $raw['pattern_key'] = $patternKey;
        }
        if ($validationCase instanceof ValidationCase) {
            $raw['validation_case'] = $validationCase->toArray();
        }

        $finding = $this->parser->parseRecord($raw, $path);
        $validator = $taskIdPattern === null
            ? new FindingValidator()
            : new FindingValidator(taskIdPattern: $taskIdPattern);
        $validator->validate($finding, $path);
        $this->assertIdDoesNotExist($root, $finding->id);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ValidationException($directory, null, $finding->id, 'cannot create findings/validated directory');
        }

        $encoded = json_encode(
            $finding->raw,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->publishAtomically($directory, $path, $encoded, $finding->id);

        return new FindingCreationResult($finding, $path);
    }

    private function assertIdDoesNotExist(string $root, string $id): void
    {
        foreach ($this->lifecycle->findingFiles($root) as $path) {
            $existing = $this->parser->parseFile($path);
            if ($existing->id === $id) {
                throw new ValidationException($path, null, $id, 'duplicate finding ID');
            }
        }
    }

    private function publishAtomically(string $directory, string $path, string $content, string $id): void
    {
        $temporaryPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
        $handle = fopen($temporaryPath, 'xb');
        if ($handle === false) {
            throw new ValidationException($temporaryPath, null, $id, 'cannot create temporary finding file');
        }

        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new ValidationException($temporaryPath, null, $id, 'cannot write temporary finding file');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new ValidationException($temporaryPath, null, $id, 'cannot flush temporary finding file');
            }
            if (!fsync($handle)) {
                throw new ValidationException($temporaryPath, null, $id, 'cannot sync temporary finding file');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            $this->removeTemporaryFile($temporaryPath, $id, $throwable);

            throw $throwable;
        }

        if (!fclose($handle)) {
            $exception = new ValidationException($temporaryPath, null, $id, 'cannot close temporary finding file after write');
            $this->removeTemporaryFile($temporaryPath, $id, $exception);

            throw $exception;
        }

        if ($this->filesystemEntryExists($path)) {
            $exception = new ValidationException($path, null, $id, 'finding file already exists');
            $this->removeTemporaryFile($temporaryPath, $id, $exception);

            throw $exception;
        }

        if (!link($temporaryPath, $path)) {
            $reason = $this->filesystemEntryExists($path)
                ? 'finding file already exists'
                : 'cannot atomically publish finding file';
            $exception = new ValidationException($path, null, $id, $reason);
            $this->removeTemporaryFile($temporaryPath, $id, $exception);

            throw $exception;
        }

        $this->removeTemporaryFile($temporaryPath, $id);
    }

    /**
     * @phpstan-impure
     */
    private function filesystemEntryExists(string $path): bool
    {
        clearstatcache(true, $path);

        return is_link($path) || file_exists($path);
    }

    private function removeTemporaryFile(string $path, string $id, ?Throwable $cause = null): void
    {
        if (!is_file($path)) {
            return;
        }
        if (unlink($path)) {
            return;
        }

        $reason = 'cannot remove temporary finding file';
        if ($cause !== null) {
            $reason .= ' after failure: ' . $cause->getMessage();
        }

        throw new ValidationException($path, null, $id, $reason);
    }
}
