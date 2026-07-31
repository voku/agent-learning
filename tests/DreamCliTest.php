<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Cli;

final class DreamCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dream-cli-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        file_put_contents($this->root . '/findings/validated/finding.2026-06-01.001.json', json_encode([
            'id' => 'finding.2026-06-01.001',
            'task_id' => 'TASK-1',
            'session' => 'session.TASK-1',
            'created_at' => '2026-06-01T00:00:00+00:00',
            'created_by' => 'tester',
            'scope' => ['src'],
            'observation' => 'Observation is concrete.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Example.php', 'line' => 1]],
            'hypothesis' => 'Hypothesis is distinct.',
            'validated_conclusion' => 'Conclusion is validated.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'dream.cli',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDryRunWritesStableJsonReportWithoutCandidateMutation(): void
    {
        $report = $this->root . '/.agent-loop/dream/latest.json';
        $exit = (new Cli())->run([
            'agent-learning',
            'dream',
            '--root', $this->root,
            '--project-root', $this->root,
            '--report', $report,
            '--format', 'json',
            '--dry-run',
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($report);
        $first = (string)file_get_contents($report);
        self::assertSame(0, (new Cli())->run([
            'agent-learning', 'dream', '--root', $this->root, '--project-root', $this->root,
            '--report', $report, '--format', 'json', '--dry-run',
        ]));
        self::assertSame($first, (string)file_get_contents($report));
        self::assertSame([], glob($this->root . '/proposals/candidate/*.json') ?: []);
        $reportData = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $reportData['evaluated_guidance_count']);
        self::assertArrayHasKey('metrics', $reportData);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            unlink($path);
        }
        rmdir($directory);
    }
}
