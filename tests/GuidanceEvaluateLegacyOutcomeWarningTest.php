<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\GuidanceOutcomeEventRepository;

final class GuidanceEvaluateLegacyOutcomeWarningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guidance-evaluate-legacy-outcome-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCountLegacyRecordsCountsOnlyNonGuidanceOutcomeShapedRecords(): void
    {
        // Reproduces a real gap: OutcomeRepository::loadAll() validates both the current
        // "guidance-outcome.*" per-item shape and the older "outcome.*" session-summary
        // shape (helpful/irrelevant/harmful as proposal-ID arrays) without error, but
        // GuidanceOutcomeEventRepository::load() -- and therefore every
        // GuidanceUsageProjector statistic and promotion/staleness decision fed by
        // guidance-evaluate -- silently skips anything that is not "guidance-outcome.*".
        // A guidance item whose only recorded usage was in the older shape was
        // indistinguishable from one that was never used at all, with no visible signal
        // that older data was excluded. countLegacyRecords() makes that count explicit so
        // callers (guidanceEvaluateCommand()) can surface it instead of losing it silently.
        $this->appendLegacyOutcome('outcome.2026-06-13.001');
        $this->appendLegacyOutcome('outcome.2026-06-13.002');
        $this->appendGuidanceOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100');

        $repository = new GuidanceOutcomeEventRepository();

        self::assertSame(2, $repository->countLegacyRecords($this->root));
        self::assertCount(1, $repository->load($this->root));
    }

    public function testCountLegacyRecordsIsZeroWhenOnlyCurrentShapedRecordsExist(): void
    {
        $this->appendGuidanceOutcome('guidance-outcome.2026-06-18.001', 'compilation.PROJECT-1.001', 'PROJECT-1', 'proposal.2026-06-18.100');

        self::assertSame(0, (new GuidanceOutcomeEventRepository())->countLegacyRecords($this->root));
    }

    public function testCountLegacyRecordsIsZeroWhenHistoryFileIsMissing(): void
    {
        self::assertSame(0, (new GuidanceOutcomeEventRepository())->countLegacyRecords($this->root . '/does-not-exist'));
    }

    private function appendLegacyOutcome(string $id): void
    {
        $record = [
            'id' => $id,
            'task_id' => 'PROJECT-1',
            'applied_proposals' => [],
            'guidance_used' => [],
            'result' => 'successful',
        ];
        file_put_contents($this->root . '/history/outcomes.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    private function appendGuidanceOutcome(string $id, string $compilationId, string $taskId, string $guidanceId): void
    {
        $record = [
            'schema_version' => '1.0',
            'id' => $id,
            'compilation_id' => $compilationId,
            'task_id' => $taskId,
            'guidance_id' => $guidanceId,
            'outcome' => 'helpful',
            'applied' => true,
            'comment' => 'fixture',
            'commit' => 'abc1234',
            'recorded_by' => 'test',
            'recorded_at' => '2026-06-18T12:00:00+00:00',
        ];
        file_put_contents($this->root . '/history/outcomes.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
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
