<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingTransitionManager;
use voku\AgentLearning\ValidationException;

final class FindingTransitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/finding-transition-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/candidate', 0777, true);
        mkdir($this->root . '/findings/validated', 0777, true);

        // create candidate finding
        $finding = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json'), true);
        $finding['status'] = 'candidate';
        $finding['validation_status'] = 'candidate';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTransitionsFindingSuccessfully(): void
    {
        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars');

        self::assertFileDoesNotExist($this->root . '/findings/candidate/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.001.json');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/validated/finding.2026-06-08.001.json'), true);
        self::assertSame('validated', $updated['status']);
    }

    public function testForbiddenTransitionThrowsAndRollsBack(): void
    {
        // transition candidate to archived (forbidden)
        $manager = new FindingTransitionManager();
        $this->expectException(ValidationException::class);
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::ARCHIVED, 'lars');

        // File remains in candidate
        self::assertFileExists($this->root . '/findings/candidate/finding.2026-06-08.001.json');
    }

    public function testTransitionToRejected(): void
    {
        // Set correct initial validation_status for candidate (unverified)
        $finding = json_decode((string)file_get_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json'), true);
        $finding['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding));

        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::REJECTED, 'lars');

        self::assertFileDoesNotExist($this->root . '/findings/candidate/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/rejected/finding.2026-06-08.001.json');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/rejected/finding.2026-06-08.001.json'), true);
        self::assertSame('rejected', $updated['status']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
