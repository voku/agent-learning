<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum Action: string
{
    case NO_DURABLE_LEARNING = 'NO_DURABLE_LEARNING';
    case ADD = 'ADD';
    case DELETE = 'DELETE';
    case REPLACE = 'REPLACE';
    case REJECT = 'REJECT';
}
