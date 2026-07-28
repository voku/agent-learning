<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum ConstraintEngine: string
{
    case PHPSTAN = 'phpstan';
    case PHPCS = 'phpcs';
    case PHP_CS_FIXER = 'php_cs_fixer';
    case TEST = 'test';
    case CI = 'ci';
}
