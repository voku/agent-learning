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
    public function testDefaultTaskIdPatternMatchesITPNG(): void
    {
        $validator = new FindingValidator();
        $finding = $this->createFinding('ITPNG-123');

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
        $finding = $this->createFinding('OTHER-123');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing task references');
        $validator->validate($finding, 'finding.json');
    }

    public function testCustomTaskIdPatternMatchesConfiguredValue(): void
    {
        // Configure to accept task ids starting with OTHER-
        $validator = new FindingValidator(
            taskIdPattern: '/^(OTHER-\d+|TODO@[\w:\/.-]+)$/'
        );
        $finding = $this->createFinding('OTHER-123');

        $this->expectNotToPerformAssertions();
        $validator->validate($finding, 'finding.json');
    }

    public function testCustomTaskIdPatternThrowsOnITPNGWhenConfiguredOtherwise(): void
    {
        $validator = new FindingValidator(
            taskIdPattern: '/^(OTHER-\d+|TODO@[\w:\/.-]+)$/'
        );
        $finding = $this->createFinding('ITPNG-123');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing task references');
        $validator->validate($finding, 'finding.json');
    }

    private function createFinding(string $taskId): Finding
    {
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
            validatedConclusion: 'The validated conclusion.',
            confidence: 'medium',
            validationStatus: 'validated',
            status: FindingStatus::VALIDATED,
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
                'validated_conclusion' => 'The validated conclusion.',
                'confidence' => 'medium',
                'validation_status' => 'validated',
                'status' => 'validated',
                'sensitivity' => 'public',
            ]
        );
    }
}
