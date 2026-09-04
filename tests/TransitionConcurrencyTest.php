<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Transitions allocate their next history id by scanning the log they are about
 * to append to, so allocation and persistence have to be one critical section.
 * Without it, two runs that both read the log before either appends agree on the
 * same sequence number and write duplicate audit ids - and the duplicate is in
 * immutable history, which is the one place this package cannot repair.
 *
 * @internal
 */
final class TransitionConcurrencyTest extends TestCase
{
    private const int WRITERS = 12;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/transition-concurrency-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        copy(
            __DIR__ . '/fixtures/findings/finding.2026-06-08.001.json',
            $this->root . '/findings/validated/finding.2026-06-08.001.json',
        );

        $decisions = '';
        foreach ($this->proposalIds() as $id) {
            $this->writeAppliedProposal($id);
            $decisions .= json_encode([
                'id' => 'decision.2026-06-08.' . substr($id, -3),
                'proposal_id' => $id,
                'status' => 'approved',
                'approved_by' => 'maintainer',
                'approved_at' => '2026-06-08T13:00:00+00:00',
            ], JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($this->root . '/history/decisions.jsonl', $decisions);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testConcurrentTransitionsNeverShareAHistoryId(): void
    {
        // A shared wall-clock start rather than a file the children poll for:
        // interpreter boot and autoload vary by milliseconds, which is far wider
        // than the read-then-append window being guarded, so children that merely
        // start together still miss each other. Spinning to one absolute instant
        // lines them up inside that window.
        $startAt = microtime(true) + 0.75;

        $processes = [];
        foreach ($this->proposalIds() as $id) {
            $processes[$id] = $this->startRetire($id, $startAt);
        }

        $failures = [];
        foreach ($processes as $id => $process) {
            $result = $this->awaitProcess($process);
            if ($result['exit'] !== 0) {
                $failures[$id] = trim($result['output']);
            }
        }

        self::assertSame([], $failures, 'every concurrent retire must succeed: ' . json_encode($failures));

        $lines = array_values(array_filter(explode(
            "\n",
            (string) file_get_contents($this->root . '/history/retired-proposals.jsonl'),
        )));
        self::assertCount(self::WRITERS, $lines, 'every writer must have appended exactly one record.');

        $ids = [];
        $proposals = [];
        foreach ($lines as $line) {
            /** @var array<string, mixed> $record */
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $ids[] = $record['id'];
            $proposals[] = $record['proposal_id'];
        }

        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'concurrent transitions allocated a duplicate history id: ' . json_encode($ids),
        );
        self::assertSame(self::WRITERS, count(array_unique($proposals)));
    }

    /** @return list<string> */
    private function proposalIds(): array
    {
        $ids = [];
        for ($index = 1; $index <= self::WRITERS; $index++) {
            $ids[] = sprintf('proposal.2026-06-08.%03d', $index);
        }

        return $ids;
    }

    /**
     * Real processes rather than a simulated race: the lock is a property of the
     * filesystem, so anything short of two interpreters contending for it would
     * be testing the harness instead of the guarantee.
     *
     * Both child streams go to a file rather than a pipe. Draining two pipes in
     * sequence deadlocks as soon as the child fills the one the parent is not
     * reading yet - and a child that fails loudly is exactly the case this test
     * exists to report, so the deadlock would swallow the report. A file sink
     * cannot block, so the failure text always survives.
     *
     * @return array{resource, string}
     */
    private function startRetire(string $proposalId, float $startAt): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = sprintf(
            '<?php require %s; $s = %s;'
            . ' while (microtime(true) < $s) {}'
            . ' (new voku\AgentLearning\ProposalTransitionManager())->retire(%s, %s, "lars", "Concurrent retirement.");',
            var_export($autoload, true),
            var_export($startAt, true),
            var_export($this->root, true),
            var_export($proposalId, true),
        );

        $outputPath = $this->root . '/' . $proposalId . '.output';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', substr($script, 6)],
            [1 => ['file', $outputPath, 'w'], 2 => ['file', $outputPath, 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start a concurrent transition process');
        }

        return [$process, $outputPath];
    }

    /**
     * @param array{resource, string} $process
     * @return array{exit: int, output: string}
     */
    private function awaitProcess(array $process): array
    {
        [$handle, $outputPath] = $process;
        $exit = proc_close($handle);

        return ['exit' => $exit, 'output' => is_file($outputPath) ? (string) file_get_contents($outputPath) : ''];
    }

    private function writeAppliedProposal(string $proposalId): void
    {
        /** @var array<string, mixed> $proposal */
        $proposal = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $proposal['id'] = $proposalId;
        $proposal['status'] = 'applied';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        $proposal['applied_by'] = 'maintainer';
        // Before the physical-target proof policy, so these fixtures stay valid
        // applied records without carrying a target hash of their own.
        $proposal['applied_at'] = '2026-06-08T14:00:00+00:00';
        $proposal['commit'] = 'commit123';

        file_put_contents(
            $this->root . '/proposals/applied/' . $proposalId . '.json',
            json_encode($proposal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
