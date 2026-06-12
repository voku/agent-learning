<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

final class ProposalApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-application-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/approved', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        // Copy a validated finding fixture
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');

        // Copy approved proposal
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['status'] = 'approved';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposal));

        // Create initial decisions.jsonl with approval
        $approvalRecord = [
            'id' => 'decision.2026-06-08.001',
            'proposal_id' => 'proposal.2026-06-08.001',
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:00:00+00:00',
        ];
        file_put_contents($this->root . '/history/decisions.jsonl', json_encode($approvalRecord) . "\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMarksAppliedSuccessfully(): void
    {
        $validationFile = $this->root . '/validation.json';
        file_put_contents($validationFile, '{"tests_passed": true}');

        $manager = new ProposalTransitionManager();
        $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);

        self::assertFileDoesNotExist($this->root . '/proposals/approved/proposal.2026-06-08.001.json');
        self::assertFileExists($this->root . '/proposals/applied/proposal.2026-06-08.001.json');

        $decisions = file($this->root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($decisions);
        self::assertCount(2, $decisions);
        self::assertStringContainsString('"status":"applied"', $decisions[1]);
        self::assertStringContainsString('"commit":"commit123"', $decisions[1]);
        self::assertStringContainsString('"tests_passed"', $decisions[1]);
    }

    public function testCannotApplyCandidateProposal(): void
    {
        // Copy candidate proposal
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['id'] = 'proposal.candidate.001';
        $proposal['status'] = 'candidate';
        file_put_contents($this->root . '/proposals/candidate/proposal.candidate.001.json', json_encode($proposal));

        $validationFile = $this->root . '/validation.json';
        file_put_contents($validationFile, '{"tests_passed": true}');

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal is not approved');
        $manager->apply($this->root, 'proposal.candidate.001', 'lars', 'commit123', $validationFile);
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
