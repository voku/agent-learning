<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum LearningClassification: string
{
    case CREATE_SKILL = 'CREATE_SKILL';
    case UPDATE_SKILL = 'UPDATE_SKILL';
    case ADD_LEARNING_NOTE = 'ADD_LEARNING_NOTE';
    case NO_DURABLE_LEARNING = 'NO_DURABLE_LEARNING';
    case IGNORE = 'IGNORE';
}
