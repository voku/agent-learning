<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class FindingCreationResult
{
    public function __construct(
        public Finding $finding,
        public string $path,
    ) {
    }
}
