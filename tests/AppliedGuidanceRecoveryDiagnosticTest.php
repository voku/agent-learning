<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceTargetValidator;
use voku\AgentLearning\ProposalParser;
use voku\AgentLearning\ValidationException;

final class AppliedGuidanceRecoveryDiagnosticTest extends TestCase
{
    private const string RULE = 'Keep the reviewed guidance in the canonical target.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/applied-guidance-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testStaleWholeFileProofPointsToTargetScopedOwnerRecoveryWhenGuidanceStillMatches(): void
    {
        $this->writeMemory(self::RULE);
        $proposalPath = $this->writeAppliedProposal((string) hash_file('sha256', $this->root . '/MEMORY.md'));

        $this->writeMemory(self::RULE, 'An unrelated row changed later.');

        try {
            (new AppliedGuidanceTargetValidator())->validate(
                (new ProposalParser())->parseFile($proposalPath),
                $this->root,
                $proposalPath,
            );
            self::fail('a stale whole-file proof must fail closed.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('target_content_hash does not match target file: MEMORY.md', $exception->getMessage());
            self::assertStringContainsString('target still satisfies this proposal\'s applied guidance', $exception->getMessage());
            self::assertStringContainsString('proposal-reanchor MEMORY.md --by ACTOR --reason TEXT', $exception->getMessage());
            self::assertStringContainsString('fails closed if any applied guidance on that file is missing', $exception->getMessage());
        }
    }

    public function testSemanticGuidanceDriftFailsBeforeReanchorIsSuggested(): void
    {
        $this->writeMemory(self::RULE);
        $proposalPath = $this->writeAppliedProposal((string) hash_file('sha256', $this->root . '/MEMORY.md'));

        $this->writeMemory('The reviewed guidance was actually removed.');

        try {
            (new AppliedGuidanceTargetValidator())->validate(
                (new ProposalParser())->parseFile($proposalPath),
                $this->root,
                $proposalPath,
            );
            self::fail('semantic drift must fail closed.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('added guidance wording is not present in target: MEMORY.md', $exception->getMessage());
            self::assertStringNotContainsString('proposal-reanchor', $exception->getMessage());
        }
    }

    private function writeAppliedProposal(string $targetHash): string
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['action'] = 'ADD';
        $proposal['target_type'] = 'memory';
        $proposal['target'] = 'memory.workflow';
        $proposal['old'] = null;
        $proposal['new'] = self::RULE;
        $proposal['status'] = 'applied';
        $proposal['applied_by'] = 'maintainer';
        $proposal['applied_at'] = '2026-08-20T14:00:00+00:00';
        $proposal['commit'] = 'commit123';
        $proposal['applied_validation'] = [
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'commit' => 'commit123',
            'target_source_ref' => 'MEMORY.md',
            'target_content_hash' => $targetHash,
            'summary' => 'Fixture application evidence.',
        ];

        $path = $this->root . '/proposal.json';
        file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function writeMemory(string ...$rows): void
    {
        file_put_contents($this->root . '/MEMORY.md', "# Memory\n\n" . implode("\n\n", $rows) . "\n");
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
