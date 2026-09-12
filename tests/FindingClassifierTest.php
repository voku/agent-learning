<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingClassifier;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLearning\ValidationException;

final class FindingClassifierTest extends TestCase
{
    public function testRawValidatedFindingCanBeClassifiedIntoPromotableLearning(): void
    {
        $root = $this->createLearningRoot();
        $created = $this->createFinding($root, 'finding.2026-09-12.92a001');

        $before = (new LearningNoteService())->promotionReadiness($root, $created->finding->id);
        self::assertFalse($before->promotable);

        $classified = (new FindingClassifier())->classify(
            root: $root,
            findingId: $created->finding->id,
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'finding.authoring.promotable',
            validationCase: new ValidationCase(
                'A validated Finding was captured without durable-learning triage.',
                'The Finding is explicitly classified after observation capture.',
                'Promotion readiness accepts the Finding without changing its original evidence.',
            ),
        );

        self::assertSame(LearningClassification::ADD_LEARNING_NOTE, $classified->classification);
        self::assertSame('finding.authoring.promotable', $classified->patternKey);
        self::assertInstanceOf(ValidationCase::class, $classified->validationCase);

        $after = (new LearningNoteService())->promotionReadiness($root, $created->finding->id);
        self::assertTrue($after->promotable);
        self::assertSame([], $after->blockers);
    }

    public function testInvalidTriageLeavesFindingByteForByteUnchanged(): void
    {
        $root = $this->createLearningRoot();
        $created = $this->createFinding($root, 'finding.2026-09-12.92a002');
        $before = file_get_contents($created->path);
        self::assertIsString($before);

        try {
            (new FindingClassifier())->classify(
                root: $root,
                findingId: $created->finding->id,
                classification: LearningClassification::ADD_LEARNING_NOTE,
                patternKey: 'not-a-stable-pattern',
                validationCase: new ValidationCase('Given', 'When', 'Then'),
            );
            self::fail('Invalid triage should have failed.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('pattern_key must use stable dot-separated lowercase segments', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($created->path));
    }

    public function testIgnoreClassificationRemovesObsoletePromotionMetadata(): void
    {
        $root = $this->createLearningRoot();
        $created = $this->createFinding($root, 'finding.2026-09-12.92a003');
        $classifier = new FindingClassifier();

        $classifier->classify(
            root: $root,
            findingId: $created->finding->id,
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'finding.authoring.ignore',
            validationCase: new ValidationCase('Given', 'When', 'Then'),
        );
        $ignored = $classifier->classify(
            root: $root,
            findingId: $created->finding->id,
            classification: LearningClassification::IGNORE,
        );

        self::assertSame(LearningClassification::IGNORE, $ignored->classification);
        self::assertNull($ignored->patternKey);
        self::assertNull($ignored->validationCase);
        self::assertArrayNotHasKey('pattern_key', $ignored->raw);
        self::assertArrayNotHasKey('validation_case', $ignored->raw);
    }

    private function createLearningRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-classifier-' . bin2hex(random_bytes(8)) . '/.agent-loop/learning';
        self::assertTrue(mkdir($root . '/findings', 0777, true));

        return $root;
    }

    private function createFinding(string $root, string $id): \voku\AgentLearning\FindingCreationResult
    {
        return (new FindingCreator())->createValidated(
            root: $root,
            taskId: 'PROJECT-92',
            session: 'session_PROJECT-92',
            createdBy: 'test',
            scope: ['src/'],
            observation: 'Supported Finding creation cannot currently express later reusable-learning triage.',
            evidence: [[
                'type' => 'manual_verification',
                'summary' => 'Promotion readiness reports missing triage metadata.',
            ]],
            hypothesis: 'Observation capture and learning classification need separate owner operations.',
            validatedConclusion: 'A later typed classification operation is required.',
            confidence: 'high',
            sensitivity: 'public',
            id: $id,
        );
    }
}
