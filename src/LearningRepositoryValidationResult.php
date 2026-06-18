<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningRepositoryValidationResult
{
    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @param list<array<string, mixed>> $outcomes
     */
    public function __construct(
        public string $root,
        public array $findingsById,
        public array $proposalsById,
        public array $outcomes,
    ) {
    }
}
