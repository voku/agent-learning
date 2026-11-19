<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

/**
 * The version-controlled proposal is the canonical record of its approval. The
 * history journal is a supplementary audit trail that consumers may keep out of
 * version control, so applying must work without it, never invent an approval,
 * and refuse a journal that contradicts the committed proposal.
 */
final class ProposalApprovalDurabilityTest extends TestCase
{
    private const string PROPOSAL = 'proposal.2026-06-08.001';
    private const string APPROVED_AT = '2026-06-08T13:00:00+00:00';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-approval-durability-' . bin2hex(random_bytes(8));
        $this->createLearningRoot($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAppliesACommittedApprovalWithoutLocalHistory(): void
    {
        $this->writeApprovedProposal('maintainer', self::APPROVED_AT);
        self::assertFileDoesNotExist($this->root . '/history/decisions.jsonl');

        (new ProposalTransitionManager())->apply($this->root, self::PROPOSAL, 'release-bot', 'commit123', $this->validationFile($this->root));

        self::assertFileExists($this->root . '/proposals/applied/' . self::PROPOSAL . '.json');
        $record = $this->lastDecision();
        self::assertSame('applied', $record['status']);
        // The approval is copied from the proposal, not manufactured from the applying actor or clock.
        self::assertSame('maintainer', $record['approved_by']);
        self::assertSame(self::APPROVED_AT, $record['approved_at']);
        self::assertSame('release-bot', $record['applied_by']);
    }

    public function testAppliesWithMatchingLocalHistory(): void
    {
        $this->writeApprovedProposal('maintainer', self::APPROVED_AT);
        $this->writeHistory(['status' => 'approved', 'approved_by' => 'maintainer', 'approved_at' => self::APPROVED_AT]);

        (new ProposalTransitionManager())->apply($this->root, self::PROPOSAL, 'release-bot', 'commit123', $this->validationFile($this->root));

        self::assertFileExists($this->root . '/proposals/applied/' . self::PROPOSAL . '.json');
        self::assertSame(self::APPROVED_AT, $this->lastDecision()['approved_at']);
    }

    public function testRefusesAnApprovedProposalWithoutApprovalActor(): void
    {
        $this->writeApprovedProposal(null, self::APPROVED_AT);

        $this->assertApplyRefused('approved_by and approved_at');
    }

    public function testRefusesAnApprovedProposalWithoutApprovalTimestamp(): void
    {
        $this->writeApprovedProposal('maintainer', null);

        $this->assertApplyRefused('approved_by and approved_at');
    }

    public function testRefusesAMalformedApprovalTimestamp(): void
    {
        $this->writeApprovedProposal('maintainer', 'last tuesday');

        $this->assertApplyRefused('malformed timestamp field: approved_at');
    }

    public function testRefusesHistoryThatContradictsTheCommittedApproval(): void
    {
        $this->writeApprovedProposal('maintainer', self::APPROVED_AT);
        $this->writeHistory(['status' => 'approved', 'approved_by' => 'someone-else', 'approved_at' => self::APPROVED_AT]);
        $historyBefore = (string)file_get_contents($this->root . '/history/decisions.jsonl');

        $this->assertApplyRefused('history approval contradicts the committed proposal approval');
        self::assertSame($historyBefore, file_get_contents($this->root . '/history/decisions.jsonl'));
    }

    public function testRefusesAnAppliedHistoryRecordWhoseApprovalIsTheApplyTime(): void
    {
        $this->writeApprovedProposal('maintainer', self::APPROVED_AT);
        $this->writeHistory([
            'status' => 'applied',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:30:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-06-08T13:30:00+00:00',
        ]);

        $this->assertApplyRefused('history approval contradicts the committed proposal approval');
    }

    public function testRefusesARejectionRecordForAnApprovedProposal(): void
    {
        $this->writeApprovedProposal('maintainer', self::APPROVED_AT);
        file_put_contents(
            $this->root . '/history/rejected-proposals.jsonl',
            json_encode(['id' => 'rejection.2026-06-08.001', 'proposal_id' => self::PROPOSAL, 'reason' => 'Scope too broad'], JSON_THROW_ON_ERROR) . "\n",
        );

        $this->assertApplyRefused('history rejection contradicts committed proposal status');
    }

    public function testRefusesToApplyAnUnapprovedProposal(): void
    {
        $this->writeProposal('candidate', 'proposals/candidate', null, null);

        $this->assertApplyRefused('proposal is not approved');
        self::assertFileExists($this->root . '/proposals/candidate/' . self::PROPOSAL . '.json');
    }

    /**
     * Approve through the CLI in one checkout whose history/ is ignored, commit, then
     * apply through the CLI in a fresh clone that has never seen that history.
     */
    public function testAFreshCloneAppliesAnApprovalCommittedElsewhere(): void
    {
        if (trim((string)shell_exec('command -v git')) === '') {
            self::markTestSkipped('git is required for the fresh-clone scenario.');
        }
        $origin = $this->root . '-origin';
        $clone = $this->root . '-clone';
        try {
            $this->createLearningRoot($origin . '/.agent-loop/learning');
            $this->writeProposal('candidate', 'proposals/candidate', null, null, $origin . '/.agent-loop/learning');
            file_put_contents($origin . '/.gitignore', "/.agent-loop/learning/history/\n");
            self::assertTrue(mkdir($origin . '/skills'));
            file_put_contents($origin . '/skills/agent-learning-cli.md', 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
            $this->exec(['git', 'init', '--quiet', $origin]);

            $approve = $this->cli(['proposal-approve', self::PROPOSAL, '--root', $origin . '/.agent-loop/learning', '--by', 'maintainer']);
            self::assertSame(0, $approve['exit'], $approve['output']);
            self::assertFileExists($origin . '/.agent-loop/learning/history/decisions.jsonl');
            $this->exec(['git', '-C', $origin, 'add', '-A']);
            $this->exec(['git', '-C', $origin, '-c', 'user.name=test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', 'approve']);
            $this->exec(['git', 'clone', '--quiet', $origin, $clone]);

            $learning = $clone . '/.agent-loop/learning';
            self::assertDirectoryDoesNotExist($learning . '/history');
            $applied = $this->cli(['proposal-mark-applied', self::PROPOSAL, '--root', $learning, '--by', 'release-bot', '--commit', 'commit123', '--validation', $this->validationFile($clone)]);
            self::assertSame(0, $applied['exit'], $applied['output']);
            self::assertFileExists($learning . '/proposals/applied/' . self::PROPOSAL . '.json');

            $approvedProposal = json_decode((string)file_get_contents($origin . '/.agent-loop/learning/proposals/approved/' . self::PROPOSAL . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $record = json_decode(trim((string)file_get_contents($learning . '/history/decisions.jsonl')), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($approvedProposal);
            self::assertIsArray($record);
            self::assertSame($approvedProposal['approved_by'], $record['approved_by']);
            self::assertSame($approvedProposal['approved_at'], $record['approved_at']);

            $validate = $this->cli(['validate', '--root', $learning]);
            self::assertSame(0, $validate['exit'], $validate['output']);
        } finally {
            exec('rm -rf ' . escapeshellarg($origin) . ' ' . escapeshellarg($clone));
        }
    }

    private function createLearningRoot(string $root): void
    {
        foreach (['findings/validated', 'proposals/candidate', 'proposals/approved', 'proposals/applied', 'history', 'skills'] as $directory) {
            self::assertTrue(is_dir($root . '/' . $directory) || mkdir($root . '/' . $directory, 0777, true));
        }
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $root . '/findings/validated/finding.2026-06-08.001.json');
        file_put_contents($root . '/skills/agent-learning-cli.md', 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
    }

    private function writeApprovedProposal(?string $approvedBy, ?string $approvedAt): void
    {
        $this->writeProposal('approved', 'proposals/approved', $approvedBy, $approvedAt);
    }

    private function writeProposal(string $status, string $directory, ?string $approvedBy, ?string $approvedAt, ?string $root = null): void
    {
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/' . self::PROPOSAL . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($proposal);
        $proposal['status'] = $status;
        $proposal['approved_by'] = $approvedBy;
        $proposal['approved_at'] = $approvedAt;
        file_put_contents(($root ?? $this->root) . '/' . $directory . '/' . self::PROPOSAL . '.json', json_encode($proposal, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $fields */
    private function writeHistory(array $fields): void
    {
        $record = ['id' => 'decision.2026-06-08.001', 'proposal_id' => self::PROPOSAL] + $fields;
        file_put_contents($this->root . '/history/decisions.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . "\n");
    }

    private function validationFile(string $root): string
    {
        $path = $root . '/validation-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            'tests_passed' => true,
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'target_content_hash' => hash_file('sha256', $root . '/skills/agent-learning-cli.md'),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function assertApplyRefused(string $message): void
    {
        try {
            (new ProposalTransitionManager())->apply($this->root, self::PROPOSAL, 'release-bot', 'commit123', $this->validationFile($this->root));
            self::fail('Expected the application to be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/proposals/applied/' . self::PROPOSAL . '.json');
    }

    /** @return array<string, mixed> */
    private function lastDecision(): array
    {
        $lines = file($this->root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $record = json_decode((string)end($lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $decision = [];
        foreach ($record as $key => $value) {
            $decision[(string)$key] = $value;
        }

        return $decision;
    }

    /** @param list<string> $command */
    private function exec(array $command): void
    {
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exit: int, output: string}
     */
    private function cli(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/agent-learning'], $arguments);
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
