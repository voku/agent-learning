<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum Detectability: string
{
    case STATIC = 'static';
    case SYNTAX_LOCAL = 'syntax_local';
    case RUNTIME = 'runtime';
    case CROSS_FILE = 'cross_file';
}
