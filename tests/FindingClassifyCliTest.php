<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingValidator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteService;

final class FindingClassifyCliTest extends TestCase
{
    public function testCreateThenClassifyProducesPromotableFinding(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-09-12.92c001');

        self::assertFalse((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);

        [$exitCode, $output] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            LearningClassification::ADD_LEARNING_NOTE->value,
            '--pattern-key', 'finding.authoring.promotable',
            '--given', 'A validated Finding has no learning triage yet.',
            '--when', 'The Finding is explicitly classified after capture.',
            '--then', 'LearningNote promotion readiness becomes satisfiable.',
        ]);

        self::assertSame(0, $exitCode, $output);
        /** @var array{id: string, classification: string} $result */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($findingId, $result['id']);
        self::assertSame(LearningClassification::ADD_LEARNING_NOTE->value, $result['classification']);

        $finding = (new FindingValidator())->validateFile($root . '/findings/validated/' . $findingId . '.json');
        self::assertSame(LearningClassification::ADD_LEARNING_NOTE, $finding->classification);
        self::assertTrue((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);
    }

    public function testInvalidClassificationDoesNotChangeFinding(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-09-12.92c002');
        $path = $root . '/findings/validated/' . $findingId . '.json';
        $before = file_get_contents($path);
        self::assertIsString($before);

        [$exitCode, $output] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            'MAYBE_LEARNING',
        ]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('unsupported learning classification: MAYBE_LEARNING', $output);
        self::assertSame($before, file_get_contents($path));
    }

    public function testReusableClassificationReportsAllMissingPromotionMetadata(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-09-12.92c003');
        $path = $root . '/findings/validated/' . $findingId . '.json';
        $before = file_get_contents($path);
        self::assertIsString($before);

        [$exitCode, $output] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            LearningClassification::ADD_LEARNING_NOTE->value,
        ]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString(
            'finding-classify missing required options: --pattern-key, --given, --when, --then',
            $output,
        );
        self::assertSame($before, file_get_contents($path));
    }

    private function createLearningRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-finding-classify-cli-' . bin2hex(random_bytes(8)) . '/.agent-loop/learning';
        self::assertTrue(mkdir($root . '/findings', 0777, true));

        return $root;
    }

    private function createFinding(string $root, string $id): string
    {
        [$exitCode, $output] = $this->runCli($root, [
            'finding-create',
            '--id', $id,
            '--task', 'PROJECT-92',
            '--session', 'session_PROJECT-92',
            '--by', 'test',
            '--scope', 'src/',
            '--observation', 'Supported creation captures a valid Finding without reusable-learning triage.',
            '--hypothesis', 'A later classification command should own durable-learning triage.',
            '--conclusion', 'Observation capture and reusable-learning classification are separate operations.',
            '--confidence', 'high',
            '--sensitivity', 'public',
            '--evidence-json', json_encode([
                'type' => 'manual_verification',
                'summary' => 'Promotion readiness lacks a supported authoring path.',
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(0, $exitCode, $output);
        /** @var array{id: string} $created */
        $created = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $created['id'];
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runCli(string $root, array $arguments): array
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/../bin/agent-learning',
            ...$arguments,
            '--root',
            $root,
        ];
        $output = [];
        $exitCode = 0;
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
