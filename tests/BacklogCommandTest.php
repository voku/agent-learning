<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Cli;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingTransitionManager;

final class BacklogCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learning-backlog-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/candidate', 0777, true);
        mkdir($this->root . '/findings/validated', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBacklogGatesWhenValidatedFindingRemainsUnconsolidated(): void
    {
        $this->seedValidatedFinding();

        self::assertSame(1, $this->runBacklog([]));
        self::assertSame(0, $this->runBacklog(['--allow-nonempty']));
    }

    public function testBacklogPassesWhenClear(): void
    {
        self::assertSame(0, $this->runBacklog([]));
    }

    /**
     * @param list<string> $extraArgs
     */
    private function runBacklog(array $extraArgs): int
    {
        $argv = array_merge(['agent-learning', 'backlog', '--root', $this->root], $extraArgs);

        ob_start();
        try {
            return (new Cli())->run($argv);
        } finally {
            ob_end_clean();
        }
    }

    private function seedValidatedFinding(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json'), true);
        $data['status'] = 'candidate';
        $data['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($data));

        (new FindingTransitionManager())->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'maintainer');
        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.001.json');
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
