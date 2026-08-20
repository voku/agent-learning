<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Cli;
use voku\AgentLearning\ConstraintGenerationPackageExporter;
use voku\AgentLearning\ConstraintEngine;
use voku\AgentLearning\ConstraintLoopRunner;
use voku\AgentLearning\ConstraintManifestActivator;
use voku\AgentLearning\Detectability;
use voku\AgentLearning\FalsePositiveRisk;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\ProposalValidator;
use voku\AgentLearning\ValidationException;

final class ConstraintProposalValidationTest extends TestCase
{
    public function testValidConstraintProposalParsesTypedSpecification(): void
    {
        $proposal = (new ProposalValidator())->validateFile(
            $this->writeProposal($this->proposalRecord()),
            [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ],
        );

        self::assertNotNull($proposal->constraint);
        self::assertSame('project.translation.parameters', $proposal->constraint->ruleId);
        self::assertSame(ConstraintEngine::PHPSTAN, $proposal->constraint->engine);
        self::assertSame('ProjectTranslationParametersRule', $proposal->constraint->ruleClassName);
        self::assertSame('infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', $proposal->constraint->targetRulePath);
        self::assertSame(['infra/githooks/phpstan_bootstrap.php'], $proposal->constraint->registrationFiles);
        self::assertSame(Detectability::STATIC, $proposal->constraint->detectability);
        self::assertSame(FalsePositiveRisk::LOW, $proposal->constraint->falsePositiveRisk);
        self::assertSame('project.translation.parameters', $proposal->target);
        self::assertSame(['src/'], $proposal->scope);
        self::assertSame(['vendor/bin/phpstan analyse'], $proposal->validation);
    }

    public function testAcceptsPhpcsConstraintProposalWithSniffPathAndCommand(): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                'rule_id' => 'project.no.redirect.in.unit.cest',
                'engine' => 'phpcs',
                'rule_class_name' => 'NoRedirectInUnitCestSniff',
                'target_rule_path' => 'infra/githooks/StandardITPortal/Sniffs/NoRedirectInUnitCestSniff.php',
                'registration_files' => ['infra/githooks/phpcs.xml'],
                'scope' => ['src/'],
                'violation' => 'A unit test calls a process-terminating redirect.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'low',
                'validation_commands' => ['make php_codesniffer'],
                'example_rule_paths' => ['infra/githooks/StandardITPortal/Sniffs/ForbiddenPrintRSniff.php'],
            ],
        ]);

        $proposal = (new ProposalValidator())->validateFile(
            $this->writeProposal($record),
            [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ],
        );

        self::assertNotNull($proposal->constraint);
        self::assertSame(ConstraintEngine::PHPCS, $proposal->constraint->engine);
    }

    /**
     * The PHPStan rule location is a path segment, not a substring.
     *
     * The check compared a filesystem path against a namespace-shaped
     * '/PHPStan/', so a consumer whose rules live in a lowercase directory had
     * every one of them rejected by the validator meant to admit them.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function phpStanRuleLocations(): array
    {
        return [
            'lowercase consumer directory' => ['phpstan/Rules/FooRule.php', true],
            'namespace-shaped directory' => ['PHPStan/Rules/FooRule.php', true],
            'mixed case inside a nested path' => ['src/StaticAnalysis/PhpStan/FooRule.php', true],
            'segment merely ending in phpstan' => ['rules/myphpstan/FooRule.php', false],
            'segment merely starting with phpstan' => ['rules/phpstanish/FooRule.php', false],
        ];
    }

    #[DataProvider('phpStanRuleLocations')]
    public function testPhpStanRuleLocationIsMatchedPerPathSegment(string $targetRulePath, bool $accepted): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                'rule_id' => 'project.translation.parameters',
                'engine' => 'phpstan',
                'rule_class_name' => 'FooRule',
                'target_rule_path' => $targetRulePath,
                'registration_files' => ['phpstan.neon.dist'],
                'scope' => ['src/'],
                'violation' => 'A forbidden state.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'low',
                'validation_commands' => ['vendor/bin/phpstan analyse'],
                'example_rule_paths' => ['phpstan/Rules/ExistingRule.php'],
            ],
        ]);

        if (!$accepted) {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('phpstan constraint target rule path must point to a PHPStan rule location');
        }

        $proposal = (new ProposalValidator())->validateFile(
            $this->writeProposal($record),
            [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ],
        );

        self::assertSame($targetRulePath, $proposal->constraint?->targetRulePath);
    }

    public function testRejectsPhpcsConstraintProposalWithNonSniffTargetPath(): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                'rule_id' => 'project.no.redirect.in.unit.cest',
                'engine' => 'phpcs',
                'rule_class_name' => 'NoRedirectInUnitCestSniff',
                'target_rule_path' => 'infra/githooks/StandardITPortal/PHPStan/NoRedirectInUnitCestRule.php',
                'registration_files' => ['infra/githooks/phpcs.xml'],
                'scope' => ['src/'],
                'violation' => 'A unit test calls a process-terminating redirect.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'low',
                'validation_commands' => ['make php_codesniffer'],
                'example_rule_paths' => ['infra/githooks/StandardITPortal/Sniffs/ForbiddenPrintRSniff.php'],
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('phpcs constraint target rule path must point to a Sniffs location');
        (new ProposalValidator())->validateFile(
            $this->writeProposal($record),
            [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ],
        );
    }

    public function testRejectsConstraintProposalWithoutSeveralFindingsOrCriticalIncident(): void
    {
        $record = $this->proposalRecord([
            'source_findings' => ['finding.2026-06-13.001'],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('constraint proposal requires several source findings or critical incident justification');

        (new ProposalValidator())->validateFile(
            $this->writeProposal($record),
            ['finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001')],
        );
    }

    public function testAllowsConstraintProposalFromOneCriticalIncident(): void
    {
        $record = $this->proposalRecord([
            'source_findings' => ['finding.2026-06-13.001'],
            'critical_incident_justification' => 'The defect caused a production-visible translation failure.',
        ]);

        $proposal = (new ProposalValidator())->validateFile(
            $this->writeProposal($record),
            ['finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001')],
        );

        self::assertSame('project.translation.parameters', $proposal->constraint?->ruleId);
    }

    public function testRejectsConstraintProposalFromUnconfirmedFinding(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('source finding is not validated: finding.2026-06-13.001');

        (new ProposalValidator())->validateFile(
            $this->writeProposal($this->proposalRecord()),
            [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001', FindingStatus::CANDIDATE, 'unverified'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ],
        );
    }

    public function testRejectsUnsupportedConstraintEngine(): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                ...$this->constraintRecord(),
                'engine' => 'magic',
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported constraint engine: magic');

        (new ProposalValidator())->validateFile($this->writeProposal($record), [
            'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
            'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
        ]);
    }

    public function testRejectsMissingConstraintValidationCommands(): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                ...$this->constraintRecord(),
                'validation_commands' => [],
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty constraint list field: validation_commands');

        (new ProposalValidator())->validateFile($this->writeProposal($record), [
            'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
            'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
        ]);
    }

    public function testRejectsHighFalsePositiveRiskWithoutJustification(): void
    {
        $record = $this->proposalRecord([
            'constraint' => [
                ...$this->constraintRecord(),
                'false_positive_risk' => 'high',
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('high false-positive risk requires justification');

        (new ProposalValidator())->validateFile($this->writeProposal($record), [
            'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
            'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
        ]);
    }

    public function testExportsConstraintGenerationPackage(): void
    {
        $root = sys_get_temp_dir() . '/constraint_generation_export_' . bin2hex(random_bytes(8));
        $output = $root . '/constraint-generation/proposal.2026-06-13.001';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/candidate', 0777, true);
        mkdir($root . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents(
            $root . '/infra/githooks/StandardITPortal/PHPStan/ItPortalTranslationParametersRule.php',
            "<?php\nfinal class ItPortalTranslationParametersRule {}\n",
        );

        $findings = [
            'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
            'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
        ];
        $proposalPath = $root . '/proposals/candidate/proposal.2026-06-13.001.json';
        file_put_contents($proposalPath, json_encode($this->proposalRecord(), JSON_THROW_ON_ERROR));

        try {
            (new ConstraintGenerationPackageExporter())->export($root, $proposalPath, $output, $findings);

            self::assertFileExists($output . '/specification.json');
            self::assertFileExists($output . '/source-findings.json');
            self::assertFileExists($output . '/source-proposals.json');
            self::assertFileExists($output . '/examples.json');
            self::assertFileExists($output . '/validation-plan.json');
            self::assertFileExists($output . '/generation-prompt.md');

            $spec = json_decode((string) file_get_contents($output . '/specification.json'), true);
            self::assertSame('project.translation.parameters', $spec['constraint']['rule_id']);
            $examples = json_decode((string) file_get_contents($output . '/examples.json'), true);
            self::assertStringContainsString('ItPortalTranslationParametersRule', $examples['examples'][0]['content']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testActivatesConstraintManifestFromApprovedProposal(): void
    {
        $project = sys_get_temp_dir() . '/constraint_manifest_activate_' . bin2hex(random_bytes(8));
        $root = $project . '/.agent-loop/learning';
        mkdir($root . '/proposals/approved', 0777, true);
        mkdir($project . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents($project . '/infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', "<?php\n");
        file_put_contents($project . '/infra/githooks/phpstan_bootstrap.php', "<?php\n");

        $findings = [
            'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
            'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
        ];
        $proposalPath = $root . '/proposals/approved/proposal.2026-06-13.001.json';
        file_put_contents($proposalPath, json_encode($this->approvedProposalRecord(), JSON_THROW_ON_ERROR));

        try {
            $manifestPath = (new ConstraintManifestActivator())->activate($root, $proposalPath, null, $findings);

            self::assertSame($root . '/constraints/active/constraint.project.translation.parameters.json', $manifestPath);
            self::assertFileExists($manifestPath);
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            self::assertSame('constraint.project.translation.parameters', $manifest['id']);
            self::assertSame('phpstan', $manifest['engine']);
            self::assertSame('project.translation.parameters', $manifest['rule_identifier']);
            self::assertSame(['src/'], $manifest['scope']);
            self::assertSame(['vendor/bin/phpstan analyse'], $manifest['validation_commands']);
            self::assertSame('proposal.2026-06-13.001', $manifest['source_proposal']);
            self::assertSame('active', $manifest['status']);
        } finally {
            $this->removeDirectory($project);
        }
    }

    public function testActivatesConstraintManifestFromApprovedProposalUsingBareIdThroughCli(): void
    {
        // Reproduces a real bug: constraint-export/constraint-activate/proposal-validate all
        // resolved the --proposal argument via Cli::resolveProposalPath() directly, which
        // concatenates the raw argument onto each status directory without appending ".json"
        // and without falling back to ProposalTransitionManager::resolveProposalPath() the way
        // resolveProposalPathOrId() (used by constraint-loop and proposal-approve/reject) does.
        // A bare proposal ID -- the natural, documented CLI input shape -- failed with
        // "proposal file does not exist" unless the caller passed the full file path instead.
        $project = sys_get_temp_dir() . '/constraint_manifest_activate_bare_id_' . bin2hex(random_bytes(8));
        $root = $project . '/.agent-loop/learning';
        mkdir($root . '/proposals/approved', 0777, true);
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($project . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents($project . '/infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', "<?php\n");
        file_put_contents($project . '/infra/githooks/phpstan_bootstrap.php', "<?php\n");

        foreach (['finding.2026-06-13.001', 'finding.2026-06-13.002'] as $findingId) {
            file_put_contents(
                $root . '/findings/validated/' . $findingId . '.json',
                json_encode($this->findingRecord($findingId), JSON_THROW_ON_ERROR)
            );
        }

        file_put_contents(
            $root . '/proposals/approved/proposal.2026-06-13.001.json',
            json_encode($this->approvedProposalRecord(), JSON_THROW_ON_ERROR)
        );

        $argv = [
            'agent-learning',
            'constraint-activate',
            '--root',
            $root,
            '--proposal',
            'proposal.2026-06-13.001',
        ];

        try {
            ob_start();
            try {
                $exitCode = (new Cli())->run($argv);
            } finally {
                ob_end_clean();
            }

            self::assertSame(0, $exitCode);
            $manifestPath = $root . '/constraints/active/constraint.project.translation.parameters.json';
            self::assertFileExists($manifestPath);
        } finally {
            $this->removeDirectory($project);
        }
    }

    public function testRejectsConstraintManifestActivationBeforeApproval(): void
    {
        $project = sys_get_temp_dir() . '/constraint_manifest_candidate_' . bin2hex(random_bytes(8));
        $root = $project . '/.agent-loop/learning';
        mkdir($root . '/proposals/candidate', 0777, true);
        mkdir($project . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents($project . '/infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', "<?php\n");
        file_put_contents($project . '/infra/githooks/phpstan_bootstrap.php', "<?php\n");

        $proposalPath = $root . '/proposals/candidate/proposal.2026-06-13.001.json';
        file_put_contents($proposalPath, json_encode($this->proposalRecord(), JSON_THROW_ON_ERROR));

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('constraint activation requires an approved or applied proposal');

            (new ConstraintManifestActivator())->activate($root, $proposalPath, null, [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ]);
        } finally {
            $this->removeDirectory($project);
        }
    }

    public function testConstraintLoopApprovesAppliesAndActivatesCandidate(): void
    {
        $project = sys_get_temp_dir() . '/constraint_loop_' . bin2hex(random_bytes(8));
        $root = $project . '/.agent-loop/learning';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/candidate', 0777, true);
        mkdir($root . '/history', 0777, true);
        mkdir($project . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents($project . '/infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', "<?php\n");
        file_put_contents($project . '/infra/githooks/phpstan_bootstrap.php', "<?php\n");
        file_put_contents(
            $root . '/findings/validated/finding.2026-06-13.001.json',
            json_encode($this->findingRecord('finding.2026-06-13.001'), JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $root . '/findings/validated/finding.2026-06-13.002.json',
            json_encode($this->findingRecord('finding.2026-06-13.002'), JSON_THROW_ON_ERROR),
        );
        $proposalPath = $root . '/proposals/candidate/proposal.2026-06-13.001.json';
        file_put_contents($proposalPath, json_encode($this->proposalRecord(), JSON_THROW_ON_ERROR));
        $validationPath = $root . '/validation-result.json';
        file_put_contents($validationPath, json_encode($this->constraintValidationRecord(), JSON_THROW_ON_ERROR));

        try {
            $findings = [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ];
            $result = (new ConstraintLoopRunner())->run(
                $root,
                $proposalPath,
                'codex',
                'working-tree',
                $validationPath,
                null,
                null,
                $findings,
                true,
            );

            self::assertTrue($result->approvedCandidate);
            self::assertTrue($result->markedApplied);
            self::assertFileDoesNotExist($proposalPath);
            self::assertFileExists($root . '/proposals/applied/proposal.2026-06-13.001.json');
            self::assertFileExists($root . '/constraint-generation/proposal.2026-06-13.001/specification.json');
            self::assertFileExists($root . '/constraints/active/constraint.project.translation.parameters.json');
        } finally {
            $this->removeDirectory($project);
        }
    }

    public function testConstraintLoopUsesConfiguredPathsForNonStandardProjectLayout(): void
    {
        $workspace = sys_get_temp_dir() . '/constraint_loop_configured_' . bin2hex(random_bytes(8));
        $project = $workspace . '/application';
        $root = $workspace . '/learning-state';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/candidate', 0777, true);
        mkdir($root . '/history', 0777, true);
        mkdir($project . '/infra/githooks/StandardITPortal/PHPStan', 0777, true);
        file_put_contents($project . '/infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php', "<?php\n");
        file_put_contents(
            $project . '/infra/githooks/StandardITPortal/PHPStan/ItPortalTranslationParametersRule.php',
            "<?php\nfinal class ItPortalTranslationParametersRule {}\n",
        );
        file_put_contents($project . '/infra/githooks/phpstan_bootstrap.php', "<?php\n");
        file_put_contents($root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../application',
            'constraint_generation_dir' => 'generated-packages',
            'active_constraints_dir' => 'active-manifests',
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $root . '/findings/validated/finding.2026-06-13.001.json',
            json_encode($this->findingRecord('finding.2026-06-13.001'), JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $root . '/findings/validated/finding.2026-06-13.002.json',
            json_encode($this->findingRecord('finding.2026-06-13.002'), JSON_THROW_ON_ERROR),
        );
        $proposalPath = $root . '/proposals/candidate/proposal.2026-06-13.001.json';
        file_put_contents($proposalPath, json_encode($this->proposalRecord(), JSON_THROW_ON_ERROR));
        $validationPath = $root . '/validation-result.json';
        file_put_contents($validationPath, json_encode($this->constraintValidationRecord(), JSON_THROW_ON_ERROR));

        try {
            $findings = [
                'finding.2026-06-13.001' => $this->finding('finding.2026-06-13.001'),
                'finding.2026-06-13.002' => $this->finding('finding.2026-06-13.002'),
            ];
            $result = (new ConstraintLoopRunner())->run(
                $root,
                $proposalPath,
                'codex',
                'working-tree',
                $validationPath,
                null,
                null,
                $findings,
                true,
            );

            self::assertSame($root . '/generated-packages/proposal.2026-06-13.001', $result->generationPackageDir);
            self::assertSame($root . '/active-manifests/constraint.project.translation.parameters.json', $result->manifestPath);
            self::assertFileExists($result->generationPackageDir . '/examples.json');
            self::assertFileExists($result->manifestPath);
            $examples = json_decode((string) file_get_contents($result->generationPackageDir . '/examples.json'), true);
            self::assertStringContainsString('ItPortalTranslationParametersRule', $examples['examples'][0]['content']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function proposalRecord(array $overrides = []): array
    {
        $record = [
            'id' => 'proposal.2026-06-13.001',
            'created_at' => '2026-06-13T10:00:00+00:00',
            'action' => 'ADD',
            'target_type' => 'constraint',
            'source_findings' => [
                'finding.2026-06-13.001',
                'finding.2026-06-13.002',
            ],
            'reason' => 'Repeated translation parameter defects should become an executable static rule.',
            'constraint' => $this->constraintRecord(),
            'status' => 'candidate',
            'proposed_by' => 'agent',
            'approved_by' => null,
            'approved_at' => null,
        ];

        if (isset($overrides['constraint']) && is_array($overrides['constraint'])) {
            /** @var array<string, mixed> $constraintOverrides */
            $constraintOverrides = $overrides['constraint'];
            $record['constraint'] = array_replace($record['constraint'], $constraintOverrides);
            unset($overrides['constraint']);
        }

        return array_replace($record, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedProposalRecord(): array
    {
        return $this->proposalRecord([
            'status' => 'approved',
            'approved_by' => 'lars',
            'approved_at' => '2026-06-13T11:00:00+00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function constraintRecord(): array
    {
        return [
            'rule_id' => 'project.translation.parameters',
            'engine' => 'phpstan',
            'rule_class_name' => 'ProjectTranslationParametersRule',
            'target_rule_path' => 'infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php',
            'registration_files' => ['infra/githooks/phpstan_bootstrap.php'],
            'scope' => ['src/'],
            'violation' => 'Translation placeholders and supplied parameter keys differ.',
            'allowed_boundaries' => [],
            'detectability' => 'static',
            'false_positive_risk' => 'low',
            'validation_commands' => ['vendor/bin/phpstan analyse'],
            'example_rule_paths' => ['infra/githooks/StandardITPortal/PHPStan/ItPortalTranslationParametersRule.php'],
        ];
    }

    private function finding(
        string $id,
        FindingStatus $status = FindingStatus::VALIDATED,
        string $validationStatus = 'validated',
    ): Finding {
        return new Finding(
            $id,
            'PROJECT-1234',
            'session_abc123',
            '2026-06-13T09:00:00+00:00',
            'agent',
            ['src/'],
            'Translation parameter mismatch was found in review.',
            [],
            'A static PHPStan rule can detect the recurring mismatch.',
            $validationStatus === 'validated' ? 'Translation parameters can be checked statically.' : null,
            'high',
            $validationStatus,
            $status,
            'public',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function findingRecord(string $id): array
    {
        return [
            'id' => $id,
            'task_id' => 'PROJECT-1234',
            'session' => 'session_abc123',
            'created_at' => '2026-06-13T09:00:00+00:00',
            'created_by' => 'agent',
            'scope' => ['src/'],
            'observation' => 'Translation parameter mismatch was found in review.',
            'evidence' => [
                [
                    'type' => 'manual_verification',
                    'summary' => 'Validated that the static rule can detect the recurring mismatch.',
                ],
            ],
            'hypothesis' => 'A static PHPStan rule can detect the recurring mismatch.',
            'validated_conclusion' => 'Translation parameters can be checked statically.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function constraintValidationRecord(): array
    {
        return [
            'generated_files' => ['infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php'],
            'registration_file' => 'infra/githooks/phpstan_bootstrap.php',
            'commit' => 'working-tree',
            'tests' => ['vendor/bin/phpstan analyse'],
            'validation_result' => ['phpstan' => 'passed'],
            'content_hashes' => [
                'infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php' => 'hash',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function writeProposal(array $record): string
    {
        $path = tempnam(sys_get_temp_dir(), 'constraint_proposal_');
        self::assertIsString($path);
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));

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
