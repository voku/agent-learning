<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;

final class FindingCreatorLearningNoteTest extends TestCase
{
    public function testCreatesLearningNoteCandidateWithoutRawFindingStorageWrites(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-classified-finding-' . bin2hex(random_bytes(6));

        try {
            $validationCase = new ValidationCase(
                given: 'A later task touches the same owner boundary.',
                when: 'Recall prepares task context.',
                then: 'The solved case is eligible as low-authority precedent.',
            );

            $result = (new FindingCreator())->createValidated(
                root: $root,
                taskId: 'TASK-349-TEACH',
                session: 'session:task-349-teach',
                createdBy: 'dogfood',
                scope: ['src/Workflow/'],
                observation: 'A reusable owner-boundary failure was reproduced.',
                evidence: [[
                    'type' => 'manual_verification',
                    'summary' => 'The owner-boundary behavior was verified against the current tree.',
                ]],
                hypothesis: 'The same owner boundary should be preserved in later related work.',
                validatedConclusion: 'Reuse the owner boundary as precedent, not as mutation authority.',
                confidence: 'high',
                sensitivity: 'public',
                id: 'finding.2026-09-05.349',
                classification: LearningClassification::ADD_LEARNING_NOTE,
                patternKey: 'workflow.learning_note_owner_boundary',
                validationCase: $validationCase,
            );

            self::assertSame(LearningClassification::ADD_LEARNING_NOTE, $result->finding->classification);
            self::assertSame('workflow.learning_note_owner_boundary', $result->finding->patternKey);
            self::assertEquals($validationCase, $result->finding->validationCase);

            $prepared = (new LearningNoteService())->prepare(
                $root,
                [$result->finding->id],
                dirname($root),
            );

            self::assertSame('workflow.learning_note_owner_boundary', $prepared->patternKey);
            self::assertEquals($validationCase, $prepared->validationCase);
            self::assertSame(['src/Workflow/'], $prepared->scope);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
