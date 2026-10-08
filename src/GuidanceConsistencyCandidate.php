<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * One place where the written guidance disagrees with itself or with the repository, stated as a fact.
 * It carries no verdict: whether it is a real contradiction, and what to do about it, is for a human.
 */
final readonly class GuidanceConsistencyCandidate
{
    public const string KIND_UNRESOLVED_PATH = 'unresolved_path';
    public const string KIND_DUPLICATE_WORDING = 'duplicate_wording';

    public function __construct(
        public string $kind,
        public string $sourceA,
        public ?string $sourceB,
        public string $evidence,
    ) {
    }
}
