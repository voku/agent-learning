<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class HistoryProjection
{
    /**
     * @param list<array{path: string, bytes: int, sha256: string}> $sourceFiles
     */
    public function __construct(
        public string $inputDigest,
        public string $snapshot,
        public string $chronicle,
        public string $manifest,
        public int $activeGuidanceRecordCount,
        public int $archivedRecordCount,
        public array $sourceFiles,
        public int $sourceBytes,
    ) {
    }

    public function projectionBytes(): int
    {
        return strlen($this->snapshot) + strlen($this->chronicle) + strlen($this->manifest);
    }

    public function compressionRatio(): ?float
    {
        $projectionBytes = $this->projectionBytes();

        return $projectionBytes === 0 ? null : $this->sourceBytes / $projectionBytes;
    }
}
