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

    public function testThrowsOnMissingAction(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or invalid action');
        $validator->validate([], $this->findings);
    }

    public function testThrowsOnUnknownAction(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unknown action: MAGIC');
        $validator->validate(['action' => 'MAGIC'], $this->findings);
    }

    public function testThrowsOnUnknownFieldForAction(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unknown field for action ADD: extra_field');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'new' => 'Do not use globals.',
            'boundary' => 'boundary info',
            'validation' => ['test'],
            'extra_field' => 'forbidden',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnMissingSourceFindings(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or empty source_findings');
        $validator->validate(['action' => 'ADD', 'source_findings' => []], $this->findings);
    }

    public function testThrowsOnInvalidFindingIdInSourceFindings(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('invalid finding ID at index 0');
        $validator->validate(['action' => 'ADD', 'source_findings' => [123]], $this->findings);
    }

    public function testThrowsOnNonExistentSourceFinding(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('invalid reference: source finding f-999 does not exist');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-999'],
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnMissingReason(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or empty reason');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnInvalidRemainingUncertainty(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('remaining_uncertainty must be an array');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'remaining_uncertainty' => 'not an array',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringRemainingUncertainty(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('remaining_uncertainty must contain only strings');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'remaining_uncertainty' => [123],
        ];
        $validator->validate($data, $this->findings);
    }

    public function testValidatesNoDurableLearning(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'NO_DURABLE_LEARNING',
            'source_findings' => ['f-1'],
            'reason' => 'Just some temporary task finding.',
            'existing_guidance_id' => 'skill.auth',
        ];
        $res = $validator->validate($data, $this->findings);
        self::assertInstanceOf(NoDurableLearningResult::class, $res);
    }

    public function testValidatesAddLearningNoteWithoutDurableMutation(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'NO_DURABLE_LEARNING',
            'source_findings' => ['f-1'],
            'reason' => 'Keep this as a raw learning note until the pattern recurs.',
            'learning_decision' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'skills.distill_learning',
            'validation_case' => [
                'given' => 'a session produces a useful but unpromoted learning',
                'when' => 'the consolidation step runs',
                'then' => 'it records the pattern key and validation case without creating a skill',
            ],
        ];

        $res = $validator->validate($data, $this->findings);

        self::assertInstanceOf(NoDurableLearningResult::class, $res);
        self::assertSame('ADD_LEARNING_NOTE', $res->learningDecision?->value);
        self::assertSame('skills.distill_learning', $res->patternKey);
    }

    public function testValidatesCreateSkillWithOverlapGate(): void
    {
        $validator = new ConsolidationResultValidator();
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'The behavior is repeated and no inspected existing skill owns it.',
            'target_type' => 'skill',
            'target' => 'skill.review-distillation',
            'scope' => ['src/Auth'],
            'new' => 'Distill repeated review findings into bounded guidance with validation cases.',
            'boundary' => 'Only applies to durable review findings, not one-off task notes.',
            'validation' => ['Run the skill validation command.'],
            'learning_decision' => 'CREATE_SKILL',
            'pattern_key' => 'skills.review_distillation',
            'validation_case' => [
                'given' => 'a review finding repeats across sessions',
                'when' => 'the agent proposes a new skill',
                'then' => 'it records inspected overlap and a concrete behavior check',
            ],
            'overlap_check' => [
                'inspected' => ['agent-learning-consumer', 'agent-learning-maintainer'],
                'max_overlap_percent' => 35,
                'decision' => 'No existing skill owns review distillation.',
            ],
        ];

        $res = $validator->validate($data, $this->findings);

        self::assertInstanceOf(AddResult::class, $res);
        self::assertSame('CREATE_SKILL', $res->learningDecision?->value);
        self::assertSame('skills.review_distillation', $res->patternKey);
    }

    public function testThrowsOnInvalidExistingGuidanceIdInNoDurable(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('existing_guidance_id must be a non-empty string');
        $data = [
            'action' => 'NO_DURABLE_LEARNING',
            'source_findings' => ['f-1'],
            'reason' => 'Just some temporary task finding.',
            'existing_guidance_id' => '',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnUnsupportedTargetType(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or unsupported target_type');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'magic',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnMissingTarget(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or empty target');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => '',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnMissingScope(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing or empty scope');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => [],
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringScopeItem(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('scope must contain only non-empty strings');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => [123],
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringOldContent(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('old content must be a string');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'old' => 123,
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringNewContent(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('new content must be a string');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'new' => 123,
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringBoundary(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('boundary must be a string');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'boundary' => 123,
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnInvalidValidationList(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('validation must be an array');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'validation' => 'not an array',
        ];
        $validator->validate($data, $this->findings);
    }

    public function testThrowsOnNonStringValidationItem(): void
    {
        $validator = new ConsolidationResultValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('validation must contain only strings');
        $data = [
            'action' => 'ADD',
            'source_findings' => ['f-1'],
            'reason' => 'Some valid reason long enough.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/Auth'],
            'validation' => [123],
        ];
        $validator->validate($data, $this->findings);
    }
}
