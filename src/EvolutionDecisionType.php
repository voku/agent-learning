<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum EvolutionDecisionType: string
{
    case NO_ACTION = 'NO_ACTION';
    case PROMOTION_CANDIDATE = 'PROMOTION_CANDIDATE';
    case STALE_CANDIDATE = 'STALE_CANDIDATE';
    case REPLACEMENT_CANDIDATE = 'REPLACEMENT_CANDIDATE';
    case CONFLICT = 'CONFLICT';
}
