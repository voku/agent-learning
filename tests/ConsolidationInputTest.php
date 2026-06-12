<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ActiveGuidance;
use voku\AgentLearning\ConsolidationInput;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingSelection;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\RejectedGuidance;
use voku\AgentLearning\Proposal;
use voku\AgentLearning\Action;
use voku\AgentLearning\ProposalStatus;
use voku\AgentLearning\ValidationException;

final class ConsolidationInputTest extends TestCase
{
    public function testEnforcesFindingsCountLimit(): void
    {
        $selection = new FindingSelection([], [], [], null, null);
        $findings = [];
        for ($i = 0; $i < 6; $i++) {
            $findings[] = $this->createFinding('finding-' . $i);
        }

        $input = new ConsolidationInput($selection, $findings, [], []);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('findings limit exceeded');
        $input->toArray(maxFindings: 5);
    }

    public function testEnforcesBytesPerRecordLimit(): void
    {
        $selection = new FindingSelection([], [], [], null, null);
        $largeFinding = $this->createFinding('finding-large', str_repeat('A', 1000));
        $input = new ConsolidationInput($selection, [$largeFinding], [], []);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds limit 500 bytes');
        $input->toArray(maxBytesPerRecord: 500);
    }

    private function createFinding(string $id, string $observation = 'test'): Finding
    {
        $raw = [
            'id' => $id,
            'task_id' => 'TASK-1',
            'session' => 'sess',
            'created_at' => '2026-06-12T12:00:00+00:00',
            'created_by' => 'actor',
            'scope' => ['src/'],
            'observation' => $observation,
            'evidence' => [],
            'hypothesis' => 'hyp',
            'validated_conclusion' => 'conc',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ];
        return new Finding(
            $id,
            'TASK-1',
            'sess',
            '2026-06-12T12:00:00+00:00',
            'actor',
            ['src/'],
            $observation,
            [],
            'hyp',
            'conc',
            'high',
            'validated',
            FindingStatus::VALIDATED,
            'public',
            $raw
        );
    }
}
