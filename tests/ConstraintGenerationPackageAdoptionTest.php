<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\ConstraintGenerationPackageExporter;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingStatus;

final class ConstraintGenerationPackageAdoptionTest extends TestCase
{
    private string $project;

    private string $root;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/constraint_export_adoption_' . bin2hex(random_bytes(8));
        $this->root = $this->project . '/.agent-loop/learning';
        self::assertTrue(mkdir($this->root . '/proposals/approved', 0777, true));
        self::assertTrue(mkdir($this->project . '/tools', 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->project);
    }

    public function testExistingConstraintIsExportedForAdoptionWithoutDuplicateGenerationGuidance(): void
    {
        file_put_contents($this->project . '/tools/release-set-dogfood.php', "<?php\n");
        file_put_contents($this->project . '/composer.json', "{}\n");

        $output = $this->root . '/constraint-generation/proposal.2026-08-20.c2a001';
        (new ConstraintGenerationPackageExporter())->export(
            $this->root,
            $this->writeProposal(),
            $output,
            $this->findings(),
        );

        $specification = $this->readJson($output . '/specification.json');
        self::assertSame('adopt_existing', $specification['mode'] ?? null);

        $validationPlan = $this->readJson($output . '/validation-plan.json');
        self::assertSame('adopt_existing', $validationPlan['mode'] ?? null);
        self::assertSame([], $validationPlan['expected_fixtures'] ?? null);
        self::assertSame(['php tools/release-set-dogfood.php'], $validationPlan['commands'] ?? null);

        $prompt = (string) file_get_contents($output . '/generation-prompt.md');
        self::assertStringContainsString('# Constraint Adoption Prompt', $prompt);
        self::assertStringContainsString('Do not generate a duplicate rule or synthetic PHP fixtures.', $prompt);
        self::assertStringContainsString('historical bad/good states', $prompt);
        self::assertStringContainsString('existing constraint activation path', $prompt);
        self::assertStringNotContainsString('Generate a repository-local', $prompt);
        self::assertStringNotContainsString('valid, invalid, boundary, and false-positive fixtures', $prompt);
    }

    public function testMissingTargetKeepsGenerationPackageBehavior(): void
    {
        file_put_contents($this->project . '/composer.json', "{}\n");

        $output = $this->root . '/constraint-generation/proposal.2026-08-20.c2a001';
        (new ConstraintGenerationPackageExporter())->export(
            $this->root,
            $this->writeProposal(),
            $output,
            $this->findings(),
        );

        $this->assertGenerationPackage($output);
    }

    public function testMissingRegistrationKeepsGenerationPackageBehavior(): void
    {
        file_put_contents($this->project . '/tools/release-set-dogfood.php', "<?php\n");

        $output = $this->root . '/constraint-generation/proposal.2026-08-20.c2a001';
        (new ConstraintGenerationPackageExporter())->export(
            $this->root,
            $this->writeProposal(),
            $output,
            $this->findings(),
        );

        $this->assertGenerationPackage($output);
    }

    private function assertGenerationPackage(string $output): void
    {
        $specification = $this->readJson($output . '/specification.json');
        self::assertSame('generate', $specification['mode'] ?? null);

        $validationPlan = $this->readJson($output . '/validation-plan.json');
        self::assertSame('generate', $validationPlan['mode'] ?? null);
        self::assertSame(['valid.php', 'invalid.php', 'boundary.php', 'false-positive.php'], $validationPlan['expected_fixtures'] ?? null);

        $prompt = (string) file_get_contents($output . '/generation-prompt.md');
        self::assertStringContainsString('# Constraint Generation Prompt', $prompt);
        self::assertStringContainsString('Generate a repository-local ci rule', $prompt);
    }

    private function writeProposal(): string
    {
        $path = $this->root . '/proposals/approved/proposal.2026-08-20.c2a001.json';
        file_put_contents($path, json_encode([
            'id' => 'proposal.2026-08-20.c2a001',
            'created_at' => '2026-08-20T10:00:00+00:00',
            'action' => 'ADD',
            'target_type' => 'constraint',
            'source_findings' => [
                'finding.2026-08-20.001',
                'finding.2026-08-20.002',
            ],
            'reason' => 'Canonical recovery commands must advance the state they name.',
            'constraint' => [
                'rule_id' => 'workflow.recovery.next-action-must-advance',
                'engine' => 'ci',
                'rule_class_name' => 'RecoveryConvergenceGate',
                'target_rule_path' => 'tools/release-set-dogfood.php',
                'registration_files' => ['composer.json'],
                'scope' => ['src/Run/', 'tools/release-set-dogfood.php'],
                'violation' => 'A canonical recovery command repeats without advancing lifecycle state.',
                'allowed_boundaries' => ['decision_required', 'host_work'],
                'detectability' => 'runtime',
                'false_positive_risk' => 'low',
                'validation_commands' => ['php tools/release-set-dogfood.php'],
                'example_rule_paths' => [],
            ],
            'status' => 'approved',
            'proposed_by' => 'agent',
            'approved_by' => 'human',
            'approved_at' => '2026-08-20T10:30:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, Finding> */
    private function findings(): array
    {
        return [
            'finding.2026-08-20.001' => $this->finding('finding.2026-08-20.001'),
            'finding.2026-08-20.002' => $this->finding('finding.2026-08-20.002'),
        ];
    }

    private function finding(string $id): Finding
    {
        return new Finding(
            $id,
            'C3-ADOPT',
            'session_adoption',
            '2026-08-20T09:00:00+00:00',
            'agent',
            ['src/Run/', 'tools/release-set-dogfood.php'],
            'A canonical recovery command repeated without advancing lifecycle state.',
            [],
            'The existing CI recovery gate can enforce convergence.',
            'The historical bad state is reproducibly rejected by the existing CI gate.',
            'high',
            'validated',
            FindingStatus::VALIDATED,
            'public',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
