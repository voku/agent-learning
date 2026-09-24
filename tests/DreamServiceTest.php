<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Cli;
use voku\AgentLearning\DreamRequest;
use voku\AgentLearning\DreamService;
use voku\AgentLearning\EvolutionDecisionType;

final class DreamServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dream-service-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        // The same validated lesson on two independent tasks: the smallest
        // corpus Dream turns into a reviewable memory promotion candidate.
        $this->writeFinding('finding.2026-06-01.001', 'TASK-1');
        $this->writeFinding('finding.2026-06-02.001', 'TASK-2');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReadOnlyRunReturnsTypedDecisionsAndWritesNothing(): void
    {
        $outcome = (new DreamService())->run(new DreamRequest($this->root, $this->root));

        self::assertFalse($outcome->candidatesWritten);
        self::assertSame([], $outcome->writtenCandidateIds);
        self::assertSame([], glob($this->root . '/proposals/candidate/*.json') ?: []);
        self::assertContains(
            EvolutionDecisionType::PROMOTION_CANDIDATE,
            array_map(static fn ($decision) => $decision->type, $outcome->result->decisions),
        );
        self::assertNotSame('', $outcome->projection->inputDigest);
    }

    public function testWritingCandidatesCreatesOnlyCandidateProposalsAndSuppressesTheRepeat(): void
    {
        $service = new DreamService();

        $first = $service->run(new DreamRequest($this->root, $this->root, writeCandidates: true));
        self::assertTrue($first->candidatesWritten);
        self::assertNotSame([], $first->writtenCandidateIds);
        self::assertCount(count($first->writtenCandidateIds), glob($this->root . '/proposals/candidate/*.json') ?: []);
        self::assertSame([], glob($this->root . '/proposals/approved/*.json') ?: [], 'Dream never approves guidance');

        // A decision already written as a candidate is suppressed, not duplicated.
        $second = $service->run(new DreamRequest($this->root, $this->root, writeCandidates: true));
        self::assertSame([], $second->writtenCandidateIds);
        self::assertNotSame([], $second->result->suppressedDecisions);
    }

    public function testTheCliReportsWhatTheServiceDecides(): void
    {
        $outcome = (new DreamService())->run(new DreamRequest($this->root, $this->root));

        $reportPath = $this->root . '/dream-report.json';
        $exit = (new Cli())->run(['agent-learning', 'dream', '--root', $this->root, '--project-root', $this->root, '--report', $reportPath, '--format', 'text', '--dry-run']);
        $json = (string) file_get_contents($reportPath);

        self::assertSame(0, $exit);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame($outcome->result->evaluatedGuidanceCount, $report['evaluated_guidance_count']);
        self::assertCount(count($outcome->result->decisions), $report['decisions']);
    }

    public function testRequestRejectsAnEmptyRootAndANonPositiveHorizon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DreamRequest($this->root, reviewHorizonDays: 0);
    }

    private function writeFinding(string $id, string $taskId): void
    {
        file_put_contents($this->root . '/findings/validated/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session.' . $taskId,
            'created_at' => '2026-06-01T00:00:00+00:00',
            'created_by' => 'tester',
            'scope' => ['src'],
            'observation' => 'Observation is concrete.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Example.php', 'line' => 1]],
            'hypothesis' => 'Hypothesis is distinct.',
            'validated_conclusion' => 'Conclusion is validated.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'dream.service',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
