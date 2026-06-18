<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum OutcomeValue: string
{
    case HELPFUL = 'helpful';
    case IRRELEVANT = 'irrelevant';
    case HARMFUL = 'harmful';
    case NOT_USED = 'not_used';
    case UNKNOWN = 'unknown';
}
