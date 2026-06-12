<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Supported guidance types.
 */
enum GuidanceType: string
{
    case MEMORY = 'memory';
    case SKILL = 'skill';
    case CONSTRAINT = 'constraint';
}
