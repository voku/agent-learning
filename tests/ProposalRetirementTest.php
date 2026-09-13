<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

final class ProposalRetirementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-retirement-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        // Copy a validated finding fixture
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json', $this->root . '/findings/validated/finding.2026-06-08.002.json');

        // Copy applied proposal
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['status'] = 'applied';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        $proposal['applied_by'] = 'maintainer';
        $proposal['applied_at'] = '2026-06-08T14:00:00+00:00';
        $proposal['commit'] = 'commit123';
        file_put_contents($this->root . '/proposals/applied/proposal.2026-06-08.001.json', json_encode($proposal));

        // Matching decision history so the repo starts in a consistent state
        $approvalRecord = [
            'id' => 'decision.2026-06-08.001',
            'proposal_id' => 'proposal.2026-06-08.001',
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:00:00+00:00',
        ];
        $appliedRecord = [
            'id' => 'decision.2026-06-08.002',
            'proposal_id' => 'proposal.2026-06-08.001',
            'status' => 'applied',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-06-08T14:00:00+00:00',
            'commit' => 'commit123',
        ];
        file_put_contents(
            $this->root . '/history/decisions.jsonl',
            json_encode($approvalRecord) . "\n" . json_encode($appliedRecord) . "\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRetiresAppliedProposalSuccessfully(): void
    {
        $manager = new ProposalTransitionManager();
        $manager->retire($this->root, 'proposal.2026-06-08.001', 'lars', 'Fully captured in the target skill, no longer needed in active recall.');

        self::assertFileDoesNotExist($this->root . '/proposals/applied/proposal.2026-06-08.001.json');
        self::assertFileExists($this->root . '/proposals/retired/proposal.2026-06-08.001.json');

        $retired = json_decode((string)file_get_contents($this->root . '/proposals/retired/proposal.2026-06-08.001.json'), true);
        self::assertSame('retired', $retired['status']);
        self::assertSame('lars', $retired['retired_by']);
        self::assertNotEmpty($retired['retired_at']);
        // approved_by/approved_at/applied_by/applied_at must survive the transition
        self::assertSame('maintainer', $retired['approved_by']);
        self::assertSame('maintainer', $retired['applied_by']);

        self::assertFileExists($this->root . '/history/retired-proposals.jsonl');
        $retirements = file_get_contents($this->root . '/history/retired-proposals.jsonl');
        self::assertIsString($retirements);
        self::assertStringContainsString('proposal.2026-06-08.001', $retirements);
        self::assertStringContainsString('Fully captured in the target skill', $retirements);
    }

    public function testCannotRetireNonAppliedProposal(): void
    {
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['id'] = 'proposal.candidate.001';
        $proposal['status'] = 'candidate';
        $proposal['approved_by'] = null;
        $proposal['approved_at'] = null;
        file_put_contents($this->root . '/proposals/candidate/proposal.candidate.001.json', json_encode($proposal));

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal is not applied');
        $manager->retire($this->root, 'proposal.candidate.001', 'lars', 'Not applicable yet.');
    }

    public function testRetiresApprovedGuidanceOnlyWhenAnActiveAppliedConstraintSupersedesItsEvidence(): void
    {
        mkdir($this->root . '/proposals/approved', 0777, true);
        mkdir($this->root . '/constraints/active', 0777, true);

        $legacy = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $legacy['id'] = 'proposal.2026-06-08.003';
        $legacy['status'] = 'approved';
        $legacy['target_type'] = 'memory';
        $legacy['approved_by'] = 'maintainer';
        $legacy['approved_at'] = '2026-06-08T13:00:00+00:00';
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.003.json', json_encode($legacy));

        $constraint = [
            'id' => 'proposal.2026-06-09.001',
            'created_at' => '2026-06-09T09:00:00+00:00',
            'action' => 'ADD',
            'source_findings' => ['finding.2026-06-08.001', 'finding.2026-06-08.002'],
            'reason' => 'The active PHPStan rule now enforces the approved guidance.',
            'scope_justification' => 'The static rule is narrower than the recurring source-finding pattern.',
            'status' => 'applied',
            'proposed_by' => 'maintainer',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-09T10:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-06-09T11:00:00+00:00',
            'commit' => 'commit456',
            'target_type' => 'constraint',
            'target' => 'project.example.rule',
            'scope' => ['phpstan/Rules/ExampleRule.php'],
            'old' => null,
            'new' => 'The PHPStan rule enforces the invariant.',
            'boundary' => 'Only direct violations are rejected.',
            'validation' => ['vendor/bin/phpstan analyse'],
            'constraint' => [
                'rule_id' => 'project.example.rule',
                'engine' => 'phpstan',
                'rule_class_name' => 'ExampleRule',
                'target_rule_path' => 'phpstan/Rules/ExampleRule.php',
                'registration_files' => ['phpstan.neon'],
                'scope' => ['phpstan/Rules/ExampleRule.php'],
                'violation' => 'The source violates the example invariant.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'low',
                'validation_commands' => ['vendor/bin/phpstan analyse'],
                'example_rule_paths' => ['src/OtherRule.php'],
            ],
        ];
        file_put_contents($this->root . '/proposals/applied/proposal.2026-06-09.001.json', json_encode($constraint));
        file_put_contents(
            $this->root . '/constraints/active/constraint.project.example.rule.json',
            json_encode([
                'schema_version' => '1.0',
                'id' => 'constraint.project.example.rule',
                'status' => 'active',
                'source_proposal' => 'proposal.2026-06-09.001',
            ]),
        );

        $history = file_get_contents($this->root . '/history/decisions.jsonl');
        self::assertIsString($history);
        $history .= json_encode([
            'id' => 'decision.2026-06-08.003',
            'proposal_id' => 'proposal.2026-06-08.003',
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-08T13:00:00+00:00',
        ]) . "\n";
        $history .= json_encode([
            'id' => 'decision.2026-06-09.001',
            'proposal_id' => 'proposal.2026-06-09.001',
            'status' => 'approved',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-09T10:00:00+00:00',
        ]) . "\n";
        $history .= json_encode([
            'id' => 'decision.2026-06-09.002',
            'proposal_id' => 'proposal.2026-06-09.001',
            'status' => 'applied',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-06-09T10:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-06-09T11:00:00+00:00',
            'commit' => 'commit456',
        ]) . "\n";
        file_put_contents($this->root . '/history/decisions.jsonl', $history);

        (new ProposalTransitionManager())->retire(
            $this->root,
            'proposal.2026-06-08.003',
            'lars',
            'The active constraint fully captures the prior prompt guidance.',
            'proposal.2026-06-09.001',
        );

        $retired = json_decode((string)file_get_contents($this->root . '/proposals/retired/proposal.2026-06-08.003.json'), true);
        self::assertSame('retired', $retired['status']);
        self::assertSame('proposal.2026-06-09.001', $retired['superseded_by']);
    }

    public function testCannotRetireApprovedGuidanceWithoutAnAppliedConstraintSuperseder(): void
    {
        mkdir($this->root . '/proposals/approved', 0777, true);
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['id'] = 'proposal.2026-06-08.004';
        $proposal['status'] = 'approved';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.004.json', json_encode($proposal));

        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('approved proposal retirement requires an explicit applied constraint superseder');
        $manager->retire($this->root, 'proposal.2026-06-08.004', 'lars', 'No longer needed.');
    }

    public function testRetireRequiresExplicitReason(): void
    {
        $manager = new ProposalTransitionManager();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('retirement reason must be explicit');
        $manager->retire($this->root, 'proposal.2026-06-08.001', 'lars', '   ');
    }

    public function testRetiredProposalWithArchivedSourceFindingStillValidates(): void
    {
        // Move the finding to archived/ once its lesson is fully captured, mirroring the
        // documented sequence: applied proposal first, then the source finding is archived.
        mkdir($this->root . '/findings/archived', 0777, true);
        $finding = json_decode(
            (string)file_get_contents($this->root . '/findings/validated/finding.2026-06-08.001.json'),
            true
        );
        $finding['status'] = 'archived';
        file_put_contents($this->root . '/findings/archived/finding.2026-06-08.001.json', json_encode($finding));
        unlink($this->root . '/findings/validated/finding.2026-06-08.001.json');

        $manager = new ProposalTransitionManager();
        $manager->retire($this->root, 'proposal.2026-06-08.001', 'lars', 'Fully captured in the target skill.');

        self::assertFileExists($this->root . '/proposals/retired/proposal.2026-06-08.001.json');
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
