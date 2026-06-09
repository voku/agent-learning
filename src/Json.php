<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use JsonException;

final class Json
{
    /**
     * @return array<string, mixed>
     */
    public function decodeObjectFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new ValidationException($path, null, null, 'file is not readable');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ValidationException($path, null, null, 'malformed JSON: ' . $exception->getMessage());
        }

        if (!is_array($decoded)) {
            throw new ValidationException($path, null, null, 'JSON root must be an object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
