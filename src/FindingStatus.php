<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum FindingStatus: string
{
    case CANDIDATE = 'candidate';
    case VALIDATED = 'validated';
    case INVALIDATED = 'invalidated';
    case REJECTED = 'rejected';
    case SUPERSEDED = 'superseded';
    case CONSOLIDATED = 'consolidated';
    case ARCHIVED = 'archived';
}
