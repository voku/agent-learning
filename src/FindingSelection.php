<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;

final readonly class FindingSelection
{
    /**
     * @param list<non-empty-string> $findingIds
     * @param list<non-empty-string> $taskIds
     * @param list<non-empty-string> $scopes
     */
    public function __construct(
        public array $findingIds,
        public array $taskIds,
        public array $scopes,
        public ?DateTimeImmutable $since,
        public ?DateTimeImmutable $until,
    ) {
    }

    public function hasSelectors(): bool
    {
        return $this->findingIds !== []
            || $this->taskIds !== []
            || $this->scopes !== []
            || $this->since instanceof DateTimeImmutable
            || $this->until instanceof DateTimeImmutable;
    }

    public function label(): string
    {
        $parts = [];
        foreach ($this->findingIds as $findingId) {
            $parts[] = 'finding=' . $findingId;
        }
        foreach ($this->taskIds as $taskId) {
            $parts[] = 'task=' . $taskId;
        }
        foreach ($this->scopes as $scope) {
            $parts[] = 'scope=' . $scope;
        }
        if ($this->since instanceof DateTimeImmutable) {
            $parts[] = 'since=' . $this->since->format('Y-m-d');
        }
        if ($this->until instanceof DateTimeImmutable) {
            $parts[] = 'until=' . $this->until->format('Y-m-d');
        }

        return implode(', ', $parts);
    }
}
