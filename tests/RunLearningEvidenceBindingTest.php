<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;

final class RunLearningEvidenceBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-evidence-binding-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testLearningDecisionCarriesTheEvidenceBoundaryItDescribes(): void
    {
        $snapshot = 'sha256:' . str_repeat('a', 64);
        $validation = 'sha256:' . str_repeat('b', 64);
        $review = 'sha256:' . str_repeat('c', 64);
        $store = new RunLearningDecisionStore($this->root);

        $decision = $store->record(
            'run:LOOP-132:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'dogfood',
            'No durable learning in implementation A.',
            contractRevision: 3,
            implementationSnapshot: $snapshot,
            validationEvidenceSha256: $validation,
            reviewEvidenceSha256: $review,
        );

        self::assertSame(3, $decision->contractRevision);
        self::assertSame($snapshot, $decision->implementationSnapshot);
        self::assertSame($validation, $decision->validationEvidenceSha256);
        self::assertSame($review, $decision->reviewEvidenceSha256);
    }

    public function testLaterImplementationSnapshotCanReplaceAStaleLearningConclusionForTheSameRun(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $validation = 'sha256:' . str_repeat('b', 64);
        $review = 'sha256:' . str_repeat('c', 64);

        $store->record(
            'run:LOOP-132:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'dogfood',
            'Decision for implementation A.',
            contractRevision: 3,
            implementationSnapshot: 'sha256:' . str_repeat('a', 64),
            validationEvidenceSha256: $validation,
            reviewEvidenceSha256: $review,
        );
        $current = $store->record(
            'run:LOOP-132:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'dogfood',
            'Decision for implementation B.',
            contractRevision: 3,
            implementationSnapshot: 'sha256:' . str_repeat('d', 64),
            validationEvidenceSha256: $validation,
            reviewEvidenceSha256: $review,
        );

        self::assertSame('sha256:' . str_repeat('d', 64), $current->implementationSnapshot);
        self::assertSame('Decision for implementation B.', $current->reason);
        self::assertSame($current->toArray(), $store->find('run:LOOP-132:deadbeef')?->toArray());
    }
}
