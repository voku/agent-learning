<?php

declare(strict_types=1);

namespace App\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Rewrites forbidden native string function calls to project-standard alternatives
 * where parameter signatures match exactly.
 */
final class ForbiddenNativeStringFunctionFixer extends AbstractFixer
{
    private const REPLACEMENTS = [
        'str_starts_with' => 'app_str_starts_with',
        'str_contains'    => 'app_str_contains',
        'str_ends_with'   => 'app_str_ends_with',
    ];

    #[\Override]
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Replace forbidden native string functions with project-standard unicode-safe helpers.',
            [
                new CodeSample(
                    <<<'PHP'
                    <?php
                    if (str_starts_with($haystack, $needle)) {}
                    if (str_contains($haystack, $needle)) {}
                    PHP
                ),
            ]
        );
    }

    #[\Override]
    public function getName(): string
    {
        return 'App/forbidden_native_string_function';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 0;
    }

    #[\Override]
    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_STRING);
    }

    #[\Override]
    public function isRisky(): bool
    {
        return false;
    }

    #[\Override]
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $functionsAnalyzer = new FunctionsAnalyzer();

        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(\T_STRING)) {
                continue;
            }

            $content = strtolower($tokens[$index]->getContent());
            if (!isset(self::REPLACEMENTS[$content])) {
                continue;
            }

            // Guard: must be a global function call (not a method call or class constant)
            if (!$functionsAnalyzer->isGlobalFunctionCall($tokens, $index)) {
                continue;
            }

            $tokens[$index] = new Token([\T_STRING, self::REPLACEMENTS[$content]]);
        }
    }
}
