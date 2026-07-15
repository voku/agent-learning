<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\GuidanceEvolutionEvaluator;
use voku\AgentLearning\GuidanceOutcomeEventRepository;
use voku\AgentLearning\GuidanceUsageProjector;
use voku\AgentLearning\RecallSelectionEventRepository;
use voku\AgentLearning\Cli;
use voku\AgentLearning\EvolutionDecisionType;
use voku\AgentLearning\FindingRepository;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\ProposalRepository;
use voku\AgentLearning\ValidationException;

final class GuidanceEvolutionEvaluatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guidance-evolution-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/approved', 0777, true);
        mkdir($this->root . '/proposals/rejected', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testProjectionRebuildIsDeterministic(): void
    {
        $this->appendSelection('recall-selection.2026-06-18.002', 'compilation.PROJECT-2.001', 'PROJECT-2', 'proposal.2026-06-18.100', 'memory');
        $this->appendSelection('recall-selection.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'memory');
        $this->appendOutcome('guidance-outcome.2026-06-18.002', 'compilation.PROJECT-2.001', 'PROJECT-2', 'proposal.2026-06-18.100', 'not_used', false);
        $this->appendOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'helpful', true);

        $selectionEvents = (new RecallSelectionEventRepository())->load($this->root);
        $outcomeEvents = (new GuidanceOutcomeEventRepository())->load($this->root);
        $first = (new GuidanceUsageProjector())->project($selectionEvents, $outcomeEvents);
        $second = (new GuidanceUsageProjector())->project($selectionEvents, $outcomeEvents);

        self::assertEquals($first, $second);
        self::assertSame(2, $first['proposal.2026-06-18.100']->selectedCount);
        self::assertSame(1, $first['proposal.2026-06-18.100']->appliedCount);
        self::assertSame(['PROJECT-1', 'PROJECT-2'], $first['proposal.2026-06-18.100']->distinctTaskIds);
    }

    public function testDuplicateEventAndMalformedJsonlAreRejectedWithContext(): void
    {
        $this->appendSelection('recall-selection.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'memory');
        $this->appendSelection('recall-selection.2026-06-18.001', 'compilation.PROJECT-2.001', 'PROJECT-2', 'proposal.2026-06-18.100', 'memory');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('duplicate recall selection event ID');
        (new RecallSelectionEventRepository())->load($this->root);
    }

    public function testUnknownSchemaAndMalformedLineAreRejected(): void
    {
        file_put_contents($this->root . '/history/recall-selections.jsonl', json_encode([
            'schema_version' => '9.0',
            'id' => 'recall-selection.2026-06-18.001',
        ], JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported recall selection schema version');
        (new RecallSelectionEventRepository())->load($this->root);

        file_put_contents($this->root . '/history/recall-selections.jsonl', "{bad\n");
        try {
            (new RecallSelectionEventRepository())->load($this->root);
            self::fail('Malformed JSONL must throw.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('history/recall-selections.jsonl', $exception->getMessage());
            self::assertStringContainsString('malformed JSONL', $exception->getMessage());
        }
    }

    public function testSelectedDoesNotImplyAppliedOrHelpful(): void
    {
        $this->appendSelection('recall-selection.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'memory');
        $this->appendOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'unknown', false);

        $summary = $this->summaries()['proposal.2026-06-18.100'];

        self::assertSame(1, $summary->selectedCount);
        self::assertSame(0, $summary->appliedCount);
        self::assertSame(0, $summary->helpfulCount);
        self::assertSame(1, $summary->unknownCount);
    }

    public function testDogfoodEmptyGuidanceFixtureDoesNotInventTelemetry(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/dogfood-learning-loop/recall/iteration-001';
        $meta = json_decode((string)file_get_contents($fixtureRoot . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
        $draft = json_decode((string)file_get_contents($fixtureRoot . '/recall-log.draft.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $meta['selected_guidance']);
        self::assertSame([], $meta['selected_constraints']);
        self::assertSame([], $draft['selected']);
        self::assertSame([], $draft['guidance_outcomes']);
        self::assertSame([], $draft['applied']);
        self::assertSame([], $draft['helpful']);
        self::assertSame([], $draft['irrelevant']);
        self::assertSame([], $draft['harmful']);
        self::assertNotContains('none', $draft['selected']);
        self::assertStringNotContainsString('"guidance_id":"none"', json_encode($draft, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('"outcome":"not_used"', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    public function testDogfoodSelectedGuidanceFixtureProjectsOneSignalWithoutPromotion(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/dogfood-learning-loop/learning-root';
        $findings = (new FindingRepository())->loadValidated($fixtureRoot);
        $proposals = (new ProposalRepository())->loadAll($fixtureRoot, $findings);
        $selectionEvents = (new RecallSelectionEventRepository())->load($fixtureRoot);
        $outcomeEvents = (new GuidanceOutcomeEventRepository())->load($fixtureRoot);

        $summaries = (new GuidanceUsageProjector())->project($selectionEvents, $outcomeEvents);
        $summary = $summaries['proposal.2026-06-19.001'];

        self::assertSame(1, $summary->eligibleCount);
        self::assertSame(1, $summary->selectedCount);
        self::assertSame(1, $summary->appliedCount);
        self::assertSame(1, $summary->helpfulCount);
        self::assertSame(0, $summary->irrelevantCount);
        self::assertSame(0, $summary->harmfulCount);
        self::assertSame(0, $summary->notUsedCount);
        self::assertSame(0, $summary->unknownCount);
        self::assertSame(['DOGFOOD-2'], $summary->distinctTaskIds);

        $result = (new GuidanceEvolutionEvaluator())->evaluate($findings, $proposals, $selectionEvents, $outcomeEvents);
        self::assertCount(1, $result->decisions);
        self::assertSame(EvolutionDecisionType::NO_ACTION, $result->decisions[0]->type);
        self::assertSame('proposal.2026-06-19.001', $result->decisions[0]->guidanceId);
    }

    public function testDogfoodSelectedGuidanceFixtureWritesNoCandidateProposal(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/dogfood-learning-loop/learning-root';
        $root = sys_get_temp_dir() . '/dogfood-learning-loop-fixture-' . bin2hex(random_bytes(8));

        try {
            $this->copyDirectory($fixtureRoot, $root);

            $argv = [
                'agent-learning',
                'guidance-evaluate',
                '--root',
                $root,
                '--write-candidates',
            ];

            ob_start();
            try {
                self::assertSame(0, (new Cli())->run($argv));
            } finally {
                ob_end_clean();
            }

            self::assertSame([], glob($root . '/proposals/candidate/*.json') ?: []);
            self::assertDirectoryDoesNotExist($root . '/proposals/candidate');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testValidateIncludesDogfoodRecallHistoryEvents(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/dogfood-learning-loop/learning-root';
        $result = (new \voku\AgentLearning\LearningRepositoryValidator())->validate($fixtureRoot);

        self::assertCount(1, $result->findingsById);
        self::assertCount(1, $result->proposalsById);
        self::assertCount(1, $result->recallSelectionEvents);
        self::assertCount(1, $result->guidanceOutcomeEvents);
    }

    public function testValidateRejectsDogfoodGuidanceOutcomeWithoutSelection(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/dogfood-learning-loop/learning-root';
        $root = sys_get_temp_dir() . '/dogfood-learning-loop-invalid-history-' . bin2hex(random_bytes(8));

        try {
            $this->copyDirectory($fixtureRoot, $root);
            file_put_contents($root . '/history/recall-selections.jsonl', '');

            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('guidance outcome has no corresponding recall selection');
            (new \voku\AgentLearning\LearningRepositoryValidator())->validate($root);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRepeatedSelectionWithoutHelpfulOutcomesDoesNotPromote(): void
    {
        $this->writeFinding('finding.2026-06-18.001', 'PROJECT-1');
        $this->writeMemoryProposal();
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'memory');
            $this->appendOutcome(sprintf('guidance-outcome.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'not_used', false);
        }

        $decisions = $this->decisions();

        self::assertFalse($this->hasDecision($decisions, EvolutionDecisionType::PROMOTION_CANDIDATE, 'proposal.2026-06-18.100'));
    }

    public function testHelpfulOutcomesAcrossIndependentTasksMayPromoteMemoryToSkill(): void
    {
        $this->writeFinding('finding.2026-06-18.001', 'PROJECT-1');
        $this->writeFinding('finding.2026-06-18.002', 'PROJECT-2');
        $this->writeMemoryProposal(['finding.2026-06-18.001', 'finding.2026-06-18.002']);
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'memory');
            $this->appendOutcome(sprintf('guidance-outcome.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', $i < 3 ? 'helpful' : 'unknown', $i < 3);
        }

        $decisions = $this->decisions();

        self::assertTrue($this->hasDecision($decisions, EvolutionDecisionType::PROMOTION_CANDIDATE, 'proposal.2026-06-18.100'));
    }

    public function testHarmfulOutcomeBlocksPromotion(): void
    {
        $this->writeFinding('finding.2026-06-18.001', 'PROJECT-1');
        $this->writeFinding('finding.2026-06-18.002', 'PROJECT-2');
        $this->writeMemoryProposal(['finding.2026-06-18.001', 'finding.2026-06-18.002']);
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'memory');
            $this->appendOutcome(sprintf('guidance-outcome.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', $i === 3 ? 'harmful' : 'helpful', true);
        }

        self::assertFalse($this->hasDecision($this->decisions(), EvolutionDecisionType::PROMOTION_CANDIDATE, 'proposal.2026-06-18.100'));
    }

    public function testConstraintInactivityDoesNotCreateStaleCandidateButHarmfulEvidenceDoes(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'constraint.auth', 'constraint', true, false);
        }
        self::assertFalse($this->hasDecision($this->decisions(), EvolutionDecisionType::STALE_CANDIDATE, 'constraint.auth'));

        $this->appendOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'constraint.auth', 'harmful', true);
        self::assertTrue($this->hasDecision($this->decisions(), EvolutionDecisionType::STALE_CANDIDATE, 'constraint.auth'));
    }

    public function testOutcomeWithoutCorrespondingSelectionIsRejected(): void
    {
        $this->appendOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100', 'helpful', true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('guidance outcome has no corresponding recall selection');
        $this->summaries();
    }

    public function testWriteCandidatesWritesOnlyCandidateProposalsWithProvenanceAndIsIdempotent(): void
    {
        $this->writeFinding('finding.2026-06-18.001', 'PROJECT-1');
        $this->writeFinding('finding.2026-06-18.002', 'PROJECT-2');
        $this->writeMemoryProposal(['finding.2026-06-18.001', 'finding.2026-06-18.002']);
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'memory');
            $this->appendOutcome(sprintf('guidance-outcome.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', $i < 3 ? 'helpful' : 'unknown', $i < 3);
        }

        $argv = ['agent-learning', 'guidance-evaluate', '--root', $this->root, '--write-candidates'];
        ob_start();
        try {
            self::assertSame(0, (new Cli())->run($argv));
            self::assertSame(0, (new Cli())->run($argv));
        } finally {
            ob_end_clean();
        }

        $files = glob($this->root . '/proposals/candidate/*.json');
        self::assertIsArray($files);
        self::assertCount(2, $files);
        self::assertSame([], glob($this->root . '/proposals/applied/*.json') ?: []);
        self::assertSame([], glob($this->root . '/proposals/approved/proposal.2026-06-18.00*.json') ?: []);

        $candidate = null;
        foreach ($files as $file) {
            $record = json_decode((string)file_get_contents($file), true);
            if (($record['evolution_decision']['guidance_id'] ?? null) === 'proposal.2026-06-18.100') {
                $candidate = $record;
            }
        }
        self::assertIsArray($candidate);
        self::assertSame('candidate', $candidate['status']);
        self::assertSame('PROMOTION_CANDIDATE', $candidate['evolution_decision']['decision_type']);
        self::assertContains('guidance-outcome.2026-06-18.001', $candidate['evolution_decision']['evidence_event_ids']);
        self::assertContains('recall-selection.2026-06-18.001', $candidate['evolution_decision']['evidence_event_ids']);
    }

    public function testStaleMemoryCandidateRetainsApprovedScopeJustification(): void
    {
        $this->writeFinding('finding.2026-06-18.001', 'PROJECT-1', ['src/Auth/UserService.php']);
        $this->writeMemoryProposal(
            ['finding.2026-06-18.001'],
            ['src/Auth'],
            'The directory-level procedure applies to the evidenced auth service.',
        );
        for ($i = 1; $i <= 3; $i++) {
            $this->appendSelection(sprintf('recall-selection.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'memory');
            $this->appendOutcome(sprintf('guidance-outcome.2026-06-18.%03d', $i), "compilation.PROJECT-$i.001", "PROJECT-$i", 'proposal.2026-06-18.100', 'not_used', false);
        }

        $argv = ['agent-learning', 'guidance-evaluate', '--root', $this->root, '--write-candidates'];
        ob_start();
        try {
            self::assertSame(0, (new Cli())->run($argv));
        } finally {
            ob_end_clean();
        }

        $files = glob($this->root . '/proposals/candidate/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $candidate = json_decode((string)file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('STALE_CANDIDATE', $candidate['evolution_decision']['decision_type']);
        self::assertSame('The directory-level procedure applies to the evidenced auth service.', $candidate['scope_justification']);
    }

    /**
     * @return array<string, \voku\AgentLearning\GuidanceUsageSummary>
     */
    private function summaries(): array
    {
        return (new GuidanceUsageProjector())->project(
            (new RecallSelectionEventRepository())->load($this->root),
            (new GuidanceOutcomeEventRepository())->load($this->root),
        );
    }

    /**
     * @return list<\voku\AgentLearning\EvolutionDecision>
     */
    private function decisions(): array
    {
        $findings = (new FindingRepository())->loadValidated($this->root);
        $proposals = (new ProposalRepository())->loadAll($this->root, $findings);

        return (new GuidanceEvolutionEvaluator())->evaluate(
            $findings,
            $proposals,
            (new RecallSelectionEventRepository())->load($this->root),
            (new GuidanceOutcomeEventRepository())->load($this->root),
        )->decisions;
    }

    /**
     * @param list<\voku\AgentLearning\EvolutionDecision> $decisions
     */
    private function hasDecision(array $decisions, EvolutionDecisionType $type, string $guidanceId): bool
    {
        foreach ($decisions as $decision) {
            if ($decision->type === $type && $decision->guidanceId === $guidanceId) {
                return true;
            }
        }

        return false;
    }

    private function appendSelection(string $id, string $compilationId, string $taskId, string $guidanceId, string $guidanceType, bool $eligible = true, bool $selected = true): void
    {
        $record = [
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => $compilationId,
            'task_id' => $taskId,
            'guidance_id' => $guidanceId,
            'guidance_type' => $guidanceType,
            'eligible' => $eligible,
            'selected' => $selected,
            'selection_reason' => $selected ? 'scope_overlap' : null,
            'exclusion_reason' => $selected ? null : 'no_scope_overlap',
            'task_files' => ['src/Auth/UserService.php'],
            'recorded_at' => '2026-06-18T10:00:00+00:00',
        ];
        file_put_contents($this->root . '/history/recall-selections.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    private function appendOutcome(string $id, string $compilationId, string $taskId, string $guidanceId, string $outcome, bool $applied): void
    {
        $record = [
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => $compilationId,
            'task_id' => $taskId,
            'guidance_id' => $guidanceId,
            'outcome' => $outcome,
            'applied' => $applied,
            'comment' => 'fixture',
            'commit' => 'abc1234',
            'recorded_by' => 'test',
            'recorded_at' => '2026-06-18T12:00:00+00:00',
        ];
        file_put_contents($this->root . '/history/outcomes.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    /**
     * @param list<string> $sourceFindings
     * @param list<string> $scope
     */
    private function writeMemoryProposal(
        array $sourceFindings = ['finding.2026-06-18.001'],
        array $scope = ['src/Auth'],
        ?string $scopeJustification = null,
    ): void
    {
        $record = [
            'schema_version' => '1.0',
            'id' => 'proposal.2026-06-18.100',
            'created_at' => '2026-06-18T09:00:00+00:00',
            'action' => 'ADD',
            'target_type' => GuidanceType::MEMORY->value,
            'target' => 'memory.auth',
            'scope' => $scope,
            'source_findings' => $sourceFindings,
            'new' => 'Procedure: before changing auth services, check the auth context boundary and validation command.',
            'reason' => 'Recurring auth work needs the same memory.',
            'boundary' => 'Auth service work only.',
            'validation' => ['vendor/bin/phpunit'],
            'status' => 'approved',
            'proposed_by' => 'test',
            'approved_by' => 'test',
            'approved_at' => '2026-06-18T09:10:00+00:00',
            'recurring_procedure' => true,
        ];
        if ($scopeJustification !== null) {
            $record['scope_justification'] = $scopeJustification;
        }
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-18.100.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $scope
     */
    private function writeFinding(string $id, string $taskId, array $scope = ['src/Auth']): void
    {
        $record = [
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session_' . $taskId,
            'created_at' => '2026-06-18T08:00:00+00:00',
            'created_by' => 'test',
            'scope' => $scope,
            'observation' => 'Auth service changes repeatedly need context-boundary checks.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Auth/UserService.php', 'line' => 1]],
            'hypothesis' => 'Auth service work benefits from checking the context boundary.',
            'validated_conclusion' => 'Auth service work requires a repeatable context-boundary check.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'auth.context_boundary',
        ];
        file_put_contents($this->root . '/findings/validated/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            self::fail('Fixture directory does not exist: ' . $source);
        }

        if (!is_dir($destination)) {
            $mkdirError = null;
            set_error_handler(
                static function (int $severity, string $message) use (&$mkdirError): bool {
                    $mkdirError = $message;

                    return true;
                }
            );
            try {
                $created = mkdir($destination, 0777, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($destination)) {
                $details = $mkdirError !== null ? ' (' . $mkdirError . ')' : '';
                self::fail('Could not create fixture copy directory: ' . $destination . $details);
            }
        }

        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $file) {
            $sourcePath = $source . '/' . $file;
            $destinationPath = $destination . '/' . $file;
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            copy($sourcePath, $destinationPath);
        }
    }
}
