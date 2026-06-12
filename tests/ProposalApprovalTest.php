<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

final class ProposalApprovalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-approval-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/approved', 0777, true);
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

    public function testApprovesProposalSuccessfully(): void
    {
        $manager = new ProposalTransitionManager();
        $manager->approve($this->root, 'proposal.2026-06-08.001', 'lars');

        self::assertFileDoesNotExist($this->root . '/proposals/candidate/proposal.2026-06-08.001.json');
        self::assertFileExists($this->root . '/proposals/approved/proposal.2026-06-08.001.json');

        // check decisions.jsonl
        self::assertFileExists($this->root . '/history/decisions.jsonl');
        $decisions = file_get_contents($this->root . '/history/decisions.jsonl');
        self::assertIsString($decisions);
        self::assertStringContainsString('approved', $decisions);
        self::assertStringContainsString('proposal.2026-06-08.001', $decisions);
    }

    public function testApproveNoDurableLearningProposalThrows(): void
    {
        // Set action of the candidate proposal to NO_DURABLE_LEARNING
        $proposal = json_decode((string)file_get_contents($this->root . '/proposals/candidate/proposal.2026-06-08.001.json'), true);
        $proposal['action'] = 'NO_DURABLE_LEARNING';
        // Clean fields disallowed for NO_DURABLE_LEARNING or make it match NO_DURABLE_LEARNING schema
        $proposal['existing_guidance_id'] = 'some-guidance-id';
        unset($proposal['target_type'], $proposal['target'], $proposal['scope'], $proposal['boundary'], $proposal['validation']);
        file_put_contents($this->root . '/proposals/candidate/proposal.2026-06-08.001.json', json_encode($proposal));

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal with action NO_DURABLE_LEARNING cannot be approved');
        $manager->approve($this->root, 'proposal.2026-06-08.001', 'lars');
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
