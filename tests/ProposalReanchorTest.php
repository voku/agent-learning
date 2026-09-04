<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceTargetValidator;
use voku\AgentLearning\DecisionHistoryValidator;
use voku\AgentLearning\ProposalParser;
use voku\AgentLearning\ProposalRepository;
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
 * The repair is scoped to the target rather than one proposal because the drift
 * belongs to the file: repairing one of several proofs would leave the root
 * invalid and could therefore never commit.
 *
 * @internal
 */
final class ProposalReanchorTest extends TestCase
{
    private const string FIRST_ID = 'proposal.2026-06-08.001';

    private const string SECOND_ID = 'proposal.2026-06-08.002';

    private const string FIRST_RULE = 'Keep the packaged entrypoint callable.';

    private const string SECOND_RULE = 'Name the owner that enforces a rule.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-reanchor-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/docs', 0777, true);

        copy(
            __DIR__ . '/fixtures/findings/finding.2026-06-08.001.json',
            $this->root . '/findings/validated/finding.2026-06-08.001.json',
        );

        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE);
        $this->writeAppliedProposal(self::FIRST_ID, self::FIRST_RULE, $this->hashMemory());
        $this->writeAppliedProposal(self::SECOND_ID, self::SECOND_RULE, $this->hashMemory());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEveryProofOnTheTargetIsRepairedAndTheDecisionEvidenceSurvives(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row moved home.');

        $repaired = (new ProposalTransitionManager())->reanchorTarget(
            $this->root,
            'MEMORY.md',
            'lars',
            'Repaired an evidence path in an unrelated MEMORY.md row after a repository layout move.',
        );

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $repaired);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
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

            // The repaired record is exactly what the validator accepts, which is
            // the point: the repository validates again without the proof being
            // weakened into an unchecked field.
            (new AppliedGuidanceTargetValidator())->validate(
                (new ProposalParser())->parseFile($path),
                $this->root,
                $path,
            );
        }
    }

    public function testEachRepairGetsItsOwnHistoryRecord(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row moved home.');

        (new ProposalTransitionManager())->reanchorTarget($this->root, 'MEMORY.md', 'lars', 'Layout move.');

        $lines = array_values(array_filter(explode(
            "\n",
            (string) file_get_contents($this->root . '/history/reanchored-proposals.jsonl'),
        )));
        self::assertCount(2, $lines);

        $ids = [];
        $proposals = [];
        foreach ($lines as $line) {
            /** @var array<string, mixed> $record */
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $ids[] = $record['id'];
            $proposals[] = $record['proposal_id'];
            self::assertSame('MEMORY.md', $record['target_source_ref']);
            self::assertSame($this->hashMemory(), $record['target_content_hash']);
            self::assertSame('lars', $record['reanchored_by']);
        }

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $proposals);
        self::assertSame($ids, array_unique($ids), 'two repairs in one transaction must not share an id.');
    }

    /**
     * `AppliedGuidanceTargetValidator` accepts `MEMORY.md` and `./MEMORY.md` as
     * the same in-root target, so proofs written at different times can spell one
     * file two ways. Matching on the reference text repaired only one spelling and
     * left the other stale, which the end-of-transaction repository validation
     * then rolled the whole repair back for - naming a proposal the caller had no
     * way to reach.
     */
    public function testProofsAreMatchedByTargetIdentityNotByHowTheySpellIt(): void
    {
        $this->writeAppliedProposal(self::SECOND_ID, self::SECOND_RULE, $this->hashMemory(), './MEMORY.md');
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row moved home.');

        $repaired = (new ProposalTransitionManager())->reanchorTarget($this->root, 'MEMORY.md', 'lars', 'Layout move.');

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $repaired);
        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            /** @var array<string, mixed> $record */
            $record = json_decode(
                (string) file_get_contents($this->root . '/proposals/applied/' . $id . '.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            /** @var array<string, mixed> $validation */
            $validation = $record['applied_validation'];
            self::assertSame($this->hashMemory(), $validation['target_content_hash']);
        }
    }

    /**
     * The target proof is the whole content of a re-anchor record. An entry that
     * does not say which file it re-pinned, or to what, is an audit line that
     * cannot be checked against the repository it claims to describe.
     *
     * @param array<string, mixed> $record
     */
    #[DataProvider('incompleteReanchorRecordProvider')]
    public function testAnIncompleteReanchorAuditRecordIsRejected(array $record, string $expected): void
    {
        (new ProposalTransitionManager())->reanchorTarget($this->root, 'MEMORY.md', 'lars', 'Layout move.');
        file_put_contents(
            $this->root . '/history/reanchored-proposals.jsonl',
            json_encode($record, JSON_THROW_ON_ERROR) . "\n",
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches($expected);

        (new DecisionHistoryValidator())->validateHistory(
            $this->root,
            (new ProposalRepository())->loadAll($this->root, []),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function incompleteReanchorRecordProvider(): iterable
    {
        $complete = [
            'id' => 'reanchor.2026-06-08.001',
            'proposal_id' => self::FIRST_ID,
            'reanchored_by' => 'lars',
            'reanchored_at' => '2026-06-08T14:00:00+00:00',
            'reason' => 'Layout move.',
            'target_source_ref' => 'MEMORY.md',
            'target_content_hash' => str_repeat('a', 64),
        ];

        yield 'without a timestamp' => [
            [...$complete, 'reanchored_at' => ' '],
            '/requires reanchored_at/',
        ];
        yield 'without a target' => [
            [...$complete, 'target_source_ref' => ''],
            '/requires target_source_ref/',
        ];
        yield 'without a target hash' => [
            array_diff_key($complete, ['target_content_hash' => null]),
            '/requires target_content_hash as sha256 hex/',
        ];
        yield 'with a malformed target hash' => [
            [...$complete, 'target_content_hash' => 'not-a-sha256'],
            '/requires target_content_hash as sha256 hex/',
        ];
    }

    public function testATargetRefThatEscapesTheProjectRootIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/must stay inside the project root/');

        (new ProposalTransitionManager())->reanchorTarget($this->root, '../MEMORY.md', 'lars', 'A reason.');
    }

    public function testARepairIsRefusedWhenOneTargetRowLostItsGuidance(): void
    {
        $this->writeMemory(self::FIRST_RULE);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/guidance wording is not present/');

        (new ProposalTransitionManager())->reanchorTarget(
            $this->root,
            'MEMORY.md',
            'lars',
            'Attempted repair after a rule was deleted.',
        );
    }

    public function testARefusedRepairLeavesEveryRecordAndTheHistoryUntouched(): void
    {
        $before = [];
        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $before[$id] = (string) file_get_contents($this->root . '/proposals/applied/' . $id . '.json');
        }
        $this->writeMemory(self::FIRST_RULE);

        try {
            (new ProposalTransitionManager())->reanchorTarget($this->root, 'MEMORY.md', 'lars', 'Attempted repair.');
            self::fail('re-anchoring a target that lost a rule must fail.');
        } catch (ValidationException) {
            // expected
        }

        foreach ($before as $id => $content) {
            self::assertSame(
                $content,
                (string) file_get_contents($this->root . '/proposals/applied/' . $id . '.json'),
                'a refused re-anchor must not leave a partially repaired record.',
            );
        }
        self::assertFileDoesNotExist($this->root . '/history/reanchored-proposals.jsonl');
    }

    public function testActorAndReasonAreRequired(): void
    {
        $manager = new ProposalTransitionManager();

        try {
            $manager->reanchorTarget($this->root, 'MEMORY.md', '  ', 'A reason.');
            self::fail('an anonymous re-anchor must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('actor name must be explicit', $exception->getMessage());
        }

        try {
            $manager->reanchorTarget($this->root, 'MEMORY.md', 'lars', '   ');
            self::fail('an unexplained re-anchor must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('re-anchor reason must be explicit', $exception->getMessage());
        }
    }

    public function testATargetNoAppliedProofNamesIsRefusedRatherThanSilentlyDoingNothing(): void
    {
        file_put_contents($this->root . '/docs/unrelated.md', "# Unrelated\n");

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/no applied memory\/skill proof names target/');

        (new ProposalTransitionManager())->reanchorTarget($this->root, 'docs/unrelated.md', 'lars', 'A reason.');
    }

    public function testAMissingTargetIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/applied guidance target does not exist/');

        (new ProposalTransitionManager())->reanchorTarget($this->root, 'docs/gone.md', 'lars', 'A reason.');
    }

    private function hashMemory(): string
    {
        return (string) hash_file('sha256', $this->root . '/MEMORY.md');
    }

    private function writeMemory(string ...$rows): void
    {
        file_put_contents($this->root . '/MEMORY.md', "# Memory\n\n" . implode("\n\n", $rows) . "\n");
    }

    private function writeAppliedProposal(string $proposalId, string $rule, string $targetHash, string $sourceRef = 'MEMORY.md'): void
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/' . self::FIRST_ID . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['id'] = $proposalId;
        $proposal['action'] = 'ADD';
        $proposal['old'] = null;
        $proposal['new'] = $rule;
        $proposal['target_type'] = 'memory';
        $proposal['target'] = $rule;
        $proposal['status'] = 'applied';
        $proposal['applied_by'] = 'maintainer';
        $proposal['applied_at'] = '2026-08-20T14:00:00+00:00';
        $proposal['commit'] = 'commit123';
        $proposal['applied_validation'] = [
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'commit' => 'commit123',
            'target_source_ref' => $sourceRef,
            'target_content_hash' => $targetHash,
            'summary' => 'Fixture application evidence.',
        ];

        file_put_contents(
            $this->root . '/proposals/applied/' . $proposalId . '.json',
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
