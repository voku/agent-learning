<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalImporter;
use voku\AgentLearning\ValidationException;

final class ProposalImportIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-import-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        // Copy a validated finding fixture
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testImportsValidResultAsCandidateProposal(): void
    {
        $resultData = [
            'action' => 'ADD',
            'source_findings' => ['finding.2026-06-08.001'],
            'reason' => 'Repeated auth failures require better rules.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/'],
            'new' => 'Do not allow bypasses.',
            'boundary' => 'boundary info',
            'validation' => ['test auth'],
        ];

        $jsonFile = $this->root . '/result.json';
        file_put_contents($jsonFile, json_encode($resultData));

        $importer = new ProposalImporter();
        $proposalId = $importer->import($this->root, $jsonFile);

        self::assertStringStartsWith('proposal.', $proposalId);
        self::assertFileExists($this->root . '/proposals/candidate/' . $proposalId . '.json');
    }

    public function testImportsConstraintResultAsCandidateProposal(): void
    {
        $finding2 = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json'), true);
        $finding2['scope'] = ['src/'];
        file_put_contents($this->root . '/findings/validated/finding.2026-06-08.002.json', json_encode($finding2));

        $resultData = [
            'action' => 'ADD',
            'source_findings' => [
                'finding.2026-06-08.001',
                'finding.2026-06-08.002',
            ],
            'reason' => 'Repeated translation parameter defects should become an executable static rule.',
            'target_type' => 'constraint',
            'constraint' => [
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
            ],
        ];

        $jsonFile = $this->root . '/constraint-result.json';
        file_put_contents($jsonFile, json_encode($resultData));

        $proposalId = (new ProposalImporter())->import($this->root, $jsonFile);
        $proposalData = json_decode((string)file_get_contents($this->root . '/proposals/candidate/' . $proposalId . '.json'), true);

        self::assertSame('constraint', $proposalData['target_type']);
        self::assertSame('project.translation.parameters', $proposalData['target']);
        self::assertSame('project.translation.parameters', $proposalData['constraint']['rule_id']);
        self::assertSame('infra/githooks/phpstan_bootstrap.php', $proposalData['constraint']['registration_files'][0]);
    }

    public function testImportsHighRiskConstraintWithJustification(): void
    {
        $resultData = [
            'action' => 'ADD',
            'source_findings' => ['finding.2026-06-08.001'],
            'reason' => 'A critical production incident justifies an executable static rule.',
            'target_type' => 'constraint',
            'critical_incident_justification' => 'The defect caused a production-visible translation failure.',
            'false_positive_risk_justification' => 'The first implementation is intentionally narrow and fixture-backed.',
            'constraint' => [
                'rule_id' => 'project.translation.parameters',
                'engine' => 'phpstan',
                'rule_class_name' => 'ProjectTranslationParametersRule',
                'target_rule_path' => 'infra/githooks/StandardITPortal/PHPStan/ProjectTranslationParametersRule.php',
                'registration_files' => ['infra/githooks/phpstan_bootstrap.php'],
                'scope' => ['src/'],
                'violation' => 'Translation placeholders and supplied parameter keys differ.',
                'allowed_boundaries' => [],
                'detectability' => 'static',
                'false_positive_risk' => 'high',
                'validation_commands' => ['vendor/bin/phpstan analyse'],
                'example_rule_paths' => ['infra/githooks/StandardITPortal/PHPStan/ItPortalTranslationParametersRule.php'],
            ],
        ];

        $jsonFile = $this->root . '/high-risk-constraint-result.json';
        file_put_contents($jsonFile, json_encode($resultData));

        $proposalId = (new ProposalImporter())->import($this->root, $jsonFile);
        $proposalData = json_decode((string)file_get_contents($this->root . '/proposals/candidate/' . $proposalId . '.json'), true);

        self::assertSame('The first implementation is intentionally narrow and fixture-backed.', $proposalData['false_positive_risk_justification']);
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
