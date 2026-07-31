<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class DreamWarning
{
    /**
     * @param list<string> $evidenceIds
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $evidenceIds,
        public string $remediation,
    ) {
    }
}
