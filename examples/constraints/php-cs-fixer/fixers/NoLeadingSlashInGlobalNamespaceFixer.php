<?php

declare(strict_types=1);

namespace App\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Classes referenced in the global namespace do not require leading slashes.
 */
final class NoLeadingSlashInGlobalNamespaceFixer extends AbstractFixer
{
    #[\Override]
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Classes in the global namespace cannot contain leading slashes.',
            [
                new CodeSample(
                    <<<'PHP'
                    <?php
                    $x = new \DateTime();
                    PHP
                ),
            ]
        );
    }

    #[\Override]
    public function getName(): string
    {
        return 'App/no_leading_slash_in_global_namespace';
    }

    #[\Override]
    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_NS_SEPARATOR);
    }

    #[\Override]
    public function isRisky(): bool
    {
        return false;
    }

    #[\Override]
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $index = 0;
        while (++$index < $tokens->count()) {
            $index = $this->skipNamespacedCode($tokens, $index);

            if (!$this->isLeadingSlashToRemove($tokens, $index)) {
                continue;
            }

            $tokens->clearTokenAndMergeSurroundingWhitespace($index);
        }
    }

    private function isLeadingSlashToRemove(Tokens $tokens, int $index): bool
    {
        if (!$tokens[$index]->isGivenKind(\T_NS_SEPARATOR)) {
            return false;
        }

        $prevIndex = $tokens->getPrevMeaningfulToken($index);
        if ($prevIndex === null) {
            return false;
        }

        $nextIndex = $tokens->getNextMeaningfulToken($index);
        if ($nextIndex === null) {
            return false;
        }

        $nextNextIndex = $tokens->getNextMeaningfulToken($nextIndex);
        if ($nextNextIndex !== null && $tokens[$nextNextIndex]->isGivenKind(\T_NS_SEPARATOR)) {
            return false; // Multi-segment namespace, keep leading slash if present
        }

        if ($tokens[$prevIndex]->isGivenKind([\T_NEW, CT::T_NULLABLE_TYPE, CT::T_TYPE_COLON])) {
            return true;
        }

        return false;
    }

    private function skipNamespacedCode(Tokens $tokens, int $index): int
    {
        if (!$tokens[$index]->isGivenKind(\T_NAMESPACE)) {
            return $index;
        }

        $nextIndex = $tokens->getNextMeaningfulToken($index);
        if ($nextIndex === null) {
            return $index;
        }

        if ($tokens[$nextIndex]->equals('{')) {
            return $nextIndex;
        }

        $nextIndex = $tokens->getNextTokenOfKind($index, ['{', ';']);
        if ($nextIndex === null) {
            return $tokens->count() - 1;
        }

        if ($tokens[$nextIndex]->equals(';')) {
            return $tokens->count() - 1;
        }

        return $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $nextIndex);
    }
}
