<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\LearningLineageProjectionUnavailable;
use voku\AgentLearning\LearningLineageService;

/**
 * A read can decline to repair the derived projection.
 *
 * The default read reconstructs an absent or stale graph, which suits command-line
 * consumers (see LearningLineageSelfHealingReadTest). A consumer that answers HTTP GET
 * cannot take that trade: the rebuild writes under the Learning root and scans every
 * source record, on a path that is supposed to change nothing. `$repairProjection: false`
 * is that consumer's contract - the recomputable states are reported, not recomputed.
 */
final class LearningLineageObservingReadTest extends TestCase
{
    private const string PROPOSAL = 'proposal.2026-06-08.001';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-observe-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/project', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAnAbsentProjectionIsReportedAndNothingIsWritten(): void
    {
        $before = $this->snapshot();

        try {
            (new LearningLineageService())->lineage($this->root, self::PROPOSAL, repairProjection: false);
            self::fail('An absent projection must not be answered from a rebuild.');
        } catch (LearningLineageProjectionUnavailable $unavailable) {
            self::assertStringContainsString('not found', $unavailable->getMessage());
        }

        self::assertSame($before, $this->snapshot(), 'Observing must leave the Learning root exactly as it was.');
        self::assertFileDoesNotExist($this->databasePath());
    }

    public function testAStaleProjectionIsReportedAndLeftStale(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $this->addFinding('finding.2026-06-09.999');
        $before = $this->snapshot();

        try {
            $service->lineage($this->root, self::PROPOSAL, repairProjection: false);
            self::fail('A stale projection must not be answered from a rebuild.');
        } catch (LearningLineageProjectionUnavailable $unavailable) {
            self::assertStringContainsString('stale', $unavailable->getMessage());
        }

        self::assertSame($before, $this->snapshot(), 'The stale database must be byte- and mtime-identical afterwards.');
        // Still stale: the diagnostic that exists to detect this must still see it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/stale/');
        $service->verifyCurrent($this->root);
    }

    public function testAStaleProjectionIsReportedForPrecedentsToo(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $this->addFinding('finding.2026-06-09.998');
        $before = $this->snapshot();

        $this->expectException(LearningLineageProjectionUnavailable::class);
        try {
            $service->precedentsForTask($this->root, 'ABC-1', repairProjection: false);
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testAnAbsentProjectionIsReportedForPrecedentsToo(): void
    {
        $before = $this->snapshot();

        $this->expectException(LearningLineageProjectionUnavailable::class);
        try {
            (new LearningLineageService())->precedentsForTask($this->root, 'ABC-1', repairProjection: false);
        } finally {
            self::assertSame($before, $this->snapshot());
            self::assertFileDoesNotExist($this->databasePath());
        }
    }

    public function testACurrentProjectionAnswersTheSameInBothModes(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $before = $this->snapshot();

        self::assertEquals(
            $service->lineage($this->root, self::PROPOSAL)->toArray(),
            $service->lineage($this->root, self::PROPOSAL, repairProjection: false)->toArray(),
        );
        self::assertEquals(
            $service->precedentsForTask($this->root, 'ABC-1')->toArray(),
            $service->precedentsForTask($this->root, 'ABC-1', repairProjection: false)->toArray(),
        );
        self::assertSame($before, $this->snapshot(), 'Reading a current projection must not touch it.');
    }

    public function testARootWithNoLearningRecordsStillAnswersEmptyWithoutAProjection(): void
    {
        $empty = sys_get_temp_dir() . '/agent-learning-observe-empty-' . bin2hex(random_bytes(6));
        mkdir($empty, 0o775, true);

        try {
            $result = (new LearningLineageService())->precedentsForTask($empty, 'ABC-1', repairProjection: false);

            self::assertSame([], $result->precedents);
            self::assertFileDoesNotExist($empty . '/.derived');
        } finally {
            $this->removeDirectory($empty);
        }
    }

    public function testTheDefaultStillRepairs(): void
    {
        $service = new LearningLineageService();

        $result = $service->lineage($this->root, self::PROPOSAL);

        self::assertSame(['finding.2026-06-08.001'], $result->identityIds);
        self::assertFileExists($this->databasePath());
    }

    public function testInvalidDurableDataStillFailsClosedWhenObserving(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $findings = glob($this->root . '/findings/validated/*.json') ?: [];
        self::assertNotSame([], $findings, 'Fixture precondition: durable findings must exist.');
        // Changing a record also makes the projection stale. Observing must report
        // that as unavailable rather than reach the unreadable record at all.
        file_put_contents($findings[0], '{ not valid json');

        $this->expectException(LearningLineageProjectionUnavailable::class);
        $service->lineage($this->root, self::PROPOSAL, repairProjection: false);
    }

    public function testACorruptProjectionIsStillNotReportedAsMerelyStale(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        file_put_contents($this->databasePath(), 'this is not a sqlite database at all');

        $this->expectException(\PDOException::class);
        $service->lineage($this->root, self::PROPOSAL, repairProjection: false);
    }

    private function databasePath(): string
    {
        return realpath($this->root) . '/.derived/lineage/graph.sqlite';
    }

    /**
     * Every path under the root with its content hash and mtime, directories included,
     * so a created directory or a rewritten-but-identical file both register.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        clearstatcache();
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($this->root));
            $result[$relative] = $file->isDir()
                ? 'dir'
                : hash_file('sha256', $path) . '@' . $file->getMTime() . '.' . $file->getSize();
        }
        ksort($result);

        return $result;
    }

    private function addFinding(string $id): void
    {
        $directory = $this->root . '/findings/validated';
        $existing = glob($directory . '/*.json') ?: [];
        self::assertNotSame([], $existing, 'Fixture precondition: a template finding must exist.');
        $decoded = json_decode((string) file_get_contents($existing[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['id'] = $id;
        file_put_contents($directory . '/' . $id . '.json', json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            throw new RuntimeException('Unable to create fixture root: ' . $target);
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $target . '/' . $entry;
            is_dir($from) ? $this->copyDirectory($from, $to) : copy($from, $to);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
