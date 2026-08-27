<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\EvidenceValidator;
use voku\AgentLearning\FindingRepository;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingValidator;
use voku\AgentLearning\RecordIdGenerator;

final class FindingCreateCliTest extends TestCase
{
    public function testCreatesValidatedFindingAndMissingTargetDirectory(): void
    {
        $root = $this->createFreshLearningRoot();
        self::assertDirectoryDoesNotExist($root . '/findings/validated');

        [$exitCode, $output] = $this->runFindingCreate($root, [
            '--task', 'PROJECT-27',
            '--session', 'session_PROJECT-27',
            '--by', 'agent',
            '--scope', 'src/',
            '--scope', 'tests/',
            '--observation', 'Consumers had to hand-write the first Finding record.',
            '--hypothesis', 'Finding creation belongs behind the package owner boundary.',
            '--conclusion', 'The package owner must create validated Finding records.',
            '--confidence', 'high',
            '--sensitivity', 'public',
            '--evidence-json', $this->evidenceJson(),
        ]);

        self::assertSame(0, $exitCode, $output);
        /** @var array{id: string, path: string} $result */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression(RecordIdGenerator::pattern('finding'), $result['id']);
        self::assertSame($root . '/findings/validated/' . $result['id'] . '.json', $result['path']);
        self::assertFileExists($result['path']);

        $finding = (new FindingValidator())->validateFile($result['path']);
        self::assertSame(FindingStatus::VALIDATED, $finding->status);
        self::assertSame('validated', $finding->validationStatus);
        self::assertSame('The package owner must create validated Finding records.', $finding->validatedConclusion);
        self::assertSame(['src/', 'tests/'], $finding->scope);
        self::assertArrayHasKey($finding->id, (new FindingRepository())->loadValidated($root));
        $this->assertNoTemporaryFindingFiles($root);
    }

    public function testReportsAllMissingRequiredOptionsInOneFailure(): void
    {
        $root = $this->createFreshLearningRoot();

        [$exitCode, $output] = $this->runFindingCreate($root, [
            '--task', 'PROJECT-27',
            '--session', 'session_PROJECT-27',
        ]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString(
            'finding-create missing required options: --by, --observation, --hypothesis, --conclusion, --confidence, --sensitivity, --evidence-json',
            $output,
        );
        self::assertDirectoryDoesNotExist($root . '/findings/validated');
    }

    public function testInvalidFindingLeavesNoPartialTargetDirectory(): void
    {
        $root = $this->createFreshLearningRoot();

        [$exitCode, $output] = $this->runFindingCreate($root, [
            '--task', 'PROJECT-27',
            '--session', 'session_PROJECT-27',
            '--by', 'agent',
            '--observation', 'Evidence is required.',
            '--hypothesis', 'Invalid input must fail before publication.',
            '--conclusion', 'No target file may survive failed validation.',
            '--confidence', 'high',
            '--sensitivity', 'public',
        ]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('finding-create missing required options: --evidence-json', $output);
        self::assertDirectoryDoesNotExist($root . '/findings/validated');
    }

    public function testUnsupportedEvidenceTypeListsAcceptedVocabulary(): void
    {
        $root = $this->createFreshLearningRoot();

        [$exitCode, $output] = $this->runFindingCreate($root, [
            '--task', 'PROJECT-27',
            '--session', 'session_PROJECT-27',
            '--by', 'agent',
            '--observation', 'Evidence vocabulary should be discoverable from the failed request.',
            '--hypothesis', 'The validator owns the accepted evidence type vocabulary.',
            '--conclusion', 'Unsupported evidence types should report the accepted owner vocabulary.',
            '--confidence', 'high',
            '--sensitivity', 'public',
            '--evidence-json', json_encode(
                ['type' => 'unsupported_example', 'summary' => 'Invalid on purpose.'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('unsupported evidence type at index 0', $output);
        self::assertStringContainsString(
            'accepted types: ' . implode(', ', EvidenceValidator::supportedTypes()),
            $output,
        );
        self::assertDirectoryDoesNotExist($root . '/findings/validated');
    }

    public function testCustomTaskPatternIsUsedDuringCreation(): void
    {
        $root = $this->createFreshLearningRoot();

        [$exitCode, $output] = $this->runFindingCreate($root, [
            '--task', 'custom:27',
            '--task-id-pattern', '/^custom:\\d+$/',
            '--session', 'session_custom_27',
            '--by', 'agent',
            '--observation', 'The consumer uses a custom task identifier.',
            '--hypothesis', 'Creation must use the same task-id override as validation.',
            '--conclusion', 'The owner creation path honors the configured task-id pattern.',
            '--confidence', 'medium',
            '--sensitivity', 'public',
            '--evidence-json', $this->evidenceJson(),
        ]);

        self::assertSame(0, $exitCode, $output);
        /** @var array{id: string, path: string} $result */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertFileExists($result['path']);
    }

    public function testExplicitDuplicateIdDoesNotOverwriteExistingFinding(): void
    {
        $root = $this->createFreshLearningRoot();
        $id = 'finding.2026-08-17.abc123';
        $arguments = $this->validArguments($id);

        [$firstExitCode, $firstOutput] = $this->runFindingCreate($root, $arguments);
        self::assertSame(0, $firstExitCode, $firstOutput);
        /** @var array{id: string, path: string} $firstResult */
        $firstResult = json_decode($firstOutput, true, 512, JSON_THROW_ON_ERROR);
        $before = file_get_contents($firstResult['path']);
        self::assertIsString($before);

        [$secondExitCode, $secondOutput] = $this->runFindingCreate($root, $arguments);
        self::assertSame(1, $secondExitCode, $secondOutput);
        self::assertStringContainsString('duplicate finding ID', $secondOutput);
        self::assertSame($before, file_get_contents($firstResult['path']));
        $this->assertNoTemporaryFindingFiles($root);
    }

    public function testPublicationDoesNotReplaceUnexpectedExistingTarget(): void
    {
        $root = $this->createFreshLearningRoot();
        $directory = $root . '/findings/validated';
        self::assertTrue(mkdir($directory, 0777, true));
        $id = 'finding.2026-08-17.def456';
        $path = $directory . '/' . $id . '.json';
        self::assertSame(0, file_put_contents($path, ''));

        [$exitCode, $output] = $this->runFindingCreate($root, $this->validArguments($id));

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('finding file already exists', $output);
        self::assertSame('', file_get_contents($path));
        $this->assertNoTemporaryFindingFiles($root);
    }

    private function createFreshLearningRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-finding-create-' . bin2hex(random_bytes(8)) . '/.agent-loop/learning';
        if (!mkdir($root . '/findings', 0777, true) && !is_dir($root . '/findings')) {
            self::fail('Cannot create temporary Learning root.');
        }

        return $root;
    }

    private function evidenceJson(): string
    {
        return json_encode(
            ['type' => 'manual_verification', 'summary' => 'Reproduced in consumer dogfood.'],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string>
     */
    private function validArguments(string $id): array
    {
        return [
            '--id', $id,
            '--task', 'PROJECT-27',
            '--session', 'session_PROJECT-27',
            '--by', 'agent',
            '--observation', 'The first record must remain unchanged.',
            '--hypothesis', 'Explicit IDs must never permit overwriting evidence.',
            '--conclusion', 'Duplicate Finding IDs are rejected before publication.',
            '--confidence', 'high',
            '--sensitivity', 'public',
            '--evidence-json', $this->evidenceJson(),
        ];
    }

    private function assertNoTemporaryFindingFiles(string $root): void
    {
        $files = glob($root . '/findings/validated/.finding.*.tmp.*');
        self::assertIsArray($files);
        self::assertSame([], $files);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runFindingCreate(string $root, array $arguments): array
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/../bin/agent-learning',
            'finding-create',
            '--root',
            $root,
            ...$arguments,
        ];
        $escaped = array_map(escapeshellarg(...), $command);
        $output = [];
        $exitCode = 0;
        exec(implode(' ', $escaped) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
