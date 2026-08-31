<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;

final class LearningNoteCliTest extends TestCase
{
    public function testPrepareAndPublishUseOwnerBoundaries(): void
    {
        $root = $this->rootWithFinding();
        [$prepareExit, $prepareOutput] = $this->runCli([
            'prepare',
            '--root', $root,
            '--finding', 'finding.2026-08-31.c0ffee',
        ]);
        self::assertSame(0, $prepareExit, $prepareOutput);
        /** @var array{pattern_key: string, source_findings: list<array<string, mixed>>} $prepared */
        $prepared = json_decode($prepareOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('workflow.learning-note', $prepared['pattern_key']);
        self::assertCount(1, $prepared['source_findings']);

        $content = json_encode([
            'title' => 'CLI precedent',
            'context' => 'A validated case exists.',
            'guidance' => 'Reuse it as precedent only.',
            'when_to_apply' => 'The same deterministic pattern matches.',
            'when_not_to_apply' => 'Current stronger authority disagrees.',
        ], JSON_THROW_ON_ERROR);
        [$publishExit, $publishOutput] = $this->runCli([
            'publish',
            '--root', $root,
            '--finding', 'finding.2026-08-31.c0ffee',
            '--content-json', $content,
        ]);
        self::assertSame(0, $publishExit, $publishOutput);
        /** @var array{id: string, pattern_key: string, path: string, digest: string} $published */
        $published = json_decode($publishOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('workflow.learning-note', $published['pattern_key']);
        self::assertFileExists($published['path']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $published['digest']);
    }

    private function rootWithFinding(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-note-cli-' . bin2hex(random_bytes(8)) . '/.agent-loop/learning';
        $directory = $root . '/findings/validated';
        self::assertTrue(mkdir($directory, 0777, true));
        $finding = [
            'id' => 'finding.2026-08-31.c0ffee',
            'task_id' => 'PROJECT-54',
            'session' => 'session_PROJECT-54',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'agent',
            'scope' => ['src/'],
            'observation' => 'One solved case should survive chat loss.',
            'evidence' => [[
                'type' => 'manual_verification',
                'summary' => 'Observed in a governed run.',
            ]],
            'hypothesis' => 'A LearningNote can preserve the useful context.',
            'validated_conclusion' => 'The case is reusable but does not yet justify active guidance.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'workflow.learning-note',
            'validation_case' => [
                'given' => 'A later task matches the same pattern.',
                'when' => 'The prior case is selected.',
                'then' => 'The agent sees bounded non-authoritative precedent.',
            ],
        ];
        self::assertNotFalse(file_put_contents(
            $directory . '/' . $finding['id'] . '.json',
            json_encode($finding, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ));

        return $root;
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runCli(array $arguments): array
    {
        $command = [PHP_BINARY, __DIR__ . '/../bin/agent-learning-note', ...$arguments];
        $output = [];
        $exitCode = 0;
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
