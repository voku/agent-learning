<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum RunLearningDecisionStatus: string
{
    case FINDINGS_RECORDED = 'findings_recorded';
    case NO_DURABLE_LEARNING = 'no_durable_learning';
    case FOLLOW_UP_REQUIRED = 'follow_up_required';
}
