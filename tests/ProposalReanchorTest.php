<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceTargetValidator;
use voku\AgentLearning\ProposalParser;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

/**
 * An applied memory/skill proof pins the whole target file by hash.
 *
 * A shared guidance home such as `MEMORY.md` carries many rows, so an edit to any
 * one of them - including repairing an evidence path a directory move
 * invalidated - made every applied record on that file report drift it did not
 * cause. Retiring answered a curation question nobody asked and re-applying is
 * closed to an applied record, so the proof had no way back to the truth.
 *
 * @internal
 */
final class ProposalReanchorTest extends TestCase
{
    private const string PROPOSAL_ID = 'proposal.2026-06-08.001';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-reanchor-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        copy(
            __DIR__ . '/fixtures/findings/finding.2026-06-08.001.json',
            $this->root . '/findings/validated/finding.2026-06-08.001.json',
        );

        $this->writeMemory("# Memory\n\nKeep the packaged entrypoint callable.\n");
        $this->writeAppliedProposal($this->hashMemory());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReanchorRepinsTheProofAndKeepsTheDecisionEvidence(): void
    {
        $this->writeMemory("# Memory\n\nKeep the packaged entrypoint callable.\n\nAn unrelated row moved home.\n");

        (new ProposalTransitionManager())->reanchor(
            $this->root,
            self::PROPOSAL_ID,
            'lars',
            'Repaired an evidence path in an unrelated MEMORY.md row after a repository layout move.',
        );

        $path = $this->root . '/proposals/applied/' . self::PROPOSAL_ID . '.json';
        self::assertFileExists($path, 'a re-anchored proposal stays applied where it was.');

        /** @var array<string, mixed> $record */
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $validation */
        $validation = $record['applied_validation'];

        self::assertSame('applied', $record['status']);
        self::assertSame('maintainer', $record['approved_by'], 'approval evidence must survive a proof repair.');
        self::assertSame('maintainer', $record['applied_by'], 'application evidence must survive a proof repair.');
        self::assertSame('commit123', $record['commit']);
        self::assertSame($this->hashMemory(), $validation['target_content_hash']);
        self::assertSame('lars', $validation['reanchored_by']);
        self::assertNotEmpty($validation['reanchored_at']);
        self::assertStringContainsString('layout move', (string) $validation['reanchor_reason']);

        // The repaired record is exactly what the validator accepts, which is the
        // whole point: the repository can validate again without the proof being
        // weakened into an unchecked field.
        (new AppliedGuidanceTargetValidator())->validate(
            (new ProposalParser())->parseFile($path),
            $this->root,
            $path,
        );

        $history = (string) file_get_contents($this->root . '/history/reanchored-proposals.jsonl');
        self::assertStringContainsString(self::PROPOSAL_ID, $history);
        self::assertStringContainsString('layout move', $history);
        self::assertStringContainsString($this->hashMemory(), $history);
    }

    public function testReanchorRefusesATargetThatLostTheGuidance(): void
    {
        $this->writeMemory("# Memory\n\nThe rule this proposal applied is gone.\n");

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/guidance wording is not present/');

        (new ProposalTransitionManager())->reanchor(
            $this->root,
            self::PROPOSAL_ID,
            'lars',
            'Attempted repair after the rule was deleted.',
        );
    }

    public function testATargetThatLostTheGuidanceIsLeftUntouched(): void
    {
        $before = (string) file_get_contents($this->root . '/proposals/applied/' . self::PROPOSAL_ID . '.json');
        $this->writeMemory("# Memory\n\nThe rule this proposal applied is gone.\n");

        try {
            (new ProposalTransitionManager())->reanchor($this->root, self::PROPOSAL_ID, 'lars', 'Attempted repair.');
            self::fail('re-anchoring a target without the guidance must fail.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(
            $before,
            (string) file_get_contents($this->root . '/proposals/applied/' . self::PROPOSAL_ID . '.json'),
            'a refused re-anchor must not leave a partially repaired record.',
        );
        self::assertFileDoesNotExist($this->root . '/history/reanchored-proposals.jsonl');
    }

    public function testActorAndReasonAreRequired(): void
    {
        $manager = new ProposalTransitionManager();

        try {
            $manager->reanchor($this->root, self::PROPOSAL_ID, '  ', 'A reason.');
            self::fail('an anonymous re-anchor must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('actor name must be explicit', $exception->getMessage());
        }

        try {
            $manager->reanchor($this->root, self::PROPOSAL_ID, 'lars', '   ');
            self::fail('an unexplained re-anchor must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('re-anchor reason must be explicit', $exception->getMessage());
        }
    }

    public function testOnlyAppliedGuidanceCanBeReanchored(): void
    {
        $path = $this->root . '/proposals/applied/' . self::PROPOSAL_ID . '.json';
        /** @var array<string, mixed> $record */
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $record['status'] = 'approved';
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/proposal is not applied/');

        (new ProposalTransitionManager())->reanchor($this->root, self::PROPOSAL_ID, 'lars', 'A reason.');
    }

    private function hashMemory(): string
    {
        return (string) hash_file('sha256', $this->root . '/MEMORY.md');
    }

    private function writeMemory(string $contents): void
    {
        file_put_contents($this->root . '/MEMORY.md', $contents);
    }

    private function writeAppliedProposal(string $targetHash): void
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/' . self::PROPOSAL_ID . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['action'] = 'ADD';
        $proposal['old'] = null;
        $proposal['new'] = 'Keep the packaged entrypoint callable.';
        $proposal['target_type'] = 'memory';
        $proposal['target'] = 'Packaged entrypoint';
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

        file_put_contents(
            $this->root . '/proposals/applied/' . self::PROPOSAL_ID . '.json',
            json_encode($proposal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
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
