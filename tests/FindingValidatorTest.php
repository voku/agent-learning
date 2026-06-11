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

    private function createFinding(
        string $taskId,
        FindingStatus $status = FindingStatus::VALIDATED,
        string $validationStatus = 'validated',
    ): Finding
    {
        $validatedConclusion = $validationStatus === 'validated' ? 'The validated conclusion.' : null;

        return new Finding(
            id: 'finding.2026-06-09.001',
            taskId: $taskId,
            session: 'sess123',
            createdAt: '2026-06-09T10:00:00+00:00',
            createdBy: 'agent',
            scope: ['src/'],
            observation: 'An observation description.',
            evidence: [
                ['type' => 'manual_verification', 'summary' => 'verified manual run']
            ],
            hypothesis: 'A hypothetical explanation.',
            validatedConclusion: $validatedConclusion,
            confidence: 'medium',
            validationStatus: $validationStatus,
            status: $status,
            sensitivity: 'public',
            raw: [
                'id' => 'finding.2026-06-09.001',
                'task_id' => $taskId,
                'session' => 'sess123',
                'created_at' => '2026-06-09T10:00:00+00:00',
                'created_by' => 'agent',
                'scope' => ['src/'],
                'observation' => 'An observation description.',
                'evidence' => [
                    ['type' => 'manual_verification', 'summary' => 'verified manual run']
                ],
                'hypothesis' => 'A hypothetical explanation.',
                'validated_conclusion' => $validatedConclusion,
                'confidence' => 'medium',
                'validation_status' => $validationStatus,
                'status' => $status->value,
                'sensitivity' => 'public',
            ]
        );
    }
}
