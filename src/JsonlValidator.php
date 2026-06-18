<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class JsonlValidator
{
    public function __construct(
        private readonly JsonlRecordReader $reader = new JsonlRecordReader(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $path): array
    {
        return $this->reader->read($path);
    }
}
