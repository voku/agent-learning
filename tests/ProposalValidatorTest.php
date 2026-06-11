<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Action;
use voku\AgentLearning\Proposal;
use voku\AgentLearning\ProposalStatus;
use voku\AgentLearning\ProposalValidator;
use voku\AgentLearning\ValidationException;

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

    private function createProposal(
        Action $action,
        ProposalStatus $status,
        bool $withApprovalMetadata = true,
    ): Proposal {
        $isDurable = $action !== Action::NO_DURABLE_LEARNING;
        $isApprovedLifecycle = $status === ProposalStatus::APPROVED || $status === ProposalStatus::APPLIED;
        $isRejectedLifecycle = $status === ProposalStatus::REJECTED || $action === Action::REJECT;
        $old = $action === Action::ADD ? null : 'Old guidance.';
        $new = $action === Action::DELETE || $action === Action::REJECT ? null : 'New guidance.';
        if ($action === Action::NO_DURABLE_LEARNING) {
            $old = null;
            $new = null;
        }

        return new Proposal(
            id: 'proposal.2026-06-09.001',
            createdAt: '2026-06-09T10:00:00+00:00',
            action: $action,
            targetType: $isDurable ? 'skill' : null,
            target: $isDurable ? 'agent-learning' : null,
            scope: $isDurable ? ['src/'] : [],
            sourceFindings: ['finding.2026-06-09.001'],
            old: $old,
            new: $new,
            reason: $isRejectedLifecycle ? 'No durable learning is supported by this evidence.' : 'Evidence supports the proposed change.',
            boundary: $isDurable ? 'Only this package behavior is covered.' : null,
            validation: $isDurable ? ['Run composer test.'] : [],
            status: $status,
            proposedBy: 'agent',
            approvedBy: $isApprovedLifecycle && $withApprovalMetadata ? 'maintainer' : null,
            approvedAt: $isApprovedLifecycle && $withApprovalMetadata ? '2026-06-09T11:00:00+00:00' : null,
            raw: [
                'id' => 'proposal.2026-06-09.001',
                'created_at' => '2026-06-09T10:00:00+00:00',
                'action' => $action->value,
                'target_type' => $isDurable ? 'skill' : null,
                'target' => $isDurable ? 'agent-learning' : null,
                'scope' => $isDurable ? ['src/'] : [],
                'source_findings' => ['finding.2026-06-09.001'],
                'old' => $old,
                'new' => $new,
                'reason' => $isRejectedLifecycle ? 'No durable learning is supported by this evidence.' : 'Evidence supports the proposed change.',
                'boundary' => $isDurable ? 'Only this package behavior is covered.' : null,
                'validation' => $isDurable ? ['Run composer test.'] : [],
                'status' => $status->value,
                'proposed_by' => 'agent',
                'approved_by' => $isApprovedLifecycle && $withApprovalMetadata ? 'maintainer' : null,
                'approved_at' => $isApprovedLifecycle && $withApprovalMetadata ? '2026-06-09T11:00:00+00:00' : null,
            ],
        );
    }
}
