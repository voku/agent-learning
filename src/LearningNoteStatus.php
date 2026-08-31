<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum LearningNoteStatus: string
{
    case ACTIVE = 'active';
    case RETIRED = 'retired';
}
