<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;

final class RunLearningDecisionStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-run-decision-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNoDurableLearningIsPersistedByOpaqueRunIdentity(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $record = $store->record(
            'run:ABC-123:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'The run produced no reusable guidance beyond the verified code change.',
        );

        self::assertFileExists($record->path);
        self::assertStringNotContainsString('run:ABC-123', $record->path);
        self::assertSame($record->toArray(), $store->find('run:ABC-123:deadbeef')?->toArray());
    }

    public function testIdenticalRetryIsIdempotentButDifferentConclusionIsRejected(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $first = $store->record(
            'run:ABC-123:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable lesson.',
        );
        $retry = $store->record(
            'run:ABC-123:deadbeef',
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable lesson.',
        );

        self::assertSame($first->decidedAt, $retry->decidedAt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has a different durable learning decision');
        $store->record(
            'run:ABC-123:deadbeef',
            RunLearningDecisionStatus::FOLLOW_UP_REQUIRED,
            'lars',
            'A follow-up is required.',
            [],
            'issue:123',
        );
    }

    public function testDecisionSpecificEvidenceIsRequired(): void
    {
        $store = new RunLearningDecisionStore($this->root);

        try {
            $store->record(
                'run:ABC-123:one',
                RunLearningDecisionStatus::FINDINGS_RECORDED,
                'lars',
                'Findings were recorded.',
            );
            self::fail('Expected findings_recorded without finding IDs to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('at least one finding id', $exception->getMessage());
        }

        try {
            $store->record(
                'run:ABC-123:two',
                RunLearningDecisionStatus::FOLLOW_UP_REQUIRED,
                'lars',
                'A follow-up is required.',
            );
            self::fail('Expected follow_up_required without a reference to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('follow-up reference', $exception->getMessage());
        }

        $findings = $store->record(
            'run:ABC-123:three',
            RunLearningDecisionStatus::FINDINGS_RECORDED,
            'lars',
            'Two durable findings were recorded.',
            ['finding-b', 'finding-a', 'finding-a'],
        );
        self::assertSame(['finding-a', 'finding-b'], $findings->findingIds);
    }

    public function testCorruptOrContradictoryPersistedStateFailsClosed(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $path = $store->path('run:ABC-123:deadbeef');
        mkdir(dirname($path), 0o775, true);
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'kind' => 'run_learning_decision',
            'run_id' => 'run:OTHER:deadbeef',
            'decision' => 'no_durable_learning',
            'decided_by' => 'lars',
            'decided_at' => '2026-08-10T18:00:00+00:00',
            'reason' => 'No durable lesson.',
            'finding_ids' => [],
            'follow_up_ref' => null,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to another run');
        $store->find('run:ABC-123:deadbeef');
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
