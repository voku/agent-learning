<?php

declare(strict_types=1);

use App\Fixer\ForbiddenNativeStringFunctionFixer;
use App\Fixer\NoLeadingSlashInGlobalNamespaceFixer;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/../fixtures');

return (new Config())
    ->registerCustomFixers([
        new ForbiddenNativeStringFunctionFixer(),
        new NoLeadingSlashInGlobalNamespaceFixer(),
    ])
    ->setRules([
        '@PSR12' => true,
        'App/forbidden_native_string_function' => true,
        'App/no_leading_slash_in_global_namespace' => true,
    ])
    ->setFinder($finder);
