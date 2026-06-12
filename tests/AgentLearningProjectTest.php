<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingLifecycle;
use voku\AgentLearning\FindingRepository;
use voku\AgentLearning\PathResolver;
use voku\AgentLearning\ProposalRepository;
use voku\AgentLearning\ValidationException;

final class AgentLearningProjectTest extends TestCase
{
    public function testResolverAcceptsProjectRootWithItPortalLayout(): void
    {
        $root = $this->createLearningRoot();
        $projectRoot = dirname($root, 3);

        self::assertSame($root, (new PathResolver())->resolve($projectRoot));
    }

    public function testRepositoriesLoadFixtureProject(): void
    {
        $root = $this->createLearningRoot();
        $findings = (new FindingRepository())->loadValidated($root);
        $proposals = (new ProposalRepository())->loadAll($root, $findings);

        self::assertCount(2, $findings);
        self::assertCount(2, $proposals);
    }

    public function testFindingLifecycleRejectsStatusDirectoryMismatch(): void
    {
        $root = $this->createLearningRoot();
        $file = $root . '/findings/candidate/finding.2026-06-08.001.json';
        mkdir(dirname($file), 0777, true);
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $file);

        $finding = (new FindingRepository())->loadValidated($root)['finding.2026-06-08.001'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be stored under findings/validated');
        (new FindingLifecycle())->assertPathMatchesStatus($finding, $file, $root);
    }

    public function testProposalRepositoryRejectsStatusDirectoryMismatch(): void
    {
        $root = $this->createLearningRoot();
        $file = $root . '/proposals/approved/proposal.2026-06-08.003.json';
        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
        $proposal['id'] = 'proposal.2026-06-08.003';
        $proposal['status'] = 'candidate';
        $proposal['approved_by'] = null;
        $proposal['approved_at'] = null;
        file_put_contents($file, json_encode($proposal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $findings = (new FindingRepository())->loadValidated($root);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be stored under proposals/candidate');
        (new ProposalRepository())->loadAll($root, $findings);
    }

    public function testCliValidateCommandHandlesFixtureProject(): void
    {
        $root = $this->createLearningRoot();
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' validate --root '
            . escapeshellarg($root)
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('Validated agent learning root:', implode("\n", $output));
    }

    public function testCliPrepareSelectsSeveralTasksDeterministically(): void
    {
        $root = $this->createLearningRoot();
        mkdir($root . '/templates', 0777, true);
        file_put_contents($root . '/templates/consolidation-prompt.md', "# Agent Learning Consolidation Prompt Addendum\n\nReturn machine-readable JSON.");
        $outputFile = $root . '/selected-consolidation-input.md';
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' prepare --root '
            . escapeshellarg($root)
            . ' --task PROJECT-1234 --task TODO@agent-learning --output '
            . escapeshellarg($outputFile)
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('Selected findings: 2', implode("\n", $output));
        self::assertStringContainsString('finding.2026-06-08.001 (PROJECT-1234)', implode("\n", $output));
        self::assertStringContainsString('finding.2026-06-08.002 (TODO@agent-learning)', implode("\n", $output));

        $prompt = (string)file_get_contents($outputFile);
        self::assertStringContainsString('"label": "task=PROJECT-1234, task=TODO@agent-learning"', $prompt);
        self::assertStringContainsString('"id": "finding.2026-06-08.001"', $prompt);
        self::assertStringContainsString('"id": "finding.2026-06-08.002"', $prompt);
        self::assertStringContainsString('# Agent Learning Consolidation Prompt Addendum', $prompt);
    }

    public function testCliPrepareFailsOnEmptySelectionByDefault(): void
    {
        $root = $this->createLearningRoot();
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' prepare --root '
            . escapeshellarg($root)
            . ' --task PROJECT-9999'
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        self::assertStringContainsString('prepare selection matched no validated findings', implode("\n", $output));
    }

    public function testCliValidateCommandFailsOnUnknownProposalReferenceInOutcomes(): void
    {
        $root = $this->createLearningRoot();
        $outcome = [
            'id' => 'outcome.2026-06-20.001',
            'task_id' => 'PROJECT-204',
            'applied_proposals' => ['proposal.nonexistent'],
            'guidance_used' => [],
            'result' => 'successful',
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];
        file_put_contents($root . '/history/outcomes.jsonl', json_encode($outcome) . "\n");

        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' validate --root '
            . escapeshellarg($root)
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        self::assertStringContainsString('outcome references unknown proposal', implode("\n", $output));
    }

    private function createLearningRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-learning-test-' . bin2hex(random_bytes(8)) . '/infra/doc/agent-learning';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/approved', 0777, true);
        mkdir($root . '/proposals/rejected', 0777, true);
        mkdir($root . '/history', 0777, true);

        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $root . '/findings/validated/finding.2026-06-08.001.json');
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json', $root . '/findings/validated/finding.2026-06-08.002.json');
        copy(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json', $root . '/proposals/approved/proposal.2026-06-08.001.json');
        copy(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.002.json', $root . '/proposals/rejected/proposal.2026-06-08.002.json');
        copy(__DIR__ . '/fixtures/history/decisions.jsonl', $root . '/history/decisions.jsonl');
        copy(__DIR__ . '/fixtures/history/rejected-proposals.jsonl', $root . '/history/rejected-proposals.jsonl');

        return $root;
    }
}
