<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Finding;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingValidator;
use voku\AgentLearning\ValidationException;

final class FindingValidatorTest extends TestCase
{
    public function testDefaultTaskIdPatternMatchesProjectTask(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('PROJECT-123');

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    public function testDefaultTaskIdPatternMatchesShortProjectTask(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('GH-123');

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    public function testDefaultTaskIdPatternMatchesTODO(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('TODO@some/path');

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    public function testDefaultTaskIdPatternThrowsOnInvalidPrefix(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('project-123');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing task references');
        $validator->validate($finding, 'finding.json');
    }

    public function testCustomTaskIdPatternMatchesConfiguredValue(): void
    {
        $validator = new FindingValidator(
            taskIdPattern: '/^(CUSTOM-\d+|TODO@[\w:\/.-]+)$/'
        );
        $finding = $this->createFinding('CUSTOM-123');

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    public function testCustomTaskIdPatternThrowsOnDefaultStyleWhenConfiguredOtherwise(): void
    {
        $validator = new FindingValidator(
            taskIdPattern: '/^(CUSTOM-\d+|TODO@[\w:\/.-]+)$/'
        );
        $finding = $this->createFinding('PROJECT-123');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing task references');
        $validator->validate($finding, 'finding.json');
    }

    /**
     * @return iterable<string, array{FindingStatus, string}>
     */
    public static function supportedLifecycleCombinations(): iterable
    {
        yield 'candidate unverified' => [FindingStatus::CANDIDATE, 'unverified'];
        yield 'validated validated' => [FindingStatus::VALIDATED, 'validated'];
        yield 'invalidated invalidated' => [FindingStatus::INVALIDATED, 'invalidated'];
        yield 'consolidated validated' => [FindingStatus::CONSOLIDATED, 'validated'];
        yield 'superseded validated' => [FindingStatus::SUPERSEDED, 'validated'];
        yield 'superseded invalidated' => [FindingStatus::SUPERSEDED, 'invalidated'];
        yield 'rejected validated' => [FindingStatus::REJECTED, 'validated'];
        yield 'rejected invalidated' => [FindingStatus::REJECTED, 'invalidated'];
        yield 'archived unverified' => [FindingStatus::ARCHIVED, 'unverified'];
        yield 'archived validated' => [FindingStatus::ARCHIVED, 'validated'];
        yield 'archived invalidated' => [FindingStatus::ARCHIVED, 'invalidated'];
    }

    /**
     * @dataProvider supportedLifecycleCombinations
     */
    public function testSupportedLifecycleCombinations(FindingStatus $status, string $validationStatus): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('PROJECT-123', $status, $validationStatus);

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    /**
     * @return iterable<string, array{FindingStatus, string}>
     */
    public static function unsupportedLifecycleCombinations(): iterable
    {
        yield 'validated unverified' => [FindingStatus::VALIDATED, 'unverified'];
        yield 'candidate validated' => [FindingStatus::CANDIDATE, 'validated'];
        yield 'invalidated validated' => [FindingStatus::INVALIDATED, 'validated'];
        yield 'consolidated invalidated' => [FindingStatus::CONSOLIDATED, 'invalidated'];
        yield 'superseded unverified' => [FindingStatus::SUPERSEDED, 'unverified'];
        yield 'rejected unverified' => [FindingStatus::REJECTED, 'unverified'];
    }

    /**
     * @dataProvider unsupportedLifecycleCombinations
     */
    public function testUnsupportedLifecycleCombinationsFail(FindingStatus $status, string $validationStatus): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('PROJECT-123', $status, $validationStatus);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot use validation_status=' . $validationStatus);
        $validator->validate($finding, 'finding.json');
    }

    public function testInvalidFindingIdThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', id: 'finding.invalid');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('finding id must match finding.YYYY-MM-DD.NNN');
        $validator->validate($finding, 'finding.json');
    }

    public function testMalformedCreatedAtThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', createdAt: '2026-06-09');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('malformed timestamp field: created_at');
        $validator->validate($finding, 'finding.json');
    }

    public function testEmptyObservationThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', observation: '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty observation');
        $validator->validate($finding, 'finding.json');
    }

    public function testEmptyHypothesisThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', hypothesis: '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty hypothesis');
        $validator->validate($finding, 'finding.json');
    }

    public function testSameObservationAndHypothesisThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', observation: 'identical text', hypothesis: 'identical text');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('observation and hypothesis must remain separate');
        $validator->validate($finding, 'finding.json');
    }

    public function testUnsupportedConfidenceThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', confidence: 'extreme');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported confidence');
        $validator->validate($finding, 'finding.json');
    }

    public function testUnsupportedValidationStatusThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', validationStatus: 'unknown');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported validation_status');
        $validator->validate($finding, 'finding.json');
    }

    public function testValidatedWithoutConclusionThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(taskId: 'PROJECT-123', validationStatus: 'validated', validatedConclusion: '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('validated finding requires validated_conclusion');
        $validator->validate($finding, 'finding.json');
    }

    public function testHypothesisAsValidatedFactThrows(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding(
            taskId: 'PROJECT-123',
            validationStatus: 'validated',
            hypothesis: 'fact text',
            validatedConclusion: 'fact text'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('hypothesis presented as validated fact');
        $validator->validate($finding, 'finding.json');
    }

    private function createFinding(
        string $taskId,
        FindingStatus $status = FindingStatus::VALIDATED,
        string $validationStatus = 'validated',
        string $id = 'finding.2026-06-09.001',
        string $createdAt = '2026-06-09T10:00:00+00:00',
        string $observation = 'An observation description.',
        string $hypothesis = 'A hypothetical explanation.',
        string $confidence = 'medium',
        ?string $validatedConclusion = 'The validated conclusion.'
    ): Finding {
        $conclusion = $validationStatus === 'validated' ? ($validatedConclusion ?? 'The validated conclusion.') : null;

        return new Finding(
            id: $id,
            taskId: $taskId,
            session: 'sess123',
            createdAt: $createdAt,
            createdBy: 'agent',
            scope: ['src/'],
            observation: $observation,
            evidence: [
                ['type' => 'manual_verification', 'summary' => 'verified manual run']
            ],
            hypothesis: $hypothesis,
            validatedConclusion: $conclusion,
            confidence: $confidence,
            validationStatus: $validationStatus,
            status: $status,
            sensitivity: 'public',
            raw: [
                'id' => $id,
                'task_id' => $taskId,
                'session' => 'sess123',
                'created_at' => $createdAt,
                'created_by' => 'agent',
                'scope' => ['src/'],
                'observation' => $observation,
                'evidence' => [
                    ['type' => 'manual_verification', 'summary' => 'verified manual run']
                ],
                'hypothesis' => $hypothesis,
                'validated_conclusion' => $conclusion,
                'confidence' => $confidence,
                'validation_status' => $validationStatus,
                'status' => $status->value,
                'sensitivity' => 'public',
            ]
        );
    }
}

