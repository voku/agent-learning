<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class EvidenceValidator
{
    /**
     * @var list<string>
     */
    private const array TYPES = [
        'file_reference',
        'commit',
        'test_result',
        'phpstan_result',
        'review_comment',
        'issue_reference',
        'schema_reference',
        'runtime_observation',
        'manual_verification',
    ];

    /**
     * @param list<array<string, mixed>> $evidence
     */
    public function validate(array $evidence, string $file, ?int $line, string $recordId): void
    {
        if ($evidence === []) {
            throw new ValidationException($file, $line, $recordId, 'finding requires evidence');
        }

        foreach ($evidence as $index => $item) {
            $type = $item['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new ValidationException($file, $line, $recordId, 'unsupported evidence type at index ' . $index);
            }

            if ($type === 'file_reference') {
                $this->requireString($item, 'path', $file, $line, $recordId, $index);
                $lineValue = $item['line'] ?? null;
                if (!is_int($lineValue) || $lineValue < 1) {
                    throw new ValidationException($file, $line, $recordId, 'file_reference evidence requires positive integer line at index ' . $index);
                }

                continue;
            }

            if ($type === 'test_result' || $type === 'phpstan_result') {
                $this->requireString($item, 'command', $file, $line, $recordId, $index);
                $this->requireString($item, 'summary', $file, $line, $recordId, $index);

                continue;
            }

            $field = match ($type) {
                'commit' => 'commit',
                'review_comment' => 'reference',
                'issue_reference' => 'issue',
                default => 'summary',
            };
            $this->requireString($item, $field, $file, $line, $recordId, $index);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requireString(array $item, string $field, string $file, ?int $line, string $recordId, int $index): void
    {
        $value = $item[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($file, $line, $recordId, 'evidence index ' . $index . ' requires non-empty string field: ' . $field);
        }
    }
}
