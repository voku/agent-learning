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
     * @param list<string> $findingIds
     */
    public function record(
        string $runId,
        RunLearningDecisionStatus $decision,
        string $decidedBy,
        string $reason,
        array $findingIds = [],
        ?string $followUpRef = null,
        ?int $contractRevision = null,
        ?string $implementationSnapshot = null,
        ?string $validationEvidenceSha256 = null,
        ?string $reviewEvidenceSha256 = null,
    ): RunLearningDecision {
        $runId = $this->nonEmpty($runId, 'run_id');
        $decidedBy = $this->nonEmpty($decidedBy, 'decided_by');
        $reason = $this->nonEmpty($reason, 'reason');
        $findingIds = $this->normalizeFindingIds($findingIds);
        $followUpRef = $followUpRef === null ? null : $this->nonEmpty($followUpRef, 'follow_up_ref');
        $this->assertDecisionShape($decision, $findingIds, $followUpRef);
        [$contractRevision, $implementationSnapshot, $validationEvidenceSha256, $reviewEvidenceSha256] = $this->binding(
            $contractRevision,
            $implementationSnapshot,
            $validationEvidenceSha256,
            $reviewEvidenceSha256,
        );

        $existing = $this->find($runId);
        if ($existing !== null) {
            $sameBinding = $existing->contractRevision === $contractRevision
                && $existing->implementationSnapshot === $implementationSnapshot
                && $existing->validationEvidenceSha256 === $validationEvidenceSha256
                && $existing->reviewEvidenceSha256 === $reviewEvidenceSha256;
            $sameDecision = $existing->decision === $decision
                && $existing->decidedBy === $decidedBy
                && $existing->reason === $reason
                && $existing->findingIds === $findingIds
                && $existing->followUpRef === $followUpRef;

            if ($sameBinding && $sameDecision) {
                return $existing;
            }
            if (
                $existing->contractRevision !== null
                && $contractRevision !== null
                && $existing->contractRevision !== $contractRevision
            ) {
                throw new RuntimeException(
                    'Run ' . $runId . ' cannot move its durable learning decision from Contract revision '
                    . $existing->contractRevision . ' to revision ' . $contractRevision . '.',
                );
            }
            if ($sameBinding || $contractRevision === null) {
                throw new RuntimeException('Run ' . $runId . ' already has a different durable learning decision.');
            }

            // A changed complete evidence boundary makes the previous conclusion
            // stale. Preserve it as immutable run lineage before replacing the
            // current conclusion; changing implementation must not erase what was
            // truthfully decided for the prior snapshot.
            $this->archive($existing);
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
            $contractRevision,
            $implementationSnapshot,
            $validationEvidenceSha256,
            $reviewEvidenceSha256,
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

    /** @return list<RunLearningDecision> */
    public function all(): array
    {
        $paths = glob(rtrim($this->rootPath, '/') . '/history/run-learning/*.json') ?: [];
        sort($paths, SORT_STRING);

        $records = [];
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read run learning decision: ' . $path);
            }
            $records[] = $this->decode($contents, $path, null);
        }

        return $records;
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

    private function archive(RunLearningDecision $record): void
    {
        $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $directory = dirname($record->path) . '/archive/' . hash('sha256', $record->runId);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create run learning archive directory: ' . $directory);
        }
        $path = $directory . '/' . hash('sha256', $json) . '.json';
        if (is_file($path)) {
            return;
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to archive stale run learning decision: ' . $path);
        }
    }

    private function decode(string $json, string $path, ?string $expectedRunId): RunLearningDecision
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
        if ($expectedRunId !== null && $runId !== $expectedRunId) {
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
            if (!is_string($findingId)) {
                throw new RuntimeException('Run learning decision contains an invalid finding id in ' . $path . '.');
            }
            $findingIds[] = $findingId;
        }
        $findingIds = $this->normalizeFindingIds($findingIds);
        $followUpRefValue = $data['follow_up_ref'] ?? null;
        if ($followUpRefValue !== null && (!is_string($followUpRefValue) || trim($followUpRefValue) === '')) {
            throw new RuntimeException('Run learning decision follow_up_ref must be null or non-empty in ' . $path . '.');
        }
        $followUpRef = is_string($followUpRefValue) ? trim($followUpRefValue) : null;
        $this->assertDecisionShape($decision, $findingIds, $followUpRef);
        [$contractRevision, $implementationSnapshot, $validationEvidenceSha256, $reviewEvidenceSha256] = $this->binding(
            $this->nullablePositiveInt($data['contract_revision'] ?? null, 'contract_revision', $path),
            $this->nullableString($data['implementation_snapshot'] ?? null, 'implementation_snapshot', $path),
            $this->nullableString($data['validation_evidence_sha256'] ?? null, 'validation_evidence_sha256', $path),
            $this->nullableString($data['review_evidence_sha256'] ?? null, 'review_evidence_sha256', $path),
        );

        return new RunLearningDecision(
            $runId,
            $decision,
            $this->requiredString($data, 'decided_by', $path),
            $this->requiredString($data, 'decided_at', $path),
            $this->requiredString($data, 'reason', $path),
            $findingIds,
            $followUpRef,
            $path,
            $contractRevision,
            $implementationSnapshot,
            $validationEvidenceSha256,
            $reviewEvidenceSha256,
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
     * @return array{0: int|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function binding(
        ?int $contractRevision,
        ?string $implementationSnapshot,
        ?string $validationEvidenceSha256,
        ?string $reviewEvidenceSha256,
    ): array {
        $present = [
            $contractRevision !== null,
            $implementationSnapshot !== null,
            $validationEvidenceSha256 !== null,
            $reviewEvidenceSha256 !== null,
        ];
        if (in_array(true, $present, true) && in_array(false, $present, true)) {
            throw new RuntimeException('Run learning evidence binding must provide Contract revision, implementation snapshot, validation evidence digest, and review evidence digest together.');
        }
        if ($contractRevision === null) {
            return [null, null, null, null];
        }
        if ($contractRevision < 1) {
            throw new RuntimeException('contract_revision must be positive.');
        }

        return [
            $contractRevision,
            $this->digest((string) $implementationSnapshot, 'implementation_snapshot'),
            $this->digest((string) $validationEvidenceSha256, 'validation_evidence_sha256'),
            $this->digest((string) $reviewEvidenceSha256, 'review_evidence_sha256'),
        ];
    }

    private function digest(string $value, string $name): string
    {
        $value = trim($value);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException($name . ' must be a sha256:<64 lowercase hex> digest.');
        }

        return $value;
    }

    /**
     * @param list<string> $findingIds
     * @return list<non-empty-string>
     */
    private function normalizeFindingIds(array $findingIds): array
    {
        /** @var array<non-empty-string, non-empty-string> $normalized */
        $normalized = [];
        foreach ($findingIds as $findingId) {
            $findingId = trim($findingId);
            if ($findingId === '') {
                throw new RuntimeException('finding_id must be non-empty.');
            }
            $normalized[$findingId] = $findingId;
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
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

    private function nullableString(mixed $value, string $name, string $path): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' requires ' . $name . ' to be null or non-empty.');
        }

        return trim($value);
    }

    private function nullablePositiveInt(mixed $value, string $name, string $path): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException($path . ' requires ' . $name . ' to be null or a positive integer.');
        }

        return $value;
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
