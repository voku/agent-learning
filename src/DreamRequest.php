<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use InvalidArgumentException;

/**
 * What a Dream run should evaluate, and whether it may write anything.
 *
 * Dream is read-only unless `$writeCandidates` is true, and even then it only
 * writes candidate Proposals: nothing it produces becomes active guidance
 * without the existing human review path.
 */
final readonly class DreamRequest
{
    public function __construct(
        public string $learningRoot,
        public ?string $projectRoot = null,
        public ?string $taskIdPattern = null,
        public int $reviewHorizonDays = 90,
        public bool $writeCandidates = false,
    ) {
        if ($this->learningRoot === '') {
            throw new InvalidArgumentException('Dream needs a learning root.');
        }
        if ($this->reviewHorizonDays < 1) {
            throw new InvalidArgumentException('Dream review horizon must be at least one day.');
        }
    }
}
