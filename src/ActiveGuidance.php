<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Guidance available during consolidation.
 */
final readonly class ActiveGuidance
{
    /**
     * @param non-empty-string       $id
     * @param GuidanceType           $type
     * @param non-empty-string       $source
     * @param list<non-empty-string> $scope
     * @param non-empty-string       $content
     */
    public function __construct(
        public string $id,
        public GuidanceType $type,
        public string $source,
        public array $scope,
        public string $content,
    ) {
    }
}
