<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class RunLearningDecisionStore
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param list<non-empty-string> $findingIds
     */
    public function record(
        string $runId,
        RunLearningDecisionStatus $decision,
        string $decidedBy,
        string $reason,
        array $findingIds = [],
        ?string $followUpRef = null,
    ): RunLearningDecision {
        $runId = $this->nonEmpty($runId, 'run_id');
        $decidedBy = $this->nonEmpty($decidedBy, 'decided_by');
        $reason = $this->nonEmpty($reason, 'reason');
        $findingIds = $this->normalizeFindingIds($findingIds);
        $followUpRef = $followUpRef === null ? null : $this->nonEmpty($followUpRef, 'follow_up_ref');
        $this->assertDecisionShape($decision, $findingIds, $followUpRef);

        $existing = $this->find($runId);
        if ($existing !== null) {
            if (
                $existing->decision !== $decision
                || $existing->decidedBy !== $decidedBy
                || $existing->reason !== $reason
                || $existing->findingIds !== $findingIds
                || $existing->followUpRef !== $followUpRef
            ) {
                throw new RuntimeException('Run ' . $runId . ' already has a different durable learning decision.');
            }

            return $existing;
        }

        $record = new RunLearningDecision(
            $runId,
            $decision,
            $decidedBy,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $reason,
            $findingIds,
            $followUpRef,
            $this->path($runId),
        );
        $this->write($record);

        return $record;
    }

    public function find(string $runId): ?RunLearningDecision
    {
        $runId = $this->nonEmpty($runId, 'run_id');
        $path = $this->path($runId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read run learning decision: ' . $path);
        }

        return $this->decode($contents, $path, $runId);
    }

    public function path(string $runId): string
    {
        $runId = $this->nonEmpty($runId, 'run_id');

        return rtrim($this->rootPath, '/') . '/history/run-learning/' . hash('sha256', $runId) . '.json';
    }

    private function write(RunLearningDecision $record): void
    {
        $directory = dirname($record->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create run learning directory: ' . $directory);
        }
        $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $record->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $record->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to persist run learning decision: ' . $record->path);
        }
    }

    private function decode(string $json, string $path, string $expectedRunId): RunLearningDecision
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid run learning decision JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'run_learning_decision') {
            throw new RuntimeException('Unsupported run learning decision schema in ' . $path . '.');
        }

        $runId = $this->requiredString($data, 'run_id', $path);
        if ($runId !== $expectedRunId) {
            throw new RuntimeException('Run learning decision belongs to another run: ' . $path);
        }
        $decisionValue = $this->requiredString($data, 'decision', $path);
        $decision = RunLearningDecisionStatus::tryFrom($decisionValue)
            ?? throw new RuntimeException('Unknown run learning decision in ' . $path . ': ' . $decisionValue);
        $findingIdsValue = $data['finding_ids'] ?? null;
        if (!is_array($findingIdsValue)) {
            throw new RuntimeException('Run learning decision finding_ids must be an array in ' . $path . '.');
        }
        $findingIds = [];
        foreach ($findingIdsValue as $findingId) {
            if (!is_string($findingId) || trim($findingId) === '') {
                throw new RuntimeException('Run learning decision contains an invalid finding id in ' . $path . '.');
            }
            $findingIds[] = trim($findingId);
        }
        $findingIds = $this->normalizeFindingIds($findingIds);
        $followUpRefValue = $data['follow_up_ref'] ?? null;
        if ($followUpRefValue !== null && (!is_string($followUpRefValue) || trim($followUpRefValue) === '')) {
            throw new RuntimeException('Run learning decision follow_up_ref must be null or non-empty in ' . $path . '.');
        }
        $followUpRef = is_string($followUpRefValue) ? trim($followUpRefValue) : null;
        $this->assertDecisionShape($decision, $findingIds, $followUpRef);

        return new RunLearningDecision(
            $runId,
            $decision,
            $this->requiredString($data, 'decided_by', $path),
            $this->requiredString($data, 'decided_at', $path),
            $this->requiredString($data, 'reason', $path),
            $findingIds,
            $followUpRef,
            $path,
        );
    }

    /**
     * @param list<non-empty-string> $findingIds
     */
    private function assertDecisionShape(
        RunLearningDecisionStatus $decision,
        array $findingIds,
        ?string $followUpRef,
    ): void {
        if ($decision === RunLearningDecisionStatus::FINDINGS_RECORDED && $findingIds === []) {
            throw new RuntimeException('findings_recorded requires at least one finding id.');
        }
        if ($decision === RunLearningDecisionStatus::NO_DURABLE_LEARNING && ($findingIds !== [] || $followUpRef !== null)) {
            throw new RuntimeException('no_durable_learning cannot carry findings or a follow-up reference.');
        }
        if ($decision === RunLearningDecisionStatus::FOLLOW_UP_REQUIRED && $followUpRef === null) {
            throw new RuntimeException('follow_up_required requires a follow-up reference.');
        }
    }

    /**
     * @param list<non-empty-string> $findingIds
     * @return list<non-empty-string>
     */
    private function normalizeFindingIds(array $findingIds): array
    {
        $normalized = [];
        foreach ($findingIds as $findingId) {
            $findingId = $this->nonEmpty($findingId, 'finding_id');
            $normalized[$findingId] = true;
        }
        $ids = array_keys($normalized);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' requires non-empty ' . $key . '.');
        }

        return trim($value);
    }

    private function nonEmpty(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException($name . ' must be non-empty.');
        }

        return $value;
    }
}
