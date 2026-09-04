<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
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

    public function testRejectsLegacyOutcomeSummaryWrites(): void
    {
        $repo = new OutcomeRepository();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('legacy outcome.* records are unsupported after the pre-1.0 cut');
        $repo->record($this->root, $this->legacyOutcome('outcome.2026-06-20.001'));
    }

    public function testRejectsHistoricalLegacyOutcomeReads(): void
    {
        $legacy = $this->legacyOutcome('outcome.2026-06-20.001');
        file_put_contents(
            $this->root . '/history/outcomes.jsonl',
            json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('legacy outcome.* records are unsupported after the pre-1.0 cut');
        (new OutcomeRepository())->loadAll($this->root);
    }

    public function testDoesNotAppendCurrentOutcomeBehindUnsupportedLegacyHistory(): void
    {
        $path = $this->root . '/history/outcomes.jsonl';
        $legacy = $this->legacyOutcome('outcome.2026-06-20.001');
        $legacyLine = json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $legacyLine);

        try {
            (new OutcomeRepository())->record(
                $this->root,
                $this->currentOutcome('guidance-outcome.2026-06-20.002'),
            );
            self::fail('Expected unsupported legacy history to fail closed.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'legacy outcome.* records are unsupported after the pre-1.0 cut',
                $exception->getMessage(),
            );
        }

        self::assertSame($legacyLine, file_get_contents($path));
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
