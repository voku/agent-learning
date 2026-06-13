<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum FalsePositiveRisk: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case UNKNOWN = 'unknown';
}
