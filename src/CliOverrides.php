<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class CliOverrides
{
    public function __construct(
        public ?string $root = null,
        public ?string $projectRoot = null,
        public ?string $constraintGenerationDirectory = null,
        public ?string $activeConstraintsDirectory = null,
    ) {
    }
}
