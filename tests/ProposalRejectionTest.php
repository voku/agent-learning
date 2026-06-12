<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

final class ProposalRejectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-rejection-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/rejected', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        // Copy a validated finding fixture
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');

        // Copy candidate proposal
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['status'] = 'candidate';
        $proposal['approved_by'] = null;
        $proposal['approved_at'] = null;
        file_put_contents($this->root . '/proposals/candidate/proposal.2026-06-08.001.json', json_encode($proposal));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectsProposalSuccessfully(): void
    {
        $manager = new ProposalTransitionManager();
        $manager->reject($this->root, 'proposal.2026-06-08.001', 'lars', 'Scope too broad');

        self::assertFileDoesNotExist($this->root . '/proposals/candidate/proposal.2026-06-08.001.json');
        self::assertFileExists($this->root . '/proposals/rejected/proposal.2026-06-08.001.json');

        // check rejected-proposals.jsonl
        self::assertFileExists($this->root . '/history/rejected-proposals.jsonl');
        $rejections = file_get_contents($this->root . '/history/rejected-proposals.jsonl');
        self::assertIsString($rejections);
        self::assertStringContainsString('Scope too broad', $rejections);
        self::assertStringContainsString('proposal.2026-06-08.001', $rejections);
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
