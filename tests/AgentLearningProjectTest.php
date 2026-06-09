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
