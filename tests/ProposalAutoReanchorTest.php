<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceTargetValidator;
use voku\AgentLearning\Cli;
use voku\AgentLearning\ProposalParser;
use voku\AgentLearning\ProposalRepository;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

/**
 * Tests for automated re-anchoring of applied guidance proofs.
 *
 * Governing principles:
 * - Situation 1: File changed, anchored lines unchanged -> auto-reanchors cleanly.
 * - Situation 2: Lines moved, content demonstrably identical -> auto-reanchors cleanly.
 * - Situation 3: Guidance text altered or deleted -> refuses auto-reanchor, fails closed, leaves records untouched.
 * - "Git ancestry proves history, not semantic equivalence. An automatic re-anchor
 *    must never silently turn stale evidence into apparently valid evidence."
 *
 * @internal
 */
final class ProposalAutoReanchorTest extends TestCase
{
    private const string FIRST_ID = 'proposal.2026-06-08.001';

    private const string SECOND_ID = 'proposal.2026-06-08.002';

    private const string FIRST_RULE = 'Keep the packaged entrypoint callable.';

    private const string SECOND_RULE = 'Name the owner that enforces a rule.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-auto-reanchor-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/skills/test-skill', 0777, true);

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

    /**
     * Situation 1: File changed, anchored lines unchanged.
     * An unrelated row is added to MEMORY.md. Target hash drifts, but all applied
     * guidance rules remain 100% present in the file.
     */
    public function testSituation1FileChangedAnchoredLinesUnchangedAutoReanchorsCleanly(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated new row added later.');

        $repaired = (new ProposalTransitionManager())->autoReanchor($this->root);

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $repaired);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
            self::assertFileExists($path);

            /** @var array<string, mixed> $record */
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $validation */
            $validation = $record['applied_validation'];

            self::assertSame('applied', $record['status']);
            self::assertSame($this->hashMemory(), $validation['target_content_hash']);
            self::assertSame('auto-reanchor', $validation['reanchored_by']);
            self::assertNotEmpty($validation['reanchored_at']);
            self::assertStringContainsString('Automated re-anchor', (string) $validation['reanchor_reason']);

            // Repository validator must accept the updated record
            (new AppliedGuidanceTargetValidator())->validate(
                (new ProposalParser())->parseFile($path),
                $this->root,
                $path,
            );
        }

        // Must record audit entries in history/reanchored-proposals.jsonl
        $historyFile = $this->root . '/history/reanchored-proposals.jsonl';
        self::assertFileExists($historyFile);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($historyFile))));
        self::assertCount(2, $lines);

        $historyIds = [];
        foreach ($lines as $line) {
            /** @var array<string, mixed> $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $historyIds[] = $entry['id'];
            self::assertSame('MEMORY.md', $entry['target_source_ref']);
            self::assertSame($this->hashMemory(), $entry['target_content_hash']);
            self::assertSame('auto-reanchor', $entry['reanchored_by']);
        }
        self::assertSame($historyIds, array_unique($historyIds));
    }

    /**
     * Situation 2: Lines moved, content demonstrably identical.
     * The order of rows is swapped and comments/blank lines are added.
     * The file hash changes, but both rules are demonstrably present.
     */
    public function testSituation2LinesMovedContentDemonstrablyIdenticalAutoReanchorsCleanly(): void
    {
        // Reordered: second rule first, then first rule, with blank lines
        file_put_contents(
            $this->root . '/MEMORY.md',
            "# Reordered Memory\n\n<!-- section 1 -->\n" . self::SECOND_RULE . "\n\n<!-- section 2 -->\n" . self::FIRST_RULE . "\n",
        );

        $repaired = (new ProposalTransitionManager())->autoReanchor($this->root, 'MEMORY.md', 'custom-actor', 'Reordered sections');

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $repaired);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
            /** @var array<string, mixed> $record */
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($this->hashMemory(), $record['applied_validation']['target_content_hash']);
            self::assertSame('custom-actor', $record['applied_validation']['reanchored_by']);
            self::assertSame('Reordered sections', $record['applied_validation']['reanchor_reason']);
        }
    }

    /**
     * Situation 3: Anchored content changed semantically or cannot be proven equivalent.
     * One rule was deleted or altered. Auto-reanchor MUST refuse, fail closed,
     * and leave every proposal and history file untouched.
     */
    public function testSituation3GuidanceAlteredOrDeletedRefusesAutoReanchorAndFailsClosed(): void
    {
        $snapshotBefore = $this->snapshotState();

        // Delete the second rule
        $this->writeMemory(self::FIRST_RULE, 'Something else entirely');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/automatic re-anchor refused for target MEMORY.md.*guidance wording is not present.*Manual review required/s');

        try {
            (new ProposalTransitionManager())->autoReanchor($this->root);
        } finally {
            self::assertSame($snapshotBefore, $this->snapshotState(), 'state must remain strictly identical on failure.');
        }
    }

    /**
     * Situation 3 with REPLACE proposal:
     * If replaced (old) guidance wording is reintroduced into target, auto-reanchor fails closed.
     */
    public function testSituation3ReplacedOldGuidanceReintroducedRefusesAutoReanchor(): void
    {
        $replaceId = 'proposal.2026-06-08.003';
        $oldWording = 'Do not use eval in templates.';
        $newWording = 'Use strict sandbox compilers instead of eval.';

        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, $newWording);
        $this->writeAppliedReplaceProposal($replaceId, $oldWording, $newWording, $this->hashMemory());

        $snapshotBefore = $this->snapshotState();

        // Reintroduce the forbidden old wording
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, $newWording, $oldWording);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/replaced guidance wording is still present in target/s');

        try {
            (new ProposalTransitionManager())->autoReanchor($this->root);
        } finally {
            self::assertSame($snapshotBefore, $this->snapshotState(), 'state must remain untouched when old wording is present.');
        }
    }

    /**
     * Situation 3 with DELETE proposal:
     * If deleted guidance wording is reintroduced into target, auto-reanchor fails closed.
     */
    public function testSituation3DeletedGuidanceReintroducedRefusesAutoReanchor(): void
    {
        $deleteId = 'proposal.2026-06-08.004';
        $deletedWording = 'Deprecated legacy helper must be avoided.';

        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE);
        $this->writeAppliedDeleteProposal($deleteId, $deletedWording, $this->hashMemory());

        $snapshotBefore = $this->snapshotState();

        // Reintroduce the deleted wording
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, $deletedWording);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/deleted guidance wording is still present in target/s');

        try {
            (new ProposalTransitionManager())->autoReanchor($this->root);
        } finally {
            self::assertSame($snapshotBefore, $this->snapshotState(), 'state must remain untouched when deleted wording is present.');
        }
    }

    /**
     * Multiple targets: MEMORY.md and SKILL.md.
     * Both have drifted hashes and both demonstrably satisfy their guidance.
     * Auto-reanchor repairs all proposals across both targets in one atomic transaction.
     */
    public function testMultiTargetAutoReanchorRepairsAllTargetsAtomically(): void
    {
        $skillPath = 'skills/test-skill/SKILL.md';
        $skillRule = 'Verify bounded execution before mutating.';
        $skillId = 'proposal.2026-06-08.005';

        file_put_contents($this->root . '/' . $skillPath, "# Skill\n\n" . $skillRule . "\n");
        $skillHash = (string) hash_file('sha256', $this->root . '/' . $skillPath);
        $this->writeAppliedProposal($skillId, $skillRule, $skillHash, $skillPath, 'skill');

        // Drift both files legitimately
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'Additional memory row.');
        file_put_contents($this->root . '/' . $skillPath, "# Skill\n\n" . $skillRule . "\n\nUnrelated skill note.\n");

        $repaired = (new ProposalTransitionManager())->autoReanchor($this->root);

        self::assertSame([self::FIRST_ID, self::SECOND_ID, $skillId], $repaired);

        // All proposals must now validate
        $proposals = (new ProposalRepository())->loadAll($this->root, []);
        self::assertArrayHasKey(self::FIRST_ID, $proposals);
        self::assertArrayHasKey(self::SECOND_ID, $proposals);
        self::assertArrayHasKey($skillId, $proposals);
    }

    /**
     * Multi-target atomic failure: if one target passes but another target fails semantic validation,
     * NOTHING is written and all targets remain untouched.
     */
    public function testMultiTargetAtomicFailureLeavesAllTargetsUntouched(): void
    {
        $skillPath = 'skills/test-skill/SKILL.md';
        $skillRule = 'Verify bounded execution before mutating.';
        $skillId = 'proposal.2026-06-08.005';

        file_put_contents($this->root . '/' . $skillPath, "# Skill\n\n" . $skillRule . "\n");
        $skillHash = (string) hash_file('sha256', $this->root . '/' . $skillPath);
        $this->writeAppliedProposal($skillId, $skillRule, $skillHash, $skillPath, 'skill');

        $snapshotBefore = $this->snapshotState();

        // Legitimate drift on MEMORY.md, but DELETED rule on SKILL.md
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'Additional memory row.');
        file_put_contents($this->root . '/' . $skillPath, "# Skill\n\nRule was deleted!\n");

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/automatic re-anchor refused for target skills\/test-skill\/SKILL\.md/s');

        try {
            (new ProposalTransitionManager())->autoReanchor($this->root);
        } finally {
            self::assertSame($snapshotBefore, $this->snapshotState(), 'state must remain untouched across all targets.');
        }
    }

    /**
     * When all targets are already up to date, autoReanchor returns [] without modifications.
     */
    public function testAlreadyUpToDateReturnsEmptyListWithoutModifications(): void
    {
        $snapshotBefore = $this->snapshotState();

        $repaired = (new ProposalTransitionManager())->autoReanchor($this->root);

        self::assertSame([], $repaired);
        self::assertSame($snapshotBefore, $this->snapshotState());
    }

    /**
     * Target filter restricts auto-reanchor to the named target file.
     */
    public function testTargetFilterRestrictsToNamedTarget(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row.');

        $repaired = (new ProposalTransitionManager())->autoReanchor($this->root, 'MEMORY.md');

        self::assertSame([self::FIRST_ID, self::SECOND_ID], $repaired);
    }

    /**
     * CLI integration: `proposal-reanchor --auto`
     */
    public function testCliAutoReanchorCommand(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row.');

        $cli = new Cli();
        ob_start();
        try {
            $code = $cli->run([
                'agent-learning',
                'proposal-reanchor',
                '--root',
                $this->root,
                '--auto',
                '--by',
                'cli-actor',
                '--reason',
                'CLI automated re-anchor',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $code);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
            /** @var array<string, mixed> $record */
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($this->hashMemory(), $record['applied_validation']['target_content_hash']);
            self::assertSame('cli-actor', $record['applied_validation']['reanchored_by']);
            self::assertSame('CLI automated re-anchor', $record['applied_validation']['reanchor_reason']);
        }

        // Running again when already up to date returns exit code 0
        ob_start();
        try {
            $codeAgain = $cli->run([
                'agent-learning',
                'proposal-reanchor',
                '--root',
                $this->root,
                '--auto',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $codeAgain);
    }

    /**
     * CLI integration: `proposal-reanchor <target> --auto`
     */
    public function testCliTargetScopedAutoReanchorCommand(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row.');

        $cli = new Cli();
        ob_start();
        try {
            $code = $cli->run([
                'agent-learning',
                'proposal-reanchor',
                'MEMORY.md',
                '--root',
                $this->root,
                '--auto',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $code);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
            /** @var array<string, mixed> $record */
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($this->hashMemory(), $record['applied_validation']['target_content_hash']);
        }
    }

    /**
     * Diagnostic error message recommends `proposal-reanchor <target> --auto`.
     */
    public function testDiagnosticErrorMessageRecommendsAutoOption(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row.');

        $proposalPath = $this->root . '/proposals/applied/' . self::FIRST_ID . '.json';

        try {
            (new AppliedGuidanceTargetValidator())->validate(
                (new ProposalParser())->parseFile($proposalPath),
                $this->root,
                $proposalPath,
            );
            self::fail('stale proof must fail closed');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('proposal-reanchor MEMORY.md --auto', $exception->getMessage());
            self::assertStringContainsString('proposal-reanchor MEMORY.md --by ACTOR --reason TEXT', $exception->getMessage());
        }
    }

    /**
     * CLI integration: `proposal-reanchor --auto <target>` syntax.
     */
    public function testCliAutoOptionWithTargetArgument(): void
    {
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'An unrelated row.');

        $cli = new Cli();
        ob_start();
        try {
            $code = $cli->run([
                'agent-learning',
                'proposal-reanchor',
                '--root',
                $this->root,
                '--auto',
                'MEMORY.md',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $code);

        foreach ([self::FIRST_ID, self::SECOND_ID] as $id) {
            $path = $this->root . '/proposals/applied/' . $id . '.json';
            /** @var array<string, mixed> $record */
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($this->hashMemory(), $record['applied_validation']['target_content_hash']);
        }
    }

    /**
     * Pre-policy records must fail closed during auto-reanchor to prevent
     * silently re-pinning hashes without semantic verification.
     */
    public function testPrePolicyRecordRefusesAutoReanchorAndDemandsManualReview(): void
    {
        $prePolicyId = 'proposal.2026-06-01.001';
        $this->writeAppliedProposal($prePolicyId, 'Legacy pre-policy rule.', $this->hashMemory());

        // Mark as pre-policy (before 2026-08-09)
        $prePolicyPath = $this->root . '/proposals/applied/' . $prePolicyId . '.json';
        /** @var array<string, mixed> $record */
        $record = json_decode((string) file_get_contents($prePolicyPath), true, 512, JSON_THROW_ON_ERROR);
        $record['applied_at'] = '2026-06-01T10:00:00+00:00';
        file_put_contents($prePolicyPath, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        // Change memory to trigger stale check
        $this->writeMemory(self::FIRST_RULE, self::SECOND_RULE, 'Legacy pre-policy rule.', 'A new row.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/pre-policy applied guidance requires manual review/s');

        (new ProposalTransitionManager())->autoReanchor($this->root);
    }

    /**
     * Post-policy proposal with missing or invalid target fails closed instead of being silently skipped.
     */
    public function testPostPolicyProposalWithMissingTargetFailsClosed(): void
    {
        $invalidId = 'proposal.2026-08-25.099';
        $this->writeAppliedProposal($invalidId, 'Some rule.', 'dummy_hash', 'non-existent-target.md');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/applied guidance target does not exist: non-existent-target\.md/s');

        (new ProposalTransitionManager())->autoReanchor($this->root);
    }

    /**
     * @return array<string, string>
     */
    private function snapshotState(): array
    {
        $records = [];
        foreach (['proposals', 'history'] as $directory) {
            $dir = $this->root . '/' . $directory;
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $content = file_get_contents($file->getPathname());
                    self::assertIsString($content);
                    $records[substr($file->getPathname(), strlen($this->root) + 1)] = $content;
                }
            }
        }
        ksort($records);

        return $records;
    }

    private function hashMemory(): string
    {
        return (string) hash_file('sha256', $this->root . '/MEMORY.md');
    }

    private function writeMemory(string ...$rows): void
    {
        file_put_contents($this->root . '/MEMORY.md', "# Memory\n\n" . implode("\n\n", $rows) . "\n");
    }

    private function writeAppliedProposal(string $proposalId, string $rule, string $targetHash, string $sourceRef = 'MEMORY.md', string $targetType = 'memory'): void
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
        $proposal['target_type'] = $targetType;
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

    private function writeAppliedReplaceProposal(string $proposalId, string $oldRule, string $newRule, string $targetHash, string $sourceRef = 'MEMORY.md'): void
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/' . self::FIRST_ID . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['id'] = $proposalId;
        $proposal['action'] = 'REPLACE';
        $proposal['old'] = $oldRule;
        $proposal['new'] = $newRule;
        $proposal['target_type'] = 'memory';
        $proposal['target'] = $newRule;
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
            'summary' => 'Fixture replacement application evidence.',
        ];

        file_put_contents(
            $this->root . '/proposals/applied/' . $proposalId . '.json',
            json_encode($proposal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function writeAppliedDeleteProposal(string $proposalId, string $oldRule, string $targetHash, string $sourceRef = 'MEMORY.md'): void
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/' . self::FIRST_ID . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['id'] = $proposalId;
        $proposal['action'] = 'DELETE';
        $proposal['old'] = $oldRule;
        $proposal['new'] = null;
        $proposal['target_type'] = 'memory';
        $proposal['target'] = $oldRule;
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
            'summary' => 'Fixture delete application evidence.',
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
