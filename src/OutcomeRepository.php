<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Repository for versioned guidance outcome records stored in history/outcomes.jsonl.
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
     * @return list<array<string, mixed>>
     * @throws ValidationException
     */
    public function loadAll(string $root): array
    {
        $path = $root . '/history/outcomes.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $records = $this->jsonlValidator->parseFile($path);
        foreach ($records as $index => $record) {
            $recordId = is_string($record['id'] ?? null) ? $record['id'] : null;
            if ($recordId !== null && str_starts_with($recordId, 'outcome.')) {
                throw new ValidationException(
                    $path,
                    $index + 1,
                    $recordId,
                    'legacy outcome.* records are unsupported after the pre-1.0 cut; only versioned guidance-outcome.* records are accepted',
                );
            }

            $this->guidanceOutcomeEventParser->parse($record, $path, $index + 1);
        }

        return $records;
    }

    /**
     * Record one current, versioned guidance outcome event.
     *
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
                'legacy outcome.* records are unsupported after the pre-1.0 cut; only versioned guidance-outcome.* records are accepted',
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
}
