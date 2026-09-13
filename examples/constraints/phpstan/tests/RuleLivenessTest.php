<?php

declare(strict_types=1);

namespace App\PHPStan\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Liveness test verifying that registered custom PHPStan rules execute and
 * reliably catch invalid fixtures in the consuming project.
 */
final class RuleLivenessTest extends TestCase
{
    public function testRulesDetectViolationsInInvalidFixture(): void
    {
        $output = $this->analyseFile(__DIR__ . '/../fixtures/invalid.php');

        self::assertStringContainsString(
            'Call to AppI18n::_ contains 1 placeholder(s), but 0 parameter value(s) given.',
            $output
        );
        self::assertStringContainsString(
            'Do not hardcode machine-specific absolute host paths',
            $output
        );
        self::assertStringContainsString(
            'Switch statement does not have a "default" case.',
            $output
        );
    }

    public function testValidFixturesPassCleanly(): void
    {
        $output = $this->analyseFile(__DIR__ . '/../fixtures/valid.php');
        self::assertStringNotContainsString('Call to AppI18n::_ contains', $output);
        self::assertStringNotContainsString('Do not hardcode machine-specific', $output);
        self::assertStringNotContainsString('Switch statement does not have a "default" case', $output);
    }

    public function testBoundaryAndFalsePositiveGuardsPassCleanly(): void
    {
        $boundaryOutput = $this->analyseFile(__DIR__ . '/../fixtures/boundary.php');
        self::assertStringNotContainsString('Call to AppI18n::_ contains', $boundaryOutput);

        $falsePositiveOutput = $this->analyseFile(__DIR__ . '/../fixtures/false-positive.php');
        self::assertStringNotContainsString('Call to AppI18n::_ contains', $falsePositiveOutput);
        self::assertStringNotContainsString('Do not hardcode machine-specific', $falsePositiveOutput);
    }

    private function analyseFile(string $filePath): string
    {
        $command = sprintf(
            'vendor/bin/phpstan analyse --no-progress --error-format=raw --configuration=%s %s 2>&1',
            escapeshellarg(__DIR__ . '/../registration/phpstan.neon.dist'),
            escapeshellarg($filePath),
        );

        exec($command, $outputLines);

        return implode("\n", $outputLines);
    }
}
