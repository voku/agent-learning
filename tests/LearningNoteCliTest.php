<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;

final class LearningNoteCliTest extends TestCase
{
    public function testPreparePublishAndStatusUseOwnerContract(): void
    {
        $base = sys_get_temp_dir() . '/learning-note-cli-' . bin2hex(random_bytes(6));
        $root = $base . '/learning';
        $projectRoot = $base . '/project';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($projectRoot . '/src', 0777, true);
        file_put_contents($projectRoot . '/src/Example.php', "<?php\n");
        file_put_contents($root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../project',
            'constraint_generation_dir' => 'constraint-generation',
            'active_constraints_dir' => 'constraints/active',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($root . '/findings/validated/finding.2026-08-31.001.json', json_encode([
            'id' => 'finding.2026-08-31.001',
            'task_id' => 'TEST-54',
            'session' => 'session_TEST-54',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/'],
            'observation' => 'A consumer reconstructed owner behavior.',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in the fixture.']],
            'hypothesis' => 'The missing owner boundary causes drift.',
            'validated_conclusion' => 'The owner boundary must remain explicit.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'workflow.owner_boundary',
            'validation_case' => [
                'given' => 'A later related task.',
                'when' => 'The same owner boundary applies.',
                'then' => 'The prior case is available as precedent.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $prepare = $this->run($root, ['prepare', '--finding', 'finding.2026-08-31.001']);
        self::assertSame(0, $prepare['exit_code'], $prepare['output']);
        $prepared = json_decode($prepare['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('workflow.owner_boundary', $prepared['pattern_key']);
        self::assertSame('finding.2026-08-31.001', $prepared['findings'][0]['id']);

        $candidate = $base . '/candidate.json';
        file_put_contents($candidate, json_encode([
            'source_findings' => ['finding.2026-08-31.001'],
            'source_proposals' => [],
            'tags' => ['workflow'],
            'repository_evidence' => [[
                'source_ref' => 'src/Example.php',
                'sha256' => hash_file('sha256', $projectRoot . '/src/Example.php'),
            ]],
            'content' => [
                'title' => 'Owner boundary precedent',
                'context' => 'A consumer needed owner-controlled state.',
                'guidance' => 'Use the typed owner boundary.',
                'why_it_works' => 'The consumer no longer reconstructs private state.',
                'when_to_apply' => 'When sibling packages need Learning-owned facts.',
                'when_not_to_apply' => 'When the caller owns the data itself.',
                'verification' => 'Run the owner and installed-consumer regressions.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $publish = $this->run($root, ['publish', '--input', $candidate]);
        self::assertSame(0, $publish['exit_code'], $publish['output']);
        $published = json_decode($publish['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('workflow.owner_boundary', $published['pattern_key']);
        self::assertSame('current', $published['evidence_state']);
        self::assertSame('Use the typed owner boundary.', $published['content']['guidance']);

        $status = $this->run($root, ['status']);
        self::assertSame(0, $status['exit_code'], $status['output']);
        $statusData = json_decode($status['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $statusData['notes']);
        self::assertSame($published['id'], $statusData['notes'][0]['id']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, output: string}
     */
    private function run(string $root, array $arguments): array
    {
        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../bin/agent-learning-note'),
        ];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $parts[] = '--root';
        $parts[] = escapeshellarg($root);
        $parts[] = '2>&1';

        exec(implode(' ', $parts), $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }
}
