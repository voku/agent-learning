<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Repository for outcome records stored in history/outcomes.jsonl.
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
     * Load all outcome records.
     *
     * @param string                  $root
     * @param array<string, Proposal> $proposalsById Optional list of known proposals for reference checking.
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

            $this->validateOutcomeRecord($record, $path, $lineNumber, $id, $proposalsById);
        }

        return $records;
    }

    /**
     * Record a new outcome.
     *
     * @param string               $root
     * @param array<string, mixed> $record
     * @throws ValidationException
     */
    public function record(string $root, array $record): void
    {
        $path = $root . '/history/outcomes.jsonl';

        // Load proposals to validate referenced proposal IDs
        $findingsById = [];
        $findingLifecycle = new FindingLifecycle();
        $findingValidator = new FindingValidator();
        foreach ($findingLifecycle->findingFiles($root) as $file) {
            $finding = $findingValidator->validateFile($file);
            $findingsById[$finding->id] = $finding;
        }
        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);

        $this->validateOutcomeRecord($record, $path, null, is_string($record['id'] ?? null) ? $record['id'] : null, $proposalsById);

        $all = $this->loadAll($root, $proposalsById);
        foreach ($all as $existing) {
            if ($existing['id'] === $record['id']) {
                throw new ValidationException($path, null, $record['id'], 'duplicate outcome ID');
            }
        }

        $this->redactionGuard->assertSafeValue($record, $path, null, is_string($record['id'] ?? null) ? $record['id'] : null);

        if (!is_dir(dirname($path))) {
            if (!mkdir($pathDir = dirname($path), 0777, true) && !is_dir($pathDir)) {
                throw new ValidationException($path, null, null, 'cannot create outcomes history directory');
            }
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $line, FILE_APPEND);
    }

    /**
     * Generate the next outcome ID.
     *
     * @param string                 $root
     * @param DateTimeImmutable|null $date
     * @return string
     */
    public function generateOutcomeId(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'outcome.' . $dateStr . '.';

        $all = $this->loadAll($root);
        $maxNum = 0;
        foreach ($all as $existing) {
            $id = $existing['id'];
            if (str_starts_with($id, $prefix)) {
                $suffix = substr($id, strlen($prefix));
                if (is_numeric($suffix)) {
                    $maxNum = max($maxNum, (int)$suffix);
                }
            }
        }

        return $prefix . sprintf('%03d', $maxNum + 1);
    }

    /**
     * Validate outcome fields and check referenced proposals.
     *
     * @param array<string, mixed>    $record
     * @param string                  $file
     * @param int|null                $line
     * @param string|null             $recordId
     * @param array<string, Proposal> $proposalsById
     */
    private function validateOutcomeRecord(array $record, string $file, ?int $line, ?string $recordId, array $proposalsById = []): void
    {
        $id = $record['id'] ?? null;
        if (is_string($id) && str_starts_with($id, 'guidance-outcome.')) {
            $this->guidanceOutcomeEventParser->parse($record, $file, $line);

            return;
        }
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
