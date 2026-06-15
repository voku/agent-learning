<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class ConstraintLoopResult
{
    public function __construct(
        public string $proposalId,
        public string $generationPackageDir,
        public string $manifestPath,
        public bool $approvedCandidate,
        public bool $markedApplied,
    ) {
    }
}
