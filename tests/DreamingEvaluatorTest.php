<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Action;
use voku\AgentLearning\DreamingEvaluator;
use voku\AgentLearning\DreamWarning;
use voku\AgentLearning\EvolutionDecisionType;
use voku\AgentLearning\EvolutionDecision;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\GuidanceConflictPolicy;
use voku\AgentLearning\GuidanceOutcomeEvent;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\Proposal;
use voku\AgentLearning\ProposalStatus;
use voku\AgentLearning\RecallSelectionEvent;
use voku\AgentLearning\ReplacementCandidatePolicy;

final class DreamingEvaluatorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/dreaming-evaluator-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->projectRoot);
    }

    public function testAuditSeparatesMissingOutcomesFromUnknownAndIsDeterministic(): void
    {
        $finding = $this->finding('finding.2026-06-01.001', 'TASK-1', 'one conclusion', 'missing.php');
        $selection = $this->selection('recall-selection.2026-06-01.001', 'TASK-1');
        $evaluator = new DreamingEvaluator();
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        $first = $evaluator->evaluate([$finding->id => $finding], [], [$selection], [], projectRoot: $this->projectRoot, reviewHorizonDays: 20, now: $now);
        $second = $evaluator->evaluate([$finding->id => $finding], [], [$selection], [], projectRoot: $this->projectRoot, reviewHorizonDays: 20, now: $now);

        self::assertEquals($first, $second);
        self::assertSame(['evidence_reference_unresolvable', 'finding_review_horizon_exceeded', 'outcome_missing'], array_map(static fn (DreamWarning $warning): string => $warning->code, $first->warnings));
        self::assertSame(1, $first->metrics->selectedGuidanceCount);
        self::assertSame(0, $first->metrics->explicitOutcomeCount);
        self::assertSame(0.0, $first->metrics->outcomeCompletenessRate);

        $unknown = $this->outcome('guidance-outcome.2026-06-01.001', 'TASK-1', 'unknown');
        $withUnknown = $evaluator->evaluate([$finding->id => $finding], [], [$selection], [$unknown], projectRoot: $this->projectRoot, reviewHorizonDays: 20, now: $now);
        self::assertSame(['evidence_reference_unresolvable', 'finding_review_horizon_exceeded', 'outcome_unknown'], array_map(static fn (DreamWarning $warning): string => $warning->code, $withUnknown->warnings));
        self::assertSame(1, $withUnknown->metrics->outcomeSignals['unknown']);
    }

    public function testMetricsJoinOutcomesBySelectedIdentityAndLeaveAnEmptyHistoryUndefined(): void
    {
        $finding = $this->finding('finding.2026-06-01.001', 'TASK-1', 'one conclusion');
        $selection = $this->selection('recall-selection.2026-06-01.001', 'TASK-1');
        $duplicateSelection = $this->selection('recall-selection.2026-06-01.002', 'TASK-1');
        $outcome = $this->outcome('guidance-outcome.2026-06-01.001', 'TASK-1', 'helpful');
        $duplicateOutcome = $this->outcome('guidance-outcome.2026-06-01.002', 'TASK-1', 'helpful');
        $evaluator = new DreamingEvaluator();

        $empty = $evaluator->evaluate([$finding->id => $finding], [], [], []);
        self::assertSame(0, $empty->metrics->selectedGuidanceCount);
        self::assertNull($empty->metrics->outcomeCompletenessRate);

        $result = $evaluator->evaluate(
            [$finding->id => $finding],
            [],
            [$selection, $duplicateSelection],
            [$outcome, $duplicateOutcome],
        );
        self::assertSame(1, $result->metrics->selectedGuidanceCount);
        self::assertSame(1, $result->metrics->explicitOutcomeCount);
        self::assertSame(1.0, $result->metrics->outcomeCompletenessRate);
    }

    public function testMetricsMeasureDuplicateProducerOverlapBeforeDeduplication(): void
    {
        $decision = new EvolutionDecision(
            EvolutionDecisionType::NO_ACTION,
            'proposal.2026-06-01.001',
            GuidanceType::MEMORY,
            null,
            [],
            [],
            'No action.',
            'No uncertainty.',
            [],
            [],
        );
        $evaluator = new DreamingEvaluator();
        $metrics = new \ReflectionMethod($evaluator, 'metrics');
        $result = $metrics->invoke(
            $evaluator,
            [],
            [],
            [],
            [],
            [$decision],
            [],
            1,
            [],
            new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
        );

        self::assertSame(1, $result->duplicateDecisionCount);
        self::assertSame(1, $result->reviewableDecisionCount);
    }

    public function testReplacementPolicyRequiresExplicitSuccessor(): void
    {
        $oldFinding = $this->finding('finding.2026-06-01.001', 'TASK-1', 'old conclusion');
        $newFinding = $this->finding('finding.2026-06-02.001', 'TASK-2', 'new conclusion');
        $applied = $this->proposal(
            'proposal.2026-06-01.001',
            ProposalStatus::APPLIED,
            Action::ADD,
            'Use the old wording.',
            null,
            [$oldFinding->id],
        );
        $successor = $this->proposal(
            'proposal.2026-06-02.001',
            ProposalStatus::APPROVED,
            Action::REPLACE,
            'Use the corrected wording.',
            'Use the old wording.',
            [$newFinding->id],
        );

        $decisions = (new ReplacementCandidatePolicy())->evaluate(
            [$applied->id => $applied, $successor->id => $successor],
            [$oldFinding->id => $oldFinding, $newFinding->id => $newFinding],
        );

        self::assertCount(1, $decisions);
        self::assertSame(EvolutionDecisionType::REPLACEMENT_CANDIDATE, $decisions[0]->type);
        self::assertSame($applied->id, $decisions[0]->guidanceId);
        self::assertSame('Use the old wording.', $decisions[0]->oldText);
        self::assertSame('Use the corrected wording.', $decisions[0]->newText);
        self::assertSame(['TASK-2'], $decisions[0]->independentTaskIds);
    }

    public function testReplacementPolicyAcceptsExplicitNewerFindingLineageAndHarmfulCorrectedSuccessor(): void
    {
        $oldFinding = $this->finding('finding.2026-06-01.001', 'TASK-1', 'old conclusion');
        $newFinding = $this->finding('finding.2026-06-02.001', 'TASK-2', 'new conclusion', rawExtras: ['supersedes_findings' => [$oldFinding->id]]);
        $old = $this->proposal('proposal.2026-06-01.001', ProposalStatus::APPLIED, Action::ADD, 'Use old wording.', null, [$oldFinding->id]);
        $successor = $this->proposal(
            'proposal.2026-06-02.001',
            ProposalStatus::APPROVED,
            Action::REPLACE,
            'Use narrower corrected wording.',
            'A differently formatted old wording.',
            [$newFinding->id],
            ['supersedes_proposal_id' => $old->id, 'corrects_proposal_id' => $old->id],
        );

        $decisions = (new ReplacementCandidatePolicy())->evaluate(
            [$old->id => $old, $successor->id => $successor],
            [$oldFinding->id => $oldFinding, $newFinding->id => $newFinding],
            [$this->outcome('guidance-outcome.2026-06-02.001', 'TASK-3', 'harmful')],
        );

        self::assertCount(1, $decisions);
        self::assertSame('Use narrower corrected wording.', $decisions[0]->newText);
        self::assertContains('guidance-outcome.2026-06-02.001', $decisions[0]->evidenceEventIds);
    }

    public function testConflictPolicyReportsConflictingValidatedConclusionsWithoutChoosingOne(): void
    {
        $left = $this->finding('finding.2026-06-01.001', 'TASK-1', 'Always validate before changing.', conflictsWith: ['finding.2026-06-02.001']);
        $right = $this->finding('finding.2026-06-02.001', 'TASK-2', 'Never validate before changing.');

        $decisions = (new GuidanceConflictPolicy())->evaluate([$left->id => $left, $right->id => $right], []);

        self::assertCount(1, $decisions);
        self::assertSame(EvolutionDecisionType::CONFLICT, $decisions[0]->type);
        self::assertSame(Action::NO_DURABLE_LEARNING, $decisions[0]->proposalAction);
        self::assertSame([$left->id, $right->id], $decisions[0]->sourceFindings);
        self::assertNull($decisions[0]->newText);
    }

    public function testConflictPolicyReportsExplicitLineageAndCrossTierDuplicatesWithoutMutation(): void
    {
        $source = $this->finding('finding.2026-06-01.001', 'TASK-1', 'validated source');
        $contradiction = $this->finding(
            'finding.2026-06-02.001',
            'TASK-2',
            'later invalidated evidence',
            status: FindingStatus::SUPERSEDED,
            rawExtras: ['contradicts_proposal_id' => 'proposal.2026-06-01.001'],
        );
        $memory = $this->proposal('proposal.2026-06-01.001', ProposalStatus::APPLIED, Action::ADD, 'Use one canonical rule.', null, [$source->id]);
        $skill = $this->proposal('proposal.2026-06-02.001', ProposalStatus::APPROVED, Action::ADD, 'Use one canonical rule.', null, [$source->id], targetType: GuidanceType::SKILL->value);

        $decisions = (new GuidanceConflictPolicy())->evaluate(
            [$source->id => $source, $contradiction->id => $contradiction],
            [$memory->id => $memory, $skill->id => $skill],
        );

        self::assertCount(2, $decisions);
        foreach ($decisions as $decision) {
            self::assertSame(Action::NO_DURABLE_LEARNING, $decision->proposalAction);
        }
        self::assertStringContainsString('conflict.duplicate', $decisions[0]->guidanceId . $decisions[1]->guidanceId);
        self::assertStringContainsString('conflict.lineage', $decisions[0]->guidanceId . $decisions[1]->guidanceId);
    }

    /**
     * @param list<string> $conflictsWith
     * @param array<string, mixed> $rawExtras
     */
    private function finding(string $id, string $taskId, string $conclusion, string $path = 'src/Existing.php', array $conflictsWith = [], FindingStatus $status = FindingStatus::VALIDATED, array $rawExtras = []): Finding
    {
        return new Finding(
            $id,
            $taskId,
            'session.' . $taskId,
            '2026-06-01T00:00:00+00:00',
            'tester',
            ['src/Auth'],
            'Observation',
            [['type' => 'file_reference', 'path' => $path, 'line' => 1]],
            'Hypothesis',
            $conclusion,
            'high',
            'validated',
            $status,
            'public',
            array_merge($conflictsWith === [] ? [] : ['conflicts_with' => $conflictsWith], $rawExtras),
            patternKey: 'auth.context_boundary',
        );
    }

    private function selection(string $id, string $taskId): RecallSelectionEvent
    {
        return new RecallSelectionEvent(
            $id,
            'compilation.' . $taskId . '.001',
            $taskId,
            'proposal.2026-06-01.001',
            GuidanceType::MEMORY,
            true,
            true,
            'scope_overlap',
            null,
            ['src/Auth'],
            '2026-06-01T00:00:00+00:00',
            [],
        );
    }

    private function outcome(string $id, string $taskId, string $outcome): GuidanceOutcomeEvent
    {
        return new GuidanceOutcomeEvent(
            $id,
            'compilation.' . $taskId . '.001',
            $taskId,
            'proposal.2026-06-01.001',
            \voku\AgentLearning\OutcomeValue::from($outcome),
            false,
            null,
            'abc1234',
            'tester',
            '2026-06-01T01:00:00+00:00',
            [],
        );
    }

    /**
     * @param list<string> $sourceFindings
     * @param array<string, mixed> $raw
     * @param list<string> $scope
     */
    private function proposal(string $id, ProposalStatus $status, Action $action, string $new, ?string $old, array $sourceFindings, array $raw = [], array $scope = ['src/Auth'], ?string $targetType = null): Proposal
    {
        $date = $id === 'proposal.2026-06-01.001' ? '2026-06-01T01:00:00+00:00' : '2026-06-02T01:00:00+00:00';

        return new Proposal(
            $id,
            $date,
            $action,
            $targetType ?? GuidanceType::MEMORY->value,
            'memory.auth',
            $scope,
            $sourceFindings,
            $old,
            $new,
            'Reason',
            'Boundary',
            ['phpunit'],
            $status,
            'tester',
            'reviewer',
            $date,
            $raw,
            patternKey: 'auth.context_boundary',
        );
    }
}
