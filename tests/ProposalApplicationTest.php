<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalRepository;
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
        mkdir($this->root . '/skills', 0777, true);

        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');

        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['status'] = 'approved';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposal));

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

    public function testMarksAppliedOnlyWhenCanonicalTargetMatchesEvidence(): void
    {
        $target = $this->root . '/skills/agent-learning-cli.md';
        file_put_contents($target, 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
        $validationFile = $this->validationFile([
            'tests_passed' => true,
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'target_content_hash' => hash_file('sha256', $target),
        ]);

        $manager = new ProposalTransitionManager();
        $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);

        self::assertFileDoesNotExist($this->root . '/proposals/approved/proposal.2026-06-08.001.json');
        $appliedPath = $this->root . '/proposals/applied/proposal.2026-06-08.001.json';
        self::assertFileExists($appliedPath);

        $appliedProposal = json_decode((string) file_get_contents($appliedPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('applied', $appliedProposal['status']);
        self::assertSame('skills/agent-learning-cli.md', $appliedProposal['applied_validation']['target_source_ref']);
        self::assertSame(hash_file('sha256', $target), $appliedProposal['applied_validation']['target_content_hash']);

        $decisions = file($this->root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($decisions);
        self::assertCount(2, $decisions);
        self::assertStringContainsString('"status":"applied"', $decisions[1]);
        self::assertStringContainsString('"commit":"commit123"', $decisions[1]);
        self::assertStringContainsString('"target_source_ref":"skills/agent-learning-cli.md"', $decisions[1]);
    }

    public function testApplyRollsBackWhenPhysicalTargetProofIsMissing(): void
    {
        $validationFile = $this->validationFile(['tests_passed' => true]);
        $manager = new ProposalTransitionManager();

        try {
            $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
            self::fail('Expected canonical-target validation to reject the apply transition.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('target_source_ref', $exception->getMessage());
        }

        self::assertFileExists($this->root . '/proposals/approved/proposal.2026-06-08.001.json');
        self::assertFileDoesNotExist($this->root . '/proposals/applied/proposal.2026-06-08.001.json');
        $decisions = file($this->root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($decisions);
        self::assertCount(1, $decisions);
    }

    public function testApplyRollsBackWhenTargetHashDoesNotMatch(): void
    {
        $target = $this->root . '/skills/agent-learning-cli.md';
        file_put_contents($target, 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
        $validationFile = $this->validationFile([
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'target_content_hash' => str_repeat('0', 64),
        ]);

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('target_content_hash does not match target file');
        $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testApplyRollsBackWhenReplacementWordingDidNotLand(): void
    {
        $target = $this->root . '/skills/agent-learning-cli.md';
        file_put_contents($target, 'Unrelated target contents.');
        $validationFile = $this->validationFile([
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'target_content_hash' => hash_file('sha256', $target),
        ]);

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('replacement guidance wording is not present');
        $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validationFile);
    }

    public function testRejectsNewApplicationUsingLegacyFileTargetType(): void
    {
        $proposalPath = $this->root . '/proposals/approved/proposal.2026-06-08.001.json';
        $proposal = json_decode((string) file_get_contents($proposalPath), true, 512, JSON_THROW_ON_ERROR);
        $proposal['target_type'] = 'file';
        file_put_contents($proposalPath, json_encode($proposal, JSON_THROW_ON_ERROR));

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('target_type=memory, skill, or constraint');
        $manager->apply($this->root, 'proposal.2026-06-08.001', 'lars', 'commit123', $this->validationFile([]));
    }

    public function testLegacyAppliedFileProposalRemainsLoadable(): void
    {
        $approvedPath = $this->root . '/proposals/approved/proposal.2026-06-08.001.json';
        $proposal = json_decode((string) file_get_contents($approvedPath), true, 512, JSON_THROW_ON_ERROR);
        unlink($approvedPath);
        $proposal['status'] = 'applied';
        $proposal['target_type'] = 'file';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        $proposal['applied_by'] = 'maintainer';
        $proposal['applied_at'] = '2026-06-08T13:01:00+00:00';
        file_put_contents(
            $this->root . '/proposals/applied/proposal.2026-06-08.001.json',
            json_encode($proposal, JSON_THROW_ON_ERROR),
        );

        $proposals = (new ProposalRepository())->loadAll($this->root, []);

        self::assertSame('file', $proposals['proposal.2026-06-08.001']->targetType);
    }

    public function testLegacyAppliedMemoryProposalWithoutPhysicalProofRemainsLoadable(): void
    {
        $approvedPath = $this->root . '/proposals/approved/proposal.2026-06-08.001.json';
        $proposal = json_decode((string) file_get_contents($approvedPath), true, 512, JSON_THROW_ON_ERROR);
        unlink($approvedPath);
        $proposal['status'] = 'applied';
        $proposal['target_type'] = 'memory';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        $proposal['applied_by'] = 'maintainer';
        $proposal['applied_at'] = '2026-08-08T23:59:59+00:00';
        file_put_contents(
            $this->root . '/proposals/applied/proposal.2026-06-08.001.json',
            json_encode($proposal, JSON_THROW_ON_ERROR),
        );

        $proposals = (new ProposalRepository())->loadAll($this->root, []);

        self::assertSame('memory', $proposals['proposal.2026-06-08.001']->targetType);
    }

    public function testCannotApplyCandidateProposal(): void
    {
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['id'] = 'proposal.candidate.001';
        $proposal['status'] = 'candidate';
        file_put_contents($this->root . '/proposals/candidate/proposal.candidate.001.json', json_encode($proposal));

        $validationFile = $this->validationFile(['tests_passed' => true]);

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal is not approved');
        $manager->apply($this->root, 'proposal.candidate.001', 'lars', 'commit123', $validationFile);
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
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
