<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

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
        'agent_history_reference',
    ];

    /**
     * @var list<string>
     */
    private const array AGENT_HISTORY_REFERENCE_STATUSES = [
        'found',
        'inspected',
        'rejected',
        'stale',
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

            if ($type === 'agent_history_reference') {
                $this->validateAgentHistoryReference($item, $file, $line, $recordId, $index);

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

    /**
     * @param array<string, mixed> $item
     */
    private function validateAgentHistoryReference(array $item, string $file, ?int $line, string $recordId, int $index): void
    {
        $this->requireString($item, 'source', $file, $line, $recordId, $index);
        if ($item['source'] !== 'ctx') {
            throw new ValidationException($file, $line, $recordId, 'agent_history_reference evidence requires source=ctx at index ' . $index);
        }

        $this->requireString($item, 'query', $file, $line, $recordId, $index);
        $this->requireString($item, 'retrieved_at', $file, $line, $recordId, $index);
        $this->requireString($item, 'summary', $file, $line, $recordId, $index);
        $this->requireString($item, 'verification_status', $file, $line, $recordId, $index);

        $sessionId = $item['ctx_session_id'] ?? null;
        $eventId = $item['ctx_event_id'] ?? null;
        if ((!is_string($sessionId) || trim($sessionId) === '') && (!is_string($eventId) || trim($eventId) === '')) {
            throw new ValidationException($file, $line, $recordId, 'agent_history_reference evidence requires ctx_session_id or ctx_event_id at index ' . $index);
        }

        $retrievedAt = $item['retrieved_at'];
        $parsedRetrievedAt = is_string($retrievedAt)
            ? DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $retrievedAt)
            : false;
        if ($parsedRetrievedAt === false || $this->hasTimestampParseErrors()) {
            throw new ValidationException($file, $line, $recordId, 'agent_history_reference evidence requires valid ISO timestamp retrieved_at at index ' . $index);
        }

        $verificationStatus = $item['verification_status'];
        if (!is_string($verificationStatus) || !in_array($verificationStatus, self::AGENT_HISTORY_REFERENCE_STATUSES, true)) {
            throw new ValidationException($file, $line, $recordId, 'agent_history_reference evidence has unsupported verification_status at index ' . $index);
        }

        $provider = $item['provider'] ?? null;
        if ($provider !== null && (!is_string($provider) || trim($provider) === '')) {
            throw new ValidationException($file, $line, $recordId, 'agent_history_reference evidence provider must be a non-empty string when present at index ' . $index);
        }
    }

    private function hasTimestampParseErrors(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors === false) {
            return false;
        }

        return $errors['warning_count'] > 0 || $errors['error_count'] > 0;
    }
}
