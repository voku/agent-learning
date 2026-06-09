<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use JsonException;

final class JsonlValidator
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new ValidationException($path, null, null, 'file is not readable');
        }

        $records = [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if (trim($line) === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ValidationException($path, $lineNumber, null, 'malformed JSONL: ' . $exception->getMessage());
            }
            if (!is_array($decoded)) {
                throw new ValidationException($path, $lineNumber, null, 'JSONL record must be an object');
            }

            /** @var array<string, mixed> $decoded */
            $records[] = $decoded;
        }

        return $records;
    }
}
