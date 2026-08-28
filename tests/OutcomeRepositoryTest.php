<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\GuidanceOutcomeEventRepository;
use voku\AgentLearning\OutcomeRepository;
use voku\AgentLearning\ValidationException;

final class OutcomeRepositoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/outcome-repository-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRecordsVersionedGuidanceOutcomeSuccessfully(): void
    {
        $repo = new OutcomeRepository();
        $record = $this->currentOutcome('guidance-outcome.2026-06-20.001');

        $repo->record($this->root, $record);

        $outcomes = $repo->loadAll($this->root);
        self::assertCount(1, $outcomes);
        self::assertSame($record, $outcomes[0]);
    }

    public function testRejectsNewLegacyOutcomeSummaryWrites(): void
    {
        $repo = new OutcomeRepository();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('legacy outcome.* records are read-only compatibility');
        $repo->record($this->root, $this->legacyOutcome('outcome.2026-06-20.001'));
    }

    public function testKeepsHistoricalLegacyOutcomeReadable(): void
    {
        $legacy = $this->legacyOutcome('outcome.2026-06-20.001');
        file_put_contents(
            $this->root . '/history/outcomes.jsonl',
            json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $outcomes = (new OutcomeRepository())->loadAll($this->root);

        self::assertSame([$legacy], $outcomes);
        self::assertSame(1, (new GuidanceOutcomeEventRepository())->countLegacyRecords($this->root));
    }

    public function testMixedLegacyAndCurrentHistoryRemainsReadable(): void
    {
        $legacy = $this->legacyOutcome('outcome.2026-06-20.001');
        file_put_contents(
            $this->root . '/history/outcomes.jsonl',
            json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $current = $this->currentOutcome('guidance-outcome.2026-06-20.002');
        (new OutcomeRepository())->record($this->root, $current);

        $outcomes = (new OutcomeRepository())->loadAll($this->root);
        self::assertSame(
            ['outcome.2026-06-20.001', 'guidance-outcome.2026-06-20.002'],
            array_column($outcomes, 'id'),
        );
        self::assertSame(1, (new GuidanceOutcomeEventRepository())->countLegacyRecords($this->root));
    }

    public function testRejectsUnsupportedCurrentSchemaVersion(): void
    {
        $record = $this->currentOutcome('guidance-outcome.2026-06-20.001');
        $record['schema_version'] = '2.0';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported guidance outcome schema version');
        (new OutcomeRepository())->record($this->root, $record);
    }

    /** @return array<string, mixed> */
    private function currentOutcome(string $id): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => 'compilation.PROJECT-204.001',
            'task_id' => 'PROJECT-204',
            'guidance_id' => 'skill.auth-context',
            'outcome' => 'helpful',
            'applied' => true,
            'comment' => 'The bounded guidance matched the implementation.',
            'commit' => 'abc123',
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function legacyOutcome(string $id): array
    {
        return [
            'id' => $id,
            'task_id' => 'PROJECT-204',
            'applied_proposals' => [],
            'guidance_used' => ['skill.auth-context'],
            'result' => 'successful',
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
