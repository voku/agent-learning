<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ConsolidationResultValidator;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\ValidationException;
use voku\AgentLearning\AddResult;
use voku\AgentLearning\NoDurableLearningResult;

final class ConsolidationResultValidatorTest extends TestCase
{
    /** @var array<string, Finding> */
    private array $findings;

    protected function setUp(): void
    {
        $raw = ['id' => 'f-1', 'status' => 'validated'];
        $finding = new Finding(
            'f-1',
            'T-1',
            'sess',
            '2026-06-12T12:00:00+00:00',
            'actor',
            ['src/Auth'],
            'obs',
            [],
            'hyp',
            'conc',
            'high',
            'validated',
            FindingStatus::VALIDATED,
            'public',
            $raw
        );
        $this->findings = ['f-1' => $finding];
    }

    public function testValidatesAndInstantiatesResult(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Need auth details here.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'new' => 'Do not use globals.',
            'boundary' => 'boundary info',
            'validation' => ['test'],
        ];

        $res = $validator->validate($data, $this->findings);
        self::assertInstanceOf(AddResult::class, $res);
    }

    public function testThrowsOnForbiddenApprovalFields(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Need auth details here.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'status' => 'approved', // forbidden approval field!
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('forbidden field supplied');
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnBroadenedScopeWithoutJustification(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'], // finding scope is src/Auth
            'reason' => 'too short', // broadened scope requires >= 15 char justification in reason
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/'], // broadened!
            'new' => 'wording',
            'boundary' => 'boundary',
            'validation' => [],
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('broadened scope requires justification in reason');
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnDisjointScopeWithoutJustification(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'], // finding scope is src/Auth
            'reason' => 'too short',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Database'], // disjoint!
            'new' => 'wording',
            'boundary' => 'boundary',
            'validation' => [],
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('broadened scope requires justification in reason');
        $validator->validate($data, $this->findings);
    }
}
