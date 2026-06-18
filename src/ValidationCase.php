<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class ValidationCase
{
    public function __construct(
        public string $given,
        public string $when,
        public string $then,
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromOptionalRecord(array $record, string $field, string $file, ?int $line, ?string $recordId): ?self
    {
        if (!array_key_exists($field, $record) || $record[$field] === null) {
            return null;
        }

        $value = $record[$field];
        if (!is_array($value)) {
            throw new ValidationException($file, $line, $recordId, $field . ' must be an object');
        }

        /** @var array<string, mixed> $value */
        return self::fromArray($value, $field, $file, $line, $recordId);
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromArray(array $record, string $field, string $file, ?int $line, ?string $recordId): self
    {
        $given = $record['given'] ?? null;
        $when = $record['when'] ?? null;
        $then = $record['then'] ?? null;

        if (!is_string($given) || trim($given) === '') {
            throw new ValidationException($file, $line, $recordId, $field . '.given must be a non-empty string');
        }
        if (!is_string($when) || trim($when) === '') {
            throw new ValidationException($file, $line, $recordId, $field . '.when must be a non-empty string');
        }
        if (!is_string($then) || trim($then) === '') {
            throw new ValidationException($file, $line, $recordId, $field . '.then must be a non-empty string');
        }

        return new self(trim($given), trim($when), trim($then));
    }

    /**
     * @return array{given: string, when: string, then: string}
     */
    public function toArray(): array
    {
        return [
            'given' => $this->given,
            'when' => $this->when,
            'then' => $this->then,
        ];
    }
}
