<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Action;
use voku\AgentLearning\Proposal;
use voku\AgentLearning\ProposalStatus;
use voku\AgentLearning\RejectedGuidance;
use voku\AgentLearning\RejectedGuidanceSelector;

final class RejectedGuidanceSelectorTest extends TestCase
{
    public function testSelectsCorrectGuidances(): void
    {
        $proposal1 = $this->createProposal('prop-1', ['src/Auth'], ['finding-1'], 'skill.auth');
        $guidance1 = new RejectedGuidance('rej-1', $proposal1, 'reason-1');

        $proposal2 = $this->createProposal('prop-2', ['src/Database'], ['finding-2'], 'skill.db');
        $guidance2 = new RejectedGuidance('rej-2', $proposal2, 'reason-2');

        $selector = new RejectedGuidanceSelector();
        $all = [$guidance1, $guidance2];

        // Match by scope overlap
        $selected = $selector->select($all, scopes: ['src/Auth']);
        self::assertCount(1, $selected);
        self::assertSame('rej-1', $selected[0]->id);

        // Match by finding ID overlap
        $selected = $selector->select($all, findingIds: ['finding-2']);
        self::assertCount(1, $selected);
        self::assertSame('rej-2', $selected[0]->id);

        // Match by target
        $selected = $selector->select($all, target: 'skill.auth');
        self::assertCount(1, $selected);
        self::assertSame('rej-1', $selected[0]->id);

        // Match by explicit proposal ID
        $selected = $selector->select($all, explicitProposalIds: ['prop-2']);
        self::assertCount(1, $selected);
        self::assertSame('rej-2', $selected[0]->id);

        // Exclude unrelated
        $selected = $selector->select($all, scopes: ['src/Cache']);
        self::assertEmpty($selected);
    }

    /**
     * @param list<string> $scope
     * @param list<string> $findings
     */
    private function createProposal(string $id, array $scope, array $findings, string $target): Proposal
    {
        return new Proposal(
            $id,
            '2026-06-12T12:00:00+00:00',
            Action::ADD,
            'skill',
            $target,
            $scope,
            $findings,
            null,
            'new',
            'reason',
            'boundary',
            [],
            ProposalStatus::REJECTED,
            'actor',
            null,
            null,
            []
        );
    }
}
