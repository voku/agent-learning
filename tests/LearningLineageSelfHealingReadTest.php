<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\LearningLineageService;

/**
 * An ordinary lineage read reconstructs its own derived projection.
 *
 * The lineage graph holds no fact that is not recomputable from durable Learning
 * records, so refusing a read because it is stale asked the caller to repair this
 * package's cache - and the refusal surfaced far from its cause. Writing a Finding
 * leaves the projection behind, and the command that then fails is the next unrelated
 * one that happens to consume Recall, reporting a lineage problem it has no reason to
 * understand. Consumers should reason about semantic availability, not cache lifecycle.
 *
 * Repair is confined to the two recomputable states. Everything else still fails
 * closed, and `verifyCurrent()` deliberately keeps refusing so the diagnostic that
 * exists to detect staleness can still detect it.
 */
final class LearningLineageSelfHealingReadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-selfheal-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/project', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAMissingProjectionIsRebuiltByAnOrdinaryRead(): void
    {
        self::assertFileDoesNotExist($this->databasePath());

        $result = (new LearningLineageService())->lineage($this->root, 'proposal.2026-06-08.001');

        self::assertSame(['finding.2026-06-08.001'], $result->identityIds);
        self::assertFileExists($this->databasePath());
    }

    public function testAStaleProjectionIsRebuiltByAnOrdinaryRead(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);

        // A new durable Finding moves the source revision without touching the graph,
        // which is exactly what FindingCreator::createValidated() leaves behind.
        $this->addFinding('finding.2026-06-09.999');

        $result = $service->lineage($this->root, 'proposal.2026-06-08.001');

        self::assertSame(['finding.2026-06-08.001'], $result->identityIds);
        $service->verifyCurrent($this->root);
    }

    public function testAPrecedentReadImmediatelyAfterAFindingIsWrittenSucceeds(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $this->addFinding('finding.2026-06-09.998');

        $result = $service->precedentsForTask($this->root, 'ABC-1');

        self::assertSame('ABC-1', $result->taskId);
    }

    public function testVerifyCurrentStillReportsStalenessInsteadOfRepairingIt(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $this->addFinding('finding.2026-06-09.997');

        // The diagnostic exists to detect this state. Repairing here would make it
        // permanently incapable of reporting the problem it was written to report.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/stale/');
        $service->verifyCurrent($this->root);
    }

    public function testInvalidDurableLearningDataStillFailsClosed(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $this->addFinding('finding.2026-06-09.996');

        // Corrupt a durable record so the rebuild the read triggers cannot complete.
        $findings = glob($this->root . '/findings/validated/*.json') ?: [];
        self::assertNotSame([], $findings, 'Fixture precondition: durable findings must exist.');
        file_put_contents($findings[0], '{ not valid json');

        $this->expectException(RuntimeException::class);
        $service->lineage($this->root, 'proposal.2026-06-08.001');
    }

    public function testACorruptProjectionIsNotSilentlyOverwritten(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        file_put_contents($this->databasePath(), 'this is not a sqlite database at all');

        // A `catch (Throwable)` here would quietly replace whatever is at that path and
        // answer as if nothing were wrong. "Not a database" is not a stale projection:
        // it may be the wrong path, a failing disk, or somebody else's file.
        $this->expectException(\PDOException::class);
        $service->lineage($this->root, 'proposal.2026-06-08.001');
    }

    private function databasePath(): string
    {
        return realpath($this->root) . '/.derived/lineage/graph.sqlite';
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
