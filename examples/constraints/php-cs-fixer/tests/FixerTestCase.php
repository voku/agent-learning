<?php

declare(strict_types=1);

namespace App\Fixer\Tests;

use App\Fixer\ForbiddenNativeStringFunctionFixer;
use App\Fixer\NoLeadingSlashInGlobalNamespaceFixer;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Demonstrates test automation for custom PHP-CS-Fixer fixers.
 */
final class FixerTestCase extends TestCase
{
    public function testForbiddenNativeStringFunctionFixerTransformsCalls(): void
    {
        $input = <<<'PHP'
            <?php
            if (str_starts_with($haystack, 'prefix')) {}
            if (str_contains($haystack, 'needle')) {}
            if (str_ends_with($haystack, 'suffix')) {}
            PHP;

        $expected = <<<'PHP'
            <?php
            if (app_str_starts_with($haystack, 'prefix')) {}
            if (app_str_contains($haystack, 'needle')) {}
            if (app_str_ends_with($haystack, 'suffix')) {}
            PHP;

        self::assertSame($expected, $this->applyFixer(new ForbiddenNativeStringFunctionFixer(), $input));
    }

    public function testForbiddenNativeStringFunctionFixerDoesNotTouchMethods(): void
    {
        $input = <<<'PHP'
            <?php
            $service->str_starts_with($haystack, 'prefix');
            PHP;

        self::assertSame($input, $this->applyFixer(new ForbiddenNativeStringFunctionFixer(), $input));
    }

    public function testNoLeadingSlashInGlobalNamespaceFixerRemovesSlash(): void
    {
        $input = <<<'PHP'
            <?php
            $time = new \DateTime();
            PHP;

        $expected = <<<'PHP'
            <?php
            $time = new DateTime();
            PHP;

        self::assertSame($expected, $this->applyFixer(new NoLeadingSlashInGlobalNamespaceFixer(), $input));
    }

    private function applyFixer(FixerInterface $fixer, string $source): string
    {
        $tokens = Tokens::fromCode($source);
        if ($fixer->isCandidate($tokens)) {
            $fixer->fix(new SplFileInfo(__FILE__), $tokens);
        }

        return $tokens->generateCode();
    }
}
