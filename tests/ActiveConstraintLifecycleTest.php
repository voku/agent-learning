<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for voku/agent-learning#206 and #207 through the installed CLI:
 * retiring an applied constraint deactivates exactly the manifest it sourced, and
 * `validate` fails closed while an active manifest points at missing or retired guidance.
 */
final class ActiveConstraintLifecycleTest extends TestCase
{
    private string $project;
    private string $root;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/active constraint lifecycle ' . bin2hex(random_bytes(8));
        $this->root = $this->project . '/.agent-loop/learning';
        foreach (['findings/validated', 'proposals/applied', 'constraints/active', 'history'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        mkdir($this->project . '/phpstan/Rules', 0777, true);
        file_put_contents($this->project . '/phpstan.neon', "rules: []\n");
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json', $this->root . '/findings/validated/finding.2026-06-08.002.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->project);
    }

    public function testValidateFailsForMissingRuleFileAndRetirementDeactivatesOnlyItsOwnManifest(): void
    {
        $this->addAppliedConstraint('proposal.2026-06-09.001', 'project.removed.rule', 'RemovedRule');
        $this->addAppliedConstraint('proposal.2026-06-09.002', 'project.unrelated.rule', 'UnrelatedRule');
        $removedManifest = $this->root . '/constraints/active/constraint.project.removed.rule.json';
        $unrelatedManifest = $this->root . '/constraints/active/constraint.project.unrelated.rule.json';

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Active constraints: 2', $output);

        unlink($this->project . '/phpstan/Rules/RemovedRule.php');
        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('constraint.project.removed.rule.json', $output);
        self::assertStringContainsString('active constraint target rule file does not exist under project root', $output);
        self::assertStringContainsString('phpstan/Rules/RemovedRule.php', $output);
        self::assertStringContainsString('proposal-retire proposal.2026-06-09.001', $output);

        // A relative --project-root from the project directory sees the same files (#208).
        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning', '--project-root', '.']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('phpstan/Rules/RemovedRule.php', $output);
        // An unavailable project root is reported as such, not as a missing rule file.
        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning', '--project-root', 'missing-project']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('--project-root directory does not exist', $output);
        self::assertStringNotContainsString('RemovedRule.php', $output);

        $decisionsBefore = (string) file_get_contents($this->root . '/history/decisions.jsonl');
        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'The rule was removed from the project.']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Deactivated constraint manifest: ' . $removedManifest, $output);

        self::assertFileDoesNotExist($removedManifest);
        self::assertFileExists($unrelatedManifest, 'An unrelated active constraint must stay selected.');
        self::assertFileExists($this->root . '/proposals/retired/proposal.2026-06-09.001.json');
        self::assertSame($decisionsBefore, (string) file_get_contents($this->root . '/history/decisions.jsonl'), 'Approval and application history stay intact.');
        self::assertStringContainsString('proposal.2026-06-09.001', (string) file_get_contents($this->root . '/history/retired-proposals.jsonl'));

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Active constraints: 1', $output);
    }

    public function testRetiringAnOlderProposalKeepsANewerManifestWithTheSameRuleIdentity(): void
    {
        $this->addAppliedConstraint('proposal.2026-06-09.001', 'project.shared.rule', 'SharedRule');
        $this->addAppliedConstraint('proposal.2026-06-10.001', 'project.shared.rule', 'SharedRule');
        $manifest = $this->root . '/constraints/active/constraint.project.shared.rule.json';
        self::assertStringContainsString('proposal.2026-06-10.001', (string) file_get_contents($manifest));

        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Superseded by the newer rule proposal.']);
        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('Deactivated constraint manifest', $output);
        self::assertFileExists($manifest);
        self::assertStringContainsString('proposal.2026-06-10.001', (string) file_get_contents($manifest));

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertSame(0, $code, $output);
    }

    public function testRerunningRetirementRepairsAStaleManifestLeftByAnEarlierRetirement(): void
    {
        $this->addAppliedConstraint('proposal.2026-06-09.001', 'project.removed.rule', 'RemovedRule');
        $manifest = $this->root . '/constraints/active/constraint.project.removed.rule.json';
        $manifestContent = (string) file_get_contents($manifest);
        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Removed.']);
        self::assertSame(0, $code, $output);
        // Recreate the state left by agent-learning <= 0.22.0, whose retirement kept the manifest.
        file_put_contents($manifest, $manifestContent);
        $retirementHistory = (string) file_get_contents($this->root . '/history/retired-proposals.jsonl');

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('active constraint manifest names retired source proposal proposal.2026-06-09.001', $output);

        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Remove the stale manifest.']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Deactivated constraint manifest: ' . $manifest, $output);
        self::assertFileDoesNotExist($manifest);
        self::assertSame($retirementHistory, (string) file_get_contents($this->root . '/history/retired-proposals.jsonl'), 'The repair does not record a second retirement.');

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertSame(0, $code, $output);

        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Again.']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('already retired and no active constraint manifest names it', $output);
    }

    public function testFailedRetirementRestoresTheManifestAndTheAppliedProposal(): void
    {
        $this->addAppliedConstraint('proposal.2026-06-09.001', 'project.removed.rule', 'RemovedRule');
        $manifest = $this->root . '/constraints/active/constraint.project.removed.rule.json';
        $manifestContent = (string) file_get_contents($manifest);
        // A corrupt outcome log makes the post-write repository validation fail inside the transition.
        file_put_contents($this->root . '/history/outcomes.jsonl', "{not json\n");

        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Removed.']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('rolled back', $output);
        self::assertSame($manifestContent, (string) file_get_contents($manifest));
        self::assertFileExists($this->root . '/proposals/applied/proposal.2026-06-09.001.json');
        self::assertFileDoesNotExist($this->root . '/proposals/retired/proposal.2026-06-09.001.json');

        // Retrying after the cause is fixed converges.
        unlink($this->root . '/history/outcomes.jsonl');
        [$code, $output] = $this->cli($this->project, ['proposal-retire', 'proposal.2026-06-09.001', '--root', '.agent-loop/learning', '--by', 'maintainer', '--reason', 'Removed.']);
        self::assertSame(0, $code, $output);
        self::assertFileDoesNotExist($manifest);
    }

    public function testValidateRejectsAManifestWhoseSourceProposalIsUnknown(): void
    {
        file_put_contents($this->root . '/constraints/active/constraint.project.orphan.rule.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'constraint.project.orphan.rule',
            'rule_identifier' => 'project.orphan.rule',
            'source_proposal' => 'proposal.2026-01-01.999',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        [$code, $output] = $this->cli($this->project, ['validate', '--root', '.agent-loop/learning']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('references unknown source proposal: proposal.2026-01-01.999', $output);
    }

    private function addAppliedConstraint(string $proposalId, string $ruleId, string $ruleClass): void
    {
        $rulePath = 'phpstan/Rules/' . $ruleClass . '.php';
        file_put_contents($this->project . '/' . $rulePath, "<?php\n");
        $date = substr($proposalId, strlen('proposal.'), 10);
        $proposal = [
            'id' => $proposalId,
            'created_at' => $date . 'T09:00:00+00:00',
            'action' => 'ADD',
            'source_findings' => ['finding.2026-06-08.001', 'finding.2026-06-08.002'],
            'reason' => 'The PHPStan rule enforces the recurring finding.',
            'scope_justification' => 'The static rule is narrower than the recurring source-finding pattern.',
            'status' => 'applied',
            'proposed_by' => 'maintainer',
            'approved_by' => 'maintainer',
            'approved_at' => $date . 'T10:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => $date . 'T11:00:00+00:00',
            'commit' => 'commit456',
            'target_type' => 'constraint',
            'target' => $ruleId,
            'scope' => [$rulePath],
            'old' => null,
            'new' => 'The PHPStan rule enforces the invariant.',
            'boundary' => 'Only direct violations are rejected.',
            'validation' => ['vendor/bin/phpstan analyse'],
            'constraint' => [
                'rule_id' => $ruleId,
                'engine' => 'phpstan',
                'rule_class_name' => $ruleClass,
                'target_rule_path' => $rulePath,
                'registration_files' => ['phpstan.neon'],
                'scope' => [$rulePath],
                'violation' => 'The source violates the example invariant.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'low',
                'validation_commands' => ['vendor/bin/phpstan analyse'],
                'example_rule_paths' => ['src/OtherRule.php'],
            ],
        ];
        file_put_contents($this->root . '/proposals/applied/' . $proposalId . '.json', json_encode($proposal, JSON_THROW_ON_ERROR));

        $decisions = $this->root . '/history/decisions.jsonl';
        $count = is_file($decisions) ? count(file($decisions) ?: []) : 0;
        file_put_contents($decisions, json_encode([
            'id' => sprintf('decision.%s.%03d', $date, $count + 1),
            'proposal_id' => $proposalId,
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => $date . 'T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR) . "\n" . json_encode([
            'id' => sprintf('decision.%s.%03d', $date, $count + 2),
            'proposal_id' => $proposalId,
            'status' => 'applied',
            'approved_by' => 'maintainer',
            'approved_at' => $date . 'T10:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => $date . 'T11:00:00+00:00',
            'commit' => 'commit456',
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

        // The manifest is written by the owner's activation path, not by hand.
        [$code, $output] = $this->cli($this->project, ['constraint-activate', '--root', '.agent-loop/learning', '--project-root', '.', '--proposal', $proposalId, '--overwrite']);
        self::assertSame(0, $code, $output);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function cli(string $cwd, array $arguments): array
    {
        $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, dirname(__DIR__) . '/bin/agent-learning', ...$arguments]));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
