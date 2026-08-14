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
        self::assertSame('1.0', $reportData['schema_version']);
        self::assertSame('agent-learning-dream', $reportData['report_type']);
        self::assertSame(0, $reportData['evaluated_guidance_count']);
        self::assertArrayHasKey('metrics', $reportData);
    }

    public function testDefaultReportStatesHowMuchSelectedGuidanceWasActuallyJudged(): void
    {
        // Two compilations selected guidance; only one produced a judgement. The
        // rate was already computed and only reachable through --format json, so
        // the default output could not distinguish a repository holding real
        // usefulness evidence from one holding only compiler placeholders.
        $this->writeSelection('recall-selection.2026-06-02.001', 'compilation.TASK-1.001', 'proposal.2026-06-01.001');
        $this->writeSelection('recall-selection.2026-06-02.002', 'compilation.TASK-1.002', 'proposal.2026-06-01.002');
        $this->writeOutcome('guidance-outcome.2026-06-02.001', 'compilation.TASK-1.001', 'proposal.2026-06-01.001');

        // Run out of process: the report is written to STDOUT, which is exactly
        // the surface under test and the one an output buffer cannot observe.
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' dream --root ' . escapeshellarg($this->root)
            . ' --project-root ' . escapeshellarg($this->root)
            . ' --dry-run 2>&1';
        exec($command, $output, $exitCode);
        $report = implode("\n", $output);

        self::assertSame(0, $exitCode, $report);
        self::assertStringContainsString('Outcome completeness: 1/2 selected guidance judged (50%)', $report);
    }

    private function writeSelection(string $id, string $compilationId, string $guidanceId): void
    {
        file_put_contents($this->root . '/history/recall-selections.jsonl', json_encode([
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => $compilationId,
            'task_id' => 'TASK-1',
            'guidance_id' => $guidanceId,
            'guidance_type' => 'memory',
            'eligible' => true,
            'selected' => true,
            'selection_reason' => 'scope_overlap',
            'exclusion_reason' => null,
            'task_files' => ['src/Example.php'],
            'recorded_at' => '2026-06-02T00:00:00+00:00',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    private function writeOutcome(string $id, string $compilationId, string $guidanceId): void
    {
        file_put_contents($this->root . '/history/outcomes.jsonl', json_encode([
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => $compilationId,
            'task_id' => 'TASK-1',
            'guidance_id' => $guidanceId,
            'outcome' => 'helpful',
            'applied' => true,
            'comment' => 'Named the boundary this change had to respect.',
            'commit' => 'abc1234',
            'recorded_by' => 'tester',
            'recorded_at' => '2026-06-02T01:00:00+00:00',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    public function testHistoryProjectionDetectsStalenessAndRecoversAfterRebuild(): void
    {
        self::assertSame(0, (new Cli())->run([
            'agent-learning', 'history-rebuild', '--root', $this->root,
        ]));
        self::assertSame(0, (new Cli())->run([
            'agent-learning', 'history-status', '--root', $this->root,
        ]));

        $snapshot = $this->root . '/history/active-guidance.snapshot.json';
        file_put_contents($snapshot, "corrupted\n");
        self::assertSame(1, (new Cli())->run([
            'agent-learning', 'history-status', '--root', $this->root,
        ]));

        self::assertSame(0, (new Cli())->run([
            'agent-learning', 'history-rebuild', '--root', $this->root,
        ]));
        self::assertSame(0, (new Cli())->run([
            'agent-learning', 'history-status', '--root', $this->root,
        ]));
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
