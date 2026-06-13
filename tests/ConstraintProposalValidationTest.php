<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ConstraintGenerationPackageExporter;
use voku\AgentLearning\ConstraintEngine;
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

            $spec = json_decode((string)file_get_contents($output . '/specification.json'), true);
            self::assertSame('project.translation.parameters', $spec['constraint']['rule_id']);
        } finally {
            $this->removeDirectory($root);
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
