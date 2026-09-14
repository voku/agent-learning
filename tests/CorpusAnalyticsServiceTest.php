<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\CorpusAnalyticsService;

final class CorpusAnalyticsServiceTest extends TestCase
{
    public function testAnalyzeComputesEvolutionLifecycleAndConsolidation(): void
    {
        $root = $this->createFixtureLearningRoot();

        $service = new CorpusAnalyticsService();
        $result = $service->analyze($root);

        // Summary
        self::assertSame(3, $result->summary['total_findings']);
        self::assertSame(2, $result->summary['total_proposals']);
        self::assertSame(1, $result->summary['total_active_notes']);
        self::assertSame(1, $result->summary['total_active_constraints']);
        self::assertSame(2, $result->summary['findings_with_proposals']);
        self::assertSame(66.7, $result->summary['finding_to_proposal_rate']);

        // Cohorts
        self::assertArrayHasKey('2026-06', $result->cohorts);
        self::assertArrayHasKey('2026-07', $result->cohorts);
        self::assertSame(2, $result->cohorts['2026-06']['finding_count']);
        self::assertSame(1, $result->cohorts['2026-07']['finding_count']);
        self::assertSame(1, $result->cohorts['2026-06']['proposal_count']);
        self::assertSame(1, $result->cohorts['2026-07']['proposal_count']);

        // Lifecycle breakdown
        self::assertSame(1, $result->lifecycleBreakdown['active_or_pending']['applied']);
        self::assertSame(1, $result->lifecycleBreakdown['terminal_proposals']['retired']);
        self::assertSame(1, $result->lifecycleBreakdown['retired_semantic_buckets']['COMPILED_DOWN_TO_CONSTRAINT']);

        // CLI test
        [$exitCode, $output] = $this->runCli($root, ['corpus-analyze', '--format', 'json']);
        self::assertSame(0, $exitCode, $output);
        /** @var array{summary: array{total_findings: int}} $json */
        $json = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $json['summary']['total_findings']);

        [$exitCodeText, $outputText] = $this->runCli($root, ['corpus-analyze']);
        self::assertSame(0, $exitCodeText, $outputText);
        self::assertStringContainsString('=== Learning Corpus Analytics ===', $outputText);
        self::assertStringContainsString('Compiled Down to Active Constraint: 1', $outputText);
    }

    private function createFixtureLearningRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-corpus-test-' . bin2hex(random_bytes(8)) . '/.agent-loop/learning';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/applied', 0777, true);
        mkdir($root . '/proposals/retired', 0777, true);
        mkdir($root . '/notes/active', 0777, true);
        mkdir($root . '/constraints/active', 0777, true);
        mkdir($root . '/history', 0777, true);

        // Findings
        file_put_contents($root . '/findings/validated/finding.2026-06-01.001.json', json_encode([
            'id' => 'finding.2026-06-01.001',
            'task_id' => 'TASK-1',
            'status' => 'validated',
            'created_at' => '2026-06-01T10:00:00+02:00',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/findings/validated/finding.2026-06-02.002.json', json_encode([
            'id' => 'finding.2026-06-02.002',
            'task_id' => 'TASK-2',
            'status' => 'validated',
            'created_at' => '2026-06-02T10:00:00+02:00',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/findings/validated/finding.2026-07-01.001.json', json_encode([
            'id' => 'finding.2026-07-01.001',
            'task_id' => 'TASK-3',
            'status' => 'validated',
            'created_at' => '2026-07-01T10:00:00+02:00',
        ], JSON_THROW_ON_ERROR));

        // Proposals
        file_put_contents($root . '/proposals/retired/proposal.2026-06-05.001.json', json_encode([
            'id' => 'proposal.2026-06-05.001',
            'status' => 'retired',
            'action' => 'ADD_MEMORY',
            'created_at' => '2026-06-05T10:00:00+02:00',
            'source_findings' => ['finding.2026-06-01.001'],
            'reason' => 'Captured in target canonical guidance; confirmed landed.',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/proposals/applied/proposal.2026-07-02.001.json', json_encode([
            'id' => 'proposal.2026-07-02.001',
            'status' => 'applied',
            'action' => 'ADD_SKILL',
            'created_at' => '2026-07-02T10:00:00+02:00',
            'source_findings' => ['finding.2026-07-01.001'],
            'reason' => 'Active skill guidance applied.',
        ], JSON_THROW_ON_ERROR));

        // Active note
        file_put_contents($root . '/notes/active/note.1.json', json_encode(['id' => 'note.1'], JSON_THROW_ON_ERROR));

        // Active constraint linked to proposal.2026-06-05.001
        file_put_contents($root . '/constraints/active/constraint.rule1.json', json_encode([
            'id' => 'constraint.rule1',
            'source_proposal' => 'proposal.2026-06-05.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        // Retired proposal history
        file_put_contents($root . '/history/retired-proposals.jsonl', json_encode([
            'id' => 'retirement.1',
            'proposal_id' => 'proposal.2026-06-05.001',
            'retired_by' => 'tester',
            'retired_at' => '2026-06-10T10:00:00+02:00',
            'reason' => 'Fully captured in target canonical guidance; confirmed landed.',
        ], JSON_THROW_ON_ERROR) . "\n");

        return $root;
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runCli(string $root, array $arguments): array
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/../bin/agent-learning',
            ...$arguments,
            '--root',
            $root,
        ];
        $output = [];
        $exitCode = 0;
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
