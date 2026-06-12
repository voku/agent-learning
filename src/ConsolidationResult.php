<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Interface representing a structured consolidation result.
 */
interface ConsolidationResult
{
    public function getAction(): Action;

    /**
     * @return list<string>
     */
    public function getSourceFindings(): array;

    public function getReason(): string;

    /**
     * @return list<string>
     */
    public function getRemainingUncertainty(): array;
}
