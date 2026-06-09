<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class RecordAccess
{
    /**
     * @param array<string, mixed> $record
     */
    public function string(array $record, string $field, string $file, ?int $line, ?string $recordId): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value)) {
            throw new ValidationException($file, $line, $recordId, 'missing or invalid string field: ' . $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function optionalString(array $record, string $field, string $file, ?int $line, ?string $recordId): ?string
    {
        $value = $record[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ValidationException($file, $line, $recordId, 'invalid optional string field: ' . $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    public function stringList(array $record, string $field, string $file, ?int $line, ?string $recordId): array
    {
        $value = $record[$field] ?? null;
        if (!is_array($value)) {
            throw new ValidationException($file, $line, $recordId, 'missing or invalid list field: ' . $field);
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ValidationException($file, $line, $recordId, 'list contains non-string item: ' . $field);
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<array<string, mixed>>
     */
    public function objectList(array $record, string $field, string $file, ?int $line, ?string $recordId): array
    {
        $value = $record[$field] ?? null;
        if (!is_array($value)) {
            throw new ValidationException($file, $line, $recordId, 'missing or invalid object-list field: ' . $field);
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new ValidationException($file, $line, $recordId, 'object-list contains non-object item: ' . $field);
            }
            /** @var array<string, mixed> $item */
            $list[] = $item;
        }

        return $list;
    }
}
