<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Repository for outcome records stored in history/outcomes.jsonl.
 *
 * The older `outcome.*` summary shape remains readable as legacy evidence, but
 * new writes use only the versioned `guidance-outcome.*` event contract.
 */
final class OutcomeRepository
{
    public function __construct(
        private readonly JsonlValidator $jsonlValidator = new JsonlValidator(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
        private readonly GuidanceOutcomeEventParser $guidanceOutcomeEventParser = new GuidanceOutcomeEventParser(),
    ) {
    }

    /**
     * Load all outcome records, including read-only legacy `outcome.*` history.
     *
     * @param string                  $root
     * @param array<string, Proposal> $proposalsById Optional list of known proposals for legacy reference checking.
     * @return list<array<string, mixed>>
     * @throws ValidationException
     */
    public function loadAll(string $root, array $proposalsById = []): array
    {
        $path = $root . '/history/outcomes.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $records = $this->jsonlValidator->parseFile($path);
        foreach ($records as $index => $record) {
            $lineNumber = $index + 1;
            $id = $record['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw new ValidationException($path, $lineNumber, null, 'missing or empty outcome id');
            }

            if (str_starts_with($id, 'guidance-outcome.')) {
                $this->guidanceOutcomeEventParser->parse($record, $path, $lineNumber);
                continue;
            }

            $this->validateLegacyOutcomeRecord($record, $path, $lineNumber, $id, $proposalsById);
        }

        return $records;
    }

    /**
     * Record one current, versioned guidance outcome event.
     *
     * Historical `outcome.*` summaries are deliberately read-only. Keeping the
     * legacy writer here would let callers append durable evidence whose format
     * has no schema discriminator while every current event already has one.
     *
     * @param string               $root
     * @param array<string, mixed> $record
     * @throws ValidationException
     */
    public function record(string $root, array $record): void
    {
        $path = $root . '/history/outcomes.jsonl';
        $recordId = is_string($record['id'] ?? null) ? $record['id'] : null;
        if ($recordId !== null && str_starts_with($recordId, 'outcome.')) {
            throw new ValidationException(
                $path,
                null,
                $recordId,
                'legacy outcome.* records are read-only compatibility; new writes require a versioned guidance-outcome.* record',
            );
        }

        $this->guidanceOutcomeEventParser->parse($record, $path);

        $all = $this->loadAll($root);
        foreach ($all as $existing) {
            if (($existing['id'] ?? null) === $recordId) {
                throw new ValidationException($path, null, $recordId, 'duplicate outcome ID');
            }
        }

        $this->redactionGuard->assertSafeValue($record, $path, null, $recordId);

        if (!is_dir(dirname($path))) {
            if (!mkdir($pathDir = dirname($path), 0777, true) && !is_dir($pathDir)) {
                throw new ValidationException($path, null, null, 'cannot create outcomes history directory');
            }
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $line, FILE_APPEND);
    }

    /**
     * Validate the historical unversioned `outcome.*` summary shape.
     *
     * This is intentionally a read-compatibility path only. New records are
     * written through the versioned guidance-outcome event contract above.
     *
     * @param array<string, mixed>    $record
     * @param string                  $file
     * @param int|null                $line
     * @param string|null             $recordId
     * @param array<string, Proposal> $proposalsById
     */
    private function validateLegacyOutcomeRecord(array $record, string $file, ?int $line, ?string $recordId, array $proposalsById = []): void
    {
        $id = $record['id'] ?? null;
        if (!is_string($id) || preg_match('/^outcome\.\d{4}-\d{2}-\d{2}\.\d{3}$/', $id) !== 1) {
            throw new ValidationException($file, $line, $recordId, 'outcome id must match outcome.YYYY-MM-DD.NNN');
        }

        $taskId = $record['task_id'] ?? null;
        if (!is_string($taskId) || trim($taskId) === '') {
            throw new ValidationException($file, $line, $recordId, 'outcome requires non-empty task_id');
        }

        $appliedProposals = $record['applied_proposals'] ?? null;
        if (!is_array($appliedProposals)) {
            throw new ValidationException($file, $line, $recordId, 'applied_proposals must be an array');
        }
        foreach ($appliedProposals as $pId) {
            if (!is_string($pId) || trim($pId) === '') {
                throw new ValidationException($file, $line, $recordId, 'applied_proposals must contain only non-empty strings');
            }
            if ($proposalsById !== [] && !isset($proposalsById[$pId])) {
                throw new ValidationException($file, $line, $recordId, 'outcome references unknown proposal: ' . $pId);
            }
        }

        $guidanceUsed = $record['guidance_used'] ?? null;
        if (!is_array($guidanceUsed)) {
            throw new ValidationException($file, $line, $recordId, 'guidance_used must be an array');
        }
        foreach ($guidanceUsed as $gId) {
            if (!is_string($gId) || trim($gId) === '') {
                throw new ValidationException($file, $line, $recordId, 'guidance_used must contain only non-empty strings');
            }
        }

        $result = $record['result'] ?? null;
        $allowedResults = [
            'successful',
            'partially_successful',
            'failed',
            'unknown',
            'violation_detected',
            'false_positive',
            'rule_bypassed',
            'rule_suppressed',
            'rule_disabled',
            'no_violation_observed',
        ];
        if (!is_string($result) || !in_array($result, $allowedResults, true)) {
            throw new ValidationException($file, $line, $recordId, 'unsupported outcome result: ' . var_export($result, true));
        }

        if (isset($record['recorded_at']) && DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $record['recorded_at']) === false) {
            throw new ValidationException($file, $line, $recordId, 'malformed timestamp field: recorded_at');
        }
    }
}
