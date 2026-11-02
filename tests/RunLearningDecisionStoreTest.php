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

    public function testDecisionNeedsNoProseToBeRecorded(): void
    {
        $store = new RunLearningDecisionStore($this->root);

        $plain = $store->record('run:ABC-124:deadbeef', RunLearningDecisionStatus::NO_DURABLE_LEARNING, 'lars');
        $blank = $store->record('run:ABC-125:deadbeef', RunLearningDecisionStatus::NO_DURABLE_LEARNING, 'lars', '   ');
        $findings = $store->record('run:ABC-126:deadbeef', RunLearningDecisionStatus::FINDINGS_RECORDED, 'lars', null, ['finding.2026-09-23.001']);
        $followUp = $store->record('run:ABC-127:deadbeef', RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, 'lars', null, [], 'FOL-31');

        foreach ([$plain, $blank, $findings, $followUp] as $record) {
            self::assertNull($record->reason);
            self::assertNull($record->toArray()['reason']);
            self::assertSame($record->toArray(), $store->find($record->runId)?->toArray());
        }
        // Idempotent retry without a reason stays the same decision.
        self::assertSame($plain->toArray(), $store->record('run:ABC-124:deadbeef', RunLearningDecisionStatus::NO_DURABLE_LEARNING, 'lars')->toArray());
    }

    public function testReservedStandingActorIsRejectedBeforeCurrentWriteOrArchive(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $runId = 'run:ABC-129:deadbeef';
        $existing = $store->record(
            $runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            contractRevision: 1,
            implementationSnapshot: 'sha256:' . str_repeat('a', 64),
            validationEvidenceSha256: 'sha256:' . str_repeat('b', 64),
            reviewEvidenceSha256: 'sha256:' . str_repeat('c', 64),
        );

        try {
            $store->record(
                $runId,
                RunLearningDecisionStatus::NO_DURABLE_LEARNING,
                '  standing:forged  ',
                contractRevision: 1,
                implementationSnapshot: 'sha256:' . str_repeat('d', 64),
                validationEvidenceSha256: 'sha256:' . str_repeat('b', 64),
                reviewEvidenceSha256: 'sha256:' . str_repeat('c', 64),
            );
            self::fail('Expected the reserved standing: actor prefix to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('The standing: actor prefix is reserved and cannot be used for Learning decisions.', $exception->getMessage());
        }

        self::assertSame($existing->toArray(), $store->find($runId)?->toArray());
        self::assertDirectoryDoesNotExist(dirname($store->path($runId)) . '/archive/' . hash('sha256', $runId));
    }

    public function testNamedDecisionCanReplaceBoundLegacyReservedDecisionAndArchiveIt(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $runId = 'run:ABC-130:deadbeef';
        $binding = [
            'contractRevision' => 4,
            'implementationSnapshot' => 'sha256:' . str_repeat('a', 64),
            'validationEvidenceSha256' => 'sha256:' . str_repeat('b', 64),
            'reviewEvidenceSha256' => 'sha256:' . str_repeat('c', 64),
        ];
        $legacy = $store->record(
            $runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture-reviewer',
            'Historical decision from a pre-reservation release.',
            ...$binding,
        );
        $legacyData = json_decode((string) file_get_contents($legacy->path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacyData);
        $legacyData['decided_by'] = 'standing:legacy-actor';
        self::assertNotFalse(file_put_contents(
            $legacy->path,
            json_encode($legacyData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        ));

        $persistedLegacy = $store->find($runId);
        self::assertNotNull($persistedLegacy);
        self::assertSame('standing:legacy-actor', $persistedLegacy->decidedBy);

        $replacement = $store->record(
            $runId,
            RunLearningDecisionStatus::FOLLOW_UP_REQUIRED,
            'named-reviewer',
            'Replace the invalid legacy pseudo-actor at the same verified evidence boundary.',
            [],
            'issue://voku/agent-loop/879',
            ...$binding,
        );

        self::assertSame(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $replacement->decision);
        self::assertSame('named-reviewer', $replacement->decidedBy);
        self::assertSame($binding['contractRevision'], $replacement->contractRevision);
        self::assertSame($binding['implementationSnapshot'], $replacement->implementationSnapshot);
        self::assertSame($binding['validationEvidenceSha256'], $replacement->validationEvidenceSha256);
        self::assertSame($binding['reviewEvidenceSha256'], $replacement->reviewEvidenceSha256);
        self::assertSame($replacement->toArray(), $store->find($runId)?->toArray());

        $archive = glob(dirname($store->path($runId)) . '/archive/' . hash('sha256', $runId) . '/*.json') ?: [];
        self::assertCount(1, $archive);
        $archived = json_decode((string) file_get_contents($archive[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('standing:legacy-actor', $archived['decided_by'] ?? null);
        self::assertSame($binding['contractRevision'], $archived['contract_revision'] ?? null);
        self::assertSame($binding['implementationSnapshot'], $archived['implementation_snapshot'] ?? null);
        self::assertSame($binding['validationEvidenceSha256'], $archived['validation_evidence_sha256'] ?? null);
        self::assertSame($binding['reviewEvidenceSha256'], $archived['review_evidence_sha256'] ?? null);
    }

    public function testDifferentNamedDecisionAtSameCompleteBindingRemainsImmutable(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $runId = 'run:ABC-131:deadbeef';
        $binding = [
            'contractRevision' => 4,
            'implementationSnapshot' => 'sha256:' . str_repeat('a', 64),
            'validationEvidenceSha256' => 'sha256:' . str_repeat('b', 64),
            'reviewEvidenceSha256' => 'sha256:' . str_repeat('c', 64),
        ];
        $existing = $store->record(
            $runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'first-reviewer',
            ...$binding,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has a different durable learning decision');
        $store->record(
            $runId,
            RunLearningDecisionStatus::FOLLOW_UP_REQUIRED,
            'second-reviewer',
            null,
            [],
            'issue://voku/agent-loop/879',
            ...$binding,
        );
    }

    public function testBoundLegacyReservedDecisionCannotMoveToAnotherContractRevision(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $runId = 'run:ABC-132:deadbeef';
        $binding = [
            'contractRevision' => 4,
            'implementationSnapshot' => 'sha256:' . str_repeat('a', 64),
            'validationEvidenceSha256' => 'sha256:' . str_repeat('b', 64),
            'reviewEvidenceSha256' => 'sha256:' . str_repeat('c', 64),
        ];
        $existing = $store->record(
            $runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture-reviewer',
            ...$binding,
        );
        $legacyData = json_decode((string) file_get_contents($existing->path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacyData);
        $legacyData['decided_by'] = 'standing:legacy-actor';
        self::assertNotFalse(file_put_contents(
            $existing->path,
            json_encode($legacyData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        ));

        try {
            $store->record(
                $runId,
                RunLearningDecisionStatus::NO_DURABLE_LEARNING,
                'named-reviewer',
                'Do not change the evidence Contract revision.',
                contractRevision: 5,
                implementationSnapshot: 'sha256:' . str_repeat('d', 64),
                validationEvidenceSha256: $binding['validationEvidenceSha256'],
                reviewEvidenceSha256: $binding['reviewEvidenceSha256'],
            );
            self::fail('A legacy reserved decision cannot move to another Contract revision.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'cannot move its durable learning decision from Contract revision 4 to revision 5',
                $exception->getMessage(),
            );
        }

        self::assertSame('standing:legacy-actor', $store->find($runId)?->decidedBy);
        self::assertDirectoryDoesNotExist(dirname($store->path($runId)) . '/archive/' . hash('sha256', $runId));
    }

    public function testRecordedReasonIsStillPersistedWhenGiven(): void
    {
        $store = new RunLearningDecisionStore($this->root);
        $record = $store->record('run:ABC-128:deadbeef', RunLearningDecisionStatus::NO_DURABLE_LEARNING, 'lars', '  Local regression only.  ');

        self::assertSame('Local regression only.', $store->find('run:ABC-128:deadbeef')?->reason);
        self::assertSame('Local regression only.', $record->reason);
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
