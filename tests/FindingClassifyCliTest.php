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

    public function testClassifyNoDurableLearningPreservesPatternLineageViaCli(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-09-14.481001');

        [$exitCode, $output] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            LearningClassification::NO_DURABLE_LEARNING->value,
            '--pattern-key', 'phpstan.in_process_rule_test_case',
            '--given', 'A rule test case requires in-process isolation.',
            '--when', 'The test runs without callback state encapsulation.',
            '--then', 'Shared state is constant-folded by the analyzer.',
        ]);

        self::assertSame(0, $exitCode, $output);
        /** @var array{id: string, classification: string} $result */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($findingId, $result['id']);
        self::assertSame(LearningClassification::NO_DURABLE_LEARNING->value, $result['classification']);

        $finding = (new FindingValidator())->validateFile($root . '/findings/validated/' . $findingId . '.json');
        self::assertSame(LearningClassification::NO_DURABLE_LEARNING, $finding->classification);
        self::assertSame('phpstan.in_process_rule_test_case', $finding->patternKey);
        self::assertNotNull($finding->validationCase);
        self::assertFalse((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);
    }


    public function testCandidateCannotBeClassifiedBeforeReviewerValidation(): void
    {
        $root = $this->createLearningRoot();
        $findingId = 'finding.2026-09-19.131002';

        [$captureExit, $captureOutput] = $this->runCli($root, [
            'finding-capture',
            '--id', $findingId,
            '--task', 'PROJECT-131',
            '--by', 'tester',
            '--observation', 'Classification was reachable before reviewer validation.',
            '--hypothesis', 'Reusable-learning triage must follow explicit validation.',
            '--evidence', 'Reproduced through the supported public CLI.',
        ]);
        self::assertSame(0, $captureExit, $captureOutput);

        [$classifyExit, $classifyOutput] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            LearningClassification::IGNORE->value,
        ]);

        self::assertSame(1, $classifyExit, $classifyOutput);
        self::assertStringContainsString(
            'finding-classify requires status validated; current status is candidate',
            $classifyOutput,
        );
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

    public function testFromProposalCopiesConsolidationTriageAndMakesFindingNoteReady(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-11-19.f00001');
        $this->writeProposal($root, 'candidate', 'proposal.2026-11-19.p00001', [$findingId]);

        self::assertFalse((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);

        [$exitCode, $output] = $this->runCli($root, ['finding-classify', $findingId, '--from-proposal', 'proposal.2026-11-19.p00001']);

        self::assertSame(0, $exitCode, $output);
        $finding = (new FindingValidator())->validateFile($root . '/findings/validated/' . $findingId . '.json');
        self::assertSame(LearningClassification::ADD_LEARNING_NOTE, $finding->classification);
        self::assertSame('process.runner.selects.host.wrapper', $finding->patternKey);
        self::assertNotNull($finding->validationCase);
        self::assertSame('a Composer bin directory with both wrappers', $finding->validationCase->given);
        self::assertTrue((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);
        self::assertFileExists($root . '/proposals/candidate/proposal.2026-11-19.p00001.json', 'the proposal is not transitioned');
    }

    public function testFromProposalAcceptsMatchingExplicitClassification(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-11-19.f00002');
        $this->writeProposal($root, 'candidate', 'proposal.2026-11-19.p00002', [$findingId]);

        [$exitCode, $output] = $this->runCli($root, ['finding-classify', $findingId, 'ADD_LEARNING_NOTE', '--from-proposal', 'proposal.2026-11-19.p00002']);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<string>, 3: string}>
     */
    public static function rejectedFromProposalCases(): iterable
    {
        yield 'proposal does not cite the finding' => ['candidate', ['finding.2026-11-19.f00999'], [], 'proposal does not cite finding'];
        yield 'proposal already decided' => ['rejected', [], [], 'requires a candidate proposal; current status is rejected'];
        yield 'explicit classification disagrees' => ['candidate', [], ['UPDATE_SKILL'], 'proposal learning_decision is ADD_LEARNING_NOTE, not the requested UPDATE_SKILL'];
        yield 'manual triage mixed in' => ['candidate', [], ['--pattern-key', 'other.key'], 'do not combine it with --pattern-key'];
        yield 'empty manual option mixed in' => ['candidate', [], ['--given='], 'do not combine it with --given'];
    }

    /**
     * @param list<string> $citedOverride
     * @param list<string> $extraArguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedFromProposalCases')]
    public function testFromProposalRefusesAndLeavesFindingUnchanged(string $status, array $citedOverride, array $extraArguments, string $expectedError): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-11-19.f00003');
        $this->writeProposal($root, $status, 'proposal.2026-11-19.p00003', $citedOverride === [] ? [$findingId] : $citedOverride);
        $findingPath = $root . '/findings/validated/' . $findingId . '.json';
        $before = file_get_contents($findingPath);

        [$exitCode, $output] = $this->runCli($root, ['finding-classify', $findingId, ...$extraArguments, '--from-proposal', 'proposal.2026-11-19.p00003']);

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
        self::assertSame($before, file_get_contents($findingPath));
    }

    public function testBareFromProposalIsRefusedInsteadOfFallingBackToOrdinaryClassification(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-11-19.f00004');
        [$classifyExit, $classifyOutput] = $this->runCli($root, [
            'finding-classify', $findingId, 'ADD_LEARNING_NOTE',
            '--pattern-key', 'existing.triage',
            '--given', 'g', '--when', 'w', '--then', 't',
        ]);
        self::assertSame(0, $classifyExit, $classifyOutput);
        $findingPath = $root . '/findings/validated/' . $findingId . '.json';
        $before = file_get_contents($findingPath);

        // Without the presence check this ran an ordinary IGNORE classification and dropped the triage.
        foreach ([['--from-proposal'], ['--from-proposal=']] as $option) {
            [$exitCode, $output] = $this->runCli($root, ['finding-classify', $findingId, 'IGNORE', ...$option]);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString('--from-proposal requires a proposal ID', $output);
            self::assertSame($before, file_get_contents($findingPath));
        }
    }

    /**
     * @param list<string> $sourceFindings
     */
    private function writeProposal(string $root, string $status, string $id, array $sourceFindings): void
    {
        $directory = $root . '/proposals/' . $status;
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        $record = [
            'id' => $id,
            'created_at' => '2026-11-19T10:00:00+00:00',
            'action' => 'ADD',
            'source_findings' => $sourceFindings,
            'reason' => 'Consolidation classified the lesson as LearningNote material.',
            'remaining_uncertainty' => ['Single manifestation.'],
            'status' => $status,
            'proposed_by' => 'consolidation',
            'approved_by' => null,
            'approved_at' => null,
            'target_type' => 'memory',
            'target' => 'Host-appropriate executable selection',
            'scope' => ['src/'],
            'old' => null,
            'new' => 'Select the host-appropriate wrapper before Windows .bat files.',
            'boundary' => 'Host-side process selection only.',
            'validation' => ['composer ci'],
            'scope_justification' => 'Repeatable host-boundary lesson.',
            'learning_decision' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'process.runner.selects.host.wrapper',
            'validation_case' => [
                'given' => 'a Composer bin directory with both wrappers',
                'when' => 'a Linux runner picks the first existing file',
                'then' => 'it selects the host-appropriate executable',
            ],
        ];
        if ($status === 'rejected') {
            $record['rejected_by'] = 'tester';
            $record['rejected_at'] = '2026-11-19T11:00:00+00:00';
            $record['rejection_reason'] = 'Not durable.';
        }
        file_put_contents($directory . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
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
