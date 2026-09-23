<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * A source other than the selected guidance that already prescribed the
 * decision credited to it. Any such source confounds the outcome: the
 * decision would plausibly have been taken without Recall.
 */
enum GuidanceOutcomeAttributionSource: string
{
    case TASK_PROMPT = 'task_prompt';
    case CONTRACT = 'contract';
    case SKILL = 'skill';
    case TEMPLATE = 'template';
    case CONSTRAINT = 'constraint';
    case REPOSITORY_DOCS = 'repository_docs';
}
