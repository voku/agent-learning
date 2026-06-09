<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $filePath,
        public readonly ?int $lineNumber,
        public readonly ?string $recordId,
        string $reason,
    ) {
        $parts = [$filePath];
        if ($lineNumber !== null) {
            $parts[] = 'line ' . $lineNumber;
        }
        if ($recordId !== null && $recordId !== '') {
            $parts[] = 'record ' . $recordId;
        }

        parent::__construct(implode(': ', $parts) . ': ' . $reason);
    }
}
