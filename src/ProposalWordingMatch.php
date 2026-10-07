<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * How much of a proposal's wording already exists in one file. Containment is
 * the share of the proposal's word 4-grams found in the file; exact is a
 * whitespace-normalised substring match.
 */
final readonly class ProposalWordingMatch
{
    public function __construct(
        public string $file,
        public int $containmentPercent,
        public bool $exact,
    ) {
    }
}
