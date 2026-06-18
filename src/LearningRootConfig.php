<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningRootConfig
{
    public function __construct(
        public string $root,
        public string $projectRoot,
        public string $constraintGenerationDirectory,
        public string $activeConstraintsDirectory,
    ) {
    }
}
