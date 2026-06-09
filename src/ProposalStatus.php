<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum ProposalStatus: string
{
    case CANDIDATE = 'candidate';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case APPLIED = 'applied';
}
