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
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new ValidationException($path, null, $finding->id, 'finding file already exists or cannot be created');
        }

        try {
            $offset = 0;
            $length = strlen($encoded);
            while ($offset < $length) {
                $written = fwrite($handle, substr($encoded, $offset));
                if ($written === false || $written === 0) {
                    throw new ValidationException($path, null, $finding->id, 'cannot write finding file');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new ValidationException($path, null, $finding->id, 'cannot flush finding file');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            if (is_file($path)) {
                unlink($path);
            }

            throw $throwable;
        }

        if (!fclose($handle)) {
            if (is_file($path)) {
                unlink($path);
            }
            throw new ValidationException($path, null, $finding->id, 'cannot close finding file after write');
        }

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
}
