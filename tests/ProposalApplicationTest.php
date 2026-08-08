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
        mkdir($this->root . '/proposals/retired', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/skills', 0777, true);

        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');

        $proposal = json_decode((string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
        $proposal['status'] = 'approved';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposal, JSON_THROW_ON_ERROR));

        $approvalRecord = [
            'id' => 'decision.2026-06-08.001',
            'proposal_id' => 'proposal.2026-06-08.001',
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:00:00+00:00',
        ];
        file_put_contents($this->root . '/history/decisions.jsonl', json_encode($approvalRecord, JSON_THROW_ON_ERROR) . "\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testVerifiedSkillApplyHandsOffAndRetiresProposalAtomically(): void
    {
        $target = $this->root . '/skills/agent-learning-cli.md';
        file_put_contents($target, 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
        $validationFile = $this->validationFile([
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'tests_passed' => true,
        ]);

        (new ProposalTransitionManager())->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);

        self::assertFileDoesNotExist($this->root . '/proposals/approved/proposal.2026-06-08.001.json');
        self::assertFileDoesNotExist($this->root . '/proposals/applied/proposal.2026-06-08.001.json');
        $retiredPath = $this->root . '/proposals/retired/proposal.2026-06-08.001.json';
        self::assertFileExists($retiredPath);

        $retired = json_decode((string) file_get_contents($retiredPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('retired', $retired['status']);
        self::assertSame('skills/agent-learning-cli.md', $retired['target_source_ref']);
        self::assertSame(hash_file('sha256', $target), $retired['target_content_hash']);
        self::assertSame('Call a consuming-project-only script directly.', $retired['old']);
        self::assertSame('Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.', $retired['new']);

        $decisions = file($this->root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($decisions);
        self::assertCount(2, $decisions);
        $applied = json_decode($decisions[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('applied', $applied['status']);
        self::assertSame('skills/agent-learning-cli.md', $applied['target_source_ref']);
        self::assertSame(hash_file('sha256', $target), $applied['target_content_hash']);

        $retirements = file($this->root . '/history/retired-proposals.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($retirements);
        self::assertCount(1, $retirements);
    }

    public function testApplyRequiresExplicitPhysicalTargetForMemoryOrSkill(): void
    {
        $validationFile = $this->validationFile(['tests_passed' => true]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('memory/skill apply requires target_source_ref');
        (new ProposalTransitionManager())->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testApplyRejectsTargetThatDoesNotContainReplacement(): void
    {
        file_put_contents($this->root . '/skills/agent-learning-cli.md', 'Unrelated guidance.');
        $validationFile = $this->validationFile(['target_source_ref' => 'skills/agent-learning-cli.md']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('replacement guidance wording is not present');
        (new ProposalTransitionManager())->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testApplyRejectsTargetWhenOldReplacementStillExists(): void
    {
        file_put_contents(
            $this->root . '/skills/agent-learning-cli.md',
            "Call a consuming-project-only script directly.\nCall the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.",
        );
        $validationFile = $this->validationFile(['target_source_ref' => 'skills/agent-learning-cli.md']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('replaced guidance wording is still present');
        (new ProposalTransitionManager())->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testApplyRejectsTraversalTargetReference(): void
    {
        $validationFile = $this->validationFile(['target_source_ref' => '../outside.md']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('target_source_ref must be a relative path inside the project root');
        (new ProposalTransitionManager())->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testCannotApplyCandidateProposal(): void
    {
        $proposal = json_decode((string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
        $proposal['id'] = 'proposal.candidate.001';
        $proposal['status'] = 'candidate';
        unset($proposal['approved_by'], $proposal['approved_at']);
        file_put_contents($this->root . '/proposals/candidate/proposal.candidate.001.json', json_encode($proposal, JSON_THROW_ON_ERROR));

        $validationFile = $this->validationFile(['target_source_ref' => 'skills/agent-learning-cli.md']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal is not approved');
        (new ProposalTransitionManager())->apply($this->root, 'proposal.candidate.001', 'lars', 'commit123', $validationFile);
    }

    /** @param array<string, mixed> $data */
    private function validationFile(array $data): string
    {
        $path = $this->root . '/validation-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
