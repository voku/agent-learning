<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Action;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\Proposal;
use voku\AgentLearning\ProposalStatus;
use voku\AgentLearning\ProposalValidator;
use voku\AgentLearning\ValidationException;
use voku\AgentLearning\ValidationCase;

final class ProposalValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{Action, ProposalStatus}>
     */
    public static function supportedActionStatusCombinations(): iterable
    {
        foreach ([Action::ADD, Action::DELETE, Action::REPLACE] as $action) {
            yield $action->value . ' candidate' => [$action, ProposalStatus::CANDIDATE];
            yield $action->value . ' approved' => [$action, ProposalStatus::APPROVED];
            yield $action->value . ' rejected' => [$action, ProposalStatus::REJECTED];
            yield $action->value . ' applied' => [$action, ProposalStatus::APPLIED];
        }

        yield 'NO_DURABLE_LEARNING candidate' => [Action::NO_DURABLE_LEARNING, ProposalStatus::CANDIDATE];
        yield 'NO_DURABLE_LEARNING rejected' => [Action::NO_DURABLE_LEARNING, ProposalStatus::REJECTED];
        yield 'REJECT candidate' => [Action::REJECT, ProposalStatus::CANDIDATE];
        yield 'REJECT rejected' => [Action::REJECT, ProposalStatus::REJECTED];
    }

    /**
     * @dataProvider supportedActionStatusCombinations
     */
    public function testSupportedActionStatusCombinations(Action $action, ProposalStatus $status): void
    {
        $proposal = $this->createProposal($action, $status);

        $this->expectNotToPerformAssertions();
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    /**
     * @return iterable<string, array{Action, ProposalStatus}>
     */
    public static function unsupportedActionStatusCombinations(): iterable
    {
        yield 'NO_DURABLE_LEARNING approved' => [Action::NO_DURABLE_LEARNING, ProposalStatus::APPROVED];
        yield 'NO_DURABLE_LEARNING applied' => [Action::NO_DURABLE_LEARNING, ProposalStatus::APPLIED];
        yield 'REJECT approved' => [Action::REJECT, ProposalStatus::APPROVED];
        yield 'REJECT applied' => [Action::REJECT, ProposalStatus::APPLIED];
    }

    /**
     * @dataProvider unsupportedActionStatusCombinations
     */
    public function testUnsupportedActionStatusCombinationsFail(Action $action, ProposalStatus $status): void
    {
        $proposal = $this->createProposal($action, $status);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal action ' . $action->value . ' cannot use status=' . $status->value);
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testAppliedProposalRequiresApprovalMetadata(): void
    {
        $proposal = $this->createProposal(Action::REPLACE, ProposalStatus::APPLIED, false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('approved proposal requires approved_by and approved_at');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testInvalidProposalIdThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, id: 'proposal.invalid');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal id must match proposal.YYYY-MM-DD.NNN');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testMalformedCreatedAtThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, createdAt: '2026-06-09');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('malformed timestamp field: created_at');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testMultipleMutationsThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, extraRaw: ['mutations' => ['a', 'b']]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal contains several mutations in field: mutations');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testNoSourceFindingsThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, sourceFindings: []);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal requires source findings');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDurableProposalMissingTargetTypeThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, targetType: null);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('durable proposal requires target_type and target');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDurableProposalMissingTargetThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, target: null);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('durable proposal requires target_type and target');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDurableProposalMissingScopeThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, scope: []);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('durable proposal requires exact scope');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDurableProposalMissingBoundaryThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, boundary: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('durable proposal requires known boundary');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDurableProposalMissingValidationThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, validation: []);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('durable proposal requires validation list');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testAddProposalMissingNewWordingThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::CANDIDATE, new: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ADD proposal requires new wording');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testDeleteProposalMissingOldWordingThrows(): void
    {
        $proposal = $this->createProposal(Action::DELETE, ProposalStatus::CANDIDATE, old: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('DELETE proposal requires old wording');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testReplaceProposalMissingOldWordingThrows(): void
    {
        $proposal = $this->createProposal(Action::REPLACE, ProposalStatus::CANDIDATE, old: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('REPLACE proposal requires old and new wording');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testReplaceProposalMissingNewWordingThrows(): void
    {
        $proposal = $this->createProposal(Action::REPLACE, ProposalStatus::CANDIDATE, new: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('REPLACE proposal requires old and new wording');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testMalformedApprovedAtThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::APPROVED, approvedAt: '2026-06-09');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('malformed timestamp field: approved_at');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testRejectedProposalMissingReasonThrows(): void
    {
        $proposal = $this->createProposal(Action::ADD, ProposalStatus::REJECTED, reason: '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('rejected proposal requires a reason');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testCreateSkillRequiresOverlapCheck(): void
    {
        $proposal = $this->createProposal(
            Action::ADD,
            ProposalStatus::CANDIDATE,
            extraRaw: [
                'learning_decision' => 'CREATE_SKILL',
                'pattern_key' => 'skills.distill_learning',
                'validation_case' => [
                    'given' => 'a repeated workflow correction has no owning skill',
                    'when' => 'the consolidation result proposes CREATE_SKILL',
                    'then' => 'the proposal records the inspected skill overlap',
                ],
            ]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('CREATE_SKILL requires overlap_check');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    public function testCreateSkillRejectsHighOverlap(): void
    {
        $proposal = $this->createProposal(
            Action::ADD,
            ProposalStatus::CANDIDATE,
            extraRaw: [
                'learning_decision' => 'CREATE_SKILL',
                'pattern_key' => 'skills.distill_learning',
                'validation_case' => [
                    'given' => 'a repeated workflow correction has an owning skill',
                    'when' => 'the consolidation result proposes CREATE_SKILL',
                    'then' => 'the proposal is rejected because UPDATE_SKILL is the correct path',
                ],
                'overlap_check' => [
                    'inspected' => ['agent-learning-consumer'],
                    'max_overlap_percent' => 75,
                    'decision' => 'Existing skill owns this behavior.',
                ],
            ]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('CREATE_SKILL overlap exceeds update threshold');
        (new ProposalValidator())->validate($proposal, 'proposal.json');
    }

    /**
     * @param list<string> $scope
     * @param list<string> $sourceFindings
     * @param list<string> $validation
     * @param array<string, mixed> $extraRaw
     */
    private function createProposal(
        Action $action,
        ProposalStatus $status,
        bool $withApprovalMetadata = true,
        string $id = 'proposal.2026-06-09.001',
        string $createdAt = '2026-06-09T10:00:00+00:00',
        ?string $targetType = 'skill',
        ?string $target = 'agent-learning',
        array $scope = ['src/'],
        array $sourceFindings = ['finding.2026-06-09.001'],
        ?string $old = null,
        ?string $new = null,
        string $reason = 'Evidence supports the proposed change.',
        ?string $boundary = 'Only this package behavior is covered.',
        array $validation = ['Run composer test.'],
        ?string $approvedAt = '2026-06-09T11:00:00+00:00',
        array $extraRaw = [],
    ): Proposal {
        $isDurable = $action !== Action::NO_DURABLE_LEARNING;
        $isApprovedLifecycle = $status === ProposalStatus::APPROVED || $status === ProposalStatus::APPLIED;
        $isRejectedLifecycle = $status === ProposalStatus::REJECTED || $action === Action::REJECT;
        
        $oldWording = $old ?? ($action === Action::ADD ? null : 'Old guidance.');
        $newWording = $new ?? ($action === Action::DELETE || $action === Action::REJECT ? null : 'New guidance.');
        if ($action === Action::NO_DURABLE_LEARNING) {
            $oldWording = null;
            $newWording = null;
        }

        $raw = [
            'id' => $id,
            'created_at' => $createdAt,
            'action' => $action->value,
            'target_type' => $isDurable ? $targetType : null,
            'target' => $isDurable ? $target : null,
            'scope' => $isDurable ? $scope : [],
            'source_findings' => $sourceFindings,
            'old' => $oldWording,
            'new' => $newWording,
            'reason' => $isRejectedLifecycle && $reason === 'Evidence supports the proposed change.' ? 'No durable learning is supported by this evidence.' : $reason,
            'boundary' => $isDurable ? $boundary : null,
            'validation' => $isDurable ? $validation : [],
            'status' => $status->value,
            'proposed_by' => 'agent',
            'approved_by' => $isApprovedLifecycle && $withApprovalMetadata ? 'maintainer' : null,
            'approved_at' => $isApprovedLifecycle && $withApprovalMetadata ? $approvedAt : null,
        ];
        
        $raw = array_merge($raw, $extraRaw);
        $learningDecision = null;
        if (is_string($raw['learning_decision'] ?? null)) {
            $learningDecision = LearningClassification::from($raw['learning_decision']);
        }
        $validationCase = null;
        if (is_array($raw['validation_case'] ?? null)) {
            /** @var array<string, mixed> $validationCaseRecord */
            $validationCaseRecord = $raw['validation_case'];
            $validationCase = ValidationCase::fromArray($validationCaseRecord, 'validation_case', 'proposal.json', null, $id);
        }

        return new Proposal(
            id: $id,
            createdAt: $createdAt,
            action: $action,
            targetType: $raw['target_type'],
            target: $raw['target'],
            scope: $raw['scope'],
            sourceFindings: $raw['source_findings'],
            old: $raw['old'],
            new: $raw['new'],
            reason: $raw['reason'],
            boundary: $raw['boundary'],
            validation: $raw['validation'],
            status: $status,
            proposedBy: 'agent',
            approvedBy: $raw['approved_by'],
            approvedAt: $raw['approved_at'],
            raw: $raw,
            learningDecision: $learningDecision,
            patternKey: is_string($raw['pattern_key'] ?? null) ? $raw['pattern_key'] : null,
            validationCase: $validationCase,
        );
    }
}
