<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNote;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\ValidationCase;

final class LearningLineageServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-lineage-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/project', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRebuildAndQueryAreDeterministicOwnerProjections(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);
        $service->verifyCurrent($this->root);

        $first = $service->lineage($this->root, 'proposal.2026-06-08.001');
        self::assertSame(['finding.2026-06-08.001'], $first->identityIds);
        self::assertSame(
            [
                'proposal.2026-06-08.001' => 0,
                'finding.2026-06-08.001' => 1,
            ],
            $first->depthByIdentityId,
        );
        self::assertSame(
            [
                ['identity_id' => 'proposal.2026-06-08.001', 'depth' => 0],
                ['identity_id' => 'finding.2026-06-08.001', 'depth' => 1],
            ],
            $first->identityDepths(),
        );
        self::assertCount(1, $first->relations);
        self::assertSame(LearningLineageProjector::PROPOSAL_FROM_FINDING, $first->relations[0]->kind);
        self::assertSame('finding.2026-06-08.001', $first->relations[0]->sourceId);
        self::assertSame('proposal.2026-06-08.001', $first->relations[0]->targetId);
        self::assertFalse($first->truncated);

        $service->rebuild($this->root);
        $service->verifyCurrent($this->root);
        self::assertSame(
            $first->toArray(),
            $service->lineage($this->root, 'proposal.2026-06-08.001')->toArray(),
        );
    }

    public function testTaskPrecedentsUseBoundedLineageAndPointReadOnlySelectedActiveNotes(): void
    {
        $note = new LearningNote(
            id: 'learning-note.2026-09-07.abcdef',
            patternKey: 'project.cli_bootstrap',
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: ['packaging'],
            sourceFindings: ['finding.2026-06-08.001'],
            sourceProposals: [],
            validationCase: new ValidationCase('Given.', 'When.', 'Then.'),
            repositoryEvidence: [],
            content: new LearningNoteContent(
                title: 'Keep installed CLI bootstrap portable',
                context: 'Composer-installed consumers need a supported bootstrap path.',
                guidance: 'Resolve package and consumer autoloaders through the supported owner path.',
                whyItWorks: 'The package remains usable both standalone and when installed.',
                whenToApply: 'When changing an installed CLI entrypoint.',
                whenNotToApply: 'When no packaged executable is involved.',
                verification: 'Run the clean installed consumer.',
            ),
            createdAt: '2026-09-07T00:00:00+00:00',
            updatedAt: '2026-09-07T00:00:00+00:00',
        );
        (new LearningNoteRepository())->publish($this->root, $note);

        $service = new LearningLineageService();
        $service->rebuild($this->root, $this->root);

        $retiredDirectory = $this->root . '/notes/retired';
        self::assertTrue(is_dir($retiredDirectory) || mkdir($retiredDirectory, 0o775, true));
        self::assertNotFalse(file_put_contents($retiredDirectory . '/malformed.json', '{not-json'));

        $result = $service->precedentsForTask(
            $this->root,
            'PROJECT-1234',
            projectRoot: $this->root,
            maximumRelatedIdentities: 10,
        );

        self::assertSame('PROJECT-1234', $result->taskId);
        self::assertCount(1, $result->precedents);
        self::assertSame($note->id, $result->precedents[0]->id);
        self::assertSame('no_hashable_repository_evidence', $result->precedents[0]->evidenceState->value);
        self::assertContains('finding.2026-06-08.001', $result->lineage->identityIds);
        self::assertContains($note->id, $result->lineage->identityIds);
        self::assertContains(
            LearningLineageProjector::FINDING_FROM_TASK,
            array_map(static fn ($relation): string => $relation->kind, $result->lineage->relations),
        );
        self::assertFalse($result->lineage->truncated);
    }

    public function testTaskPrecedentsIncludeActiveNotesWhenTaskHasNoDirectLineage(): void
    {
        $note = new LearningNote(
            id: 'learning-note.2026-09-07.abcdef',
            patternKey: 'project.cli_bootstrap',
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: ['packaging'],
            sourceFindings: ['finding.2026-06-08.001'],
            sourceProposals: [],
            validationCase: new ValidationCase('Given.', 'When.', 'Then.'),
            repositoryEvidence: [],
            content: new LearningNoteContent(
                title: 'Keep installed CLI bootstrap portable',
                context: 'Composer-installed consumers need a supported bootstrap path.',
                guidance: 'Resolve package and consumer autoloaders through the supported owner path.',
                whyItWorks: 'The package remains usable both standalone and when installed.',
                whenToApply: 'When changing an installed CLI entrypoint.',
                whenNotToApply: 'When no packaged executable is involved.',
                verification: 'Run the clean installed consumer.',
            ),
            createdAt: '2026-09-07T00:00:00+00:00',
            updatedAt: '2026-09-07T00:00:00+00:00',
        );
        (new LearningNoteRepository())->publish($this->root, $note);

        $service = new LearningLineageService();
        $service->rebuild($this->root, $this->root);

        $result = $service->precedentsForTask(
            $this->root,
            'NEW-TASK-999',
            projectRoot: $this->root,
            maximumRelatedIdentities: 10,
        );

        self::assertSame('NEW-TASK-999', $result->taskId);
        self::assertCount(1, $result->precedents);
        self::assertSame($note->id, $result->precedents[0]->id);
        self::assertSame([], $result->lineage->identityIds);
        self::assertSame(['NEW-TASK-999' => 0], $result->lineage->depthByIdentityId);
        self::assertSame([], $result->lineage->relations);
        self::assertFalse($result->lineage->truncated);
    }

    public function testTaskPrecedentQueryReturnsBoundedEmptyObservationWithoutLineageSources(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-lineage-empty-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0o775, true));

        try {
            $result = (new LearningLineageService())->precedentsForTask(
                $root,
                'EMPTY-123',
                maximumRelatedIdentities: 10,
            );

            self::assertSame('EMPTY-123', $result->taskId);
            self::assertSame([], $result->precedents);
            self::assertSame('EMPTY-123', $result->lineage->identityId);
            self::assertSame([], $result->lineage->identityIds);
            self::assertSame(['EMPTY-123' => 0], $result->lineage->depthByIdentityId);
            self::assertSame([], $result->lineage->relations);
            self::assertSame(3, $result->lineage->maximumDepth);
            self::assertSame(10, $result->lineage->maximumResults);
            self::assertFalse($result->lineage->truncated);
            self::assertFileDoesNotExist($root . '/.derived/lineage/graph.sqlite');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testNumericTaskIdHasLosslessIdentityDepthProjection(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-lineage-numeric-' . bin2hex(random_bytes(6));
        self::assertDirectoryDoesNotExist($root);

        $result = (new LearningLineageService())->precedentsForTask(
            $root,
            '403',
            maximumRelatedIdentities: 10,
        );

        self::assertSame('403', $result->lineage->identityId);
        self::assertSame(
            [
                ['identity_id' => '403', 'depth' => 0],
            ],
            $result->lineage->identityDepths(),
        );
        self::assertSame(
            $result->lineage->identityDepths(),
            $result->lineage->toArray()['identity_depths'],
        );
        self::assertDirectoryDoesNotExist($root);
    }

    public function testTaskPrecedentQueryReturnsBoundedEmptyObservationWhenRootDirectoryDoesNotExist(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-lineage-nonexistent-' . bin2hex(random_bytes(6));
        self::assertDirectoryDoesNotExist($root);

        $result = (new LearningLineageService())->precedentsForTask(
            $root,
            'EMPTY-456',
            maximumRelatedIdentities: 10,
        );

        self::assertSame('EMPTY-456', $result->taskId);
        self::assertSame([], $result->precedents);
        self::assertSame('EMPTY-456', $result->lineage->identityId);
        self::assertSame([], $result->lineage->identityIds);
        self::assertSame(['EMPTY-456' => 0], $result->lineage->depthByIdentityId);
        self::assertSame([], $result->lineage->relations);
        self::assertSame(3, $result->lineage->maximumDepth);
        self::assertSame(10, $result->lineage->maximumResults);
        self::assertFalse($result->lineage->truncated);
        self::assertDirectoryDoesNotExist($root);
    }

    public function testMissingGraphStillFailsWhenLineageSourcesExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Derived Learning lineage graph not found; rebuild it first.');

        (new LearningLineageService())->precedentsForTask($this->root, 'PROJECT-1234');
    }

    public function testChangedOwnerStateRejectsStaleDerivedGraph(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);

        $path = $this->root . '/findings/validated/finding.2026-06-08.001.json';
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertNotFalse(file_put_contents($path, $content . "\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Derived Learning lineage graph is stale');
        $service->lineage($this->root, 'proposal.2026-06-08.001');
    }

    public function testCyclicOwnerRelationsRemainBoundedAndReportTruncation(): void
    {
        $this->addConflict(
            $this->root . '/proposals/approved/proposal.2026-06-08.001.json',
            'proposal.2026-06-08.002',
        );
        $this->addConflict(
            $this->root . '/proposals/rejected/proposal.2026-06-08.002.json',
            'proposal.2026-06-08.001',
        );

        $service = new LearningLineageService();
        $service->rebuild($this->root);

        $complete = $service->lineage(
            $this->root,
            'proposal.2026-06-08.001',
            maximumDepth: 8,
            maximumResults: 10,
        );
        self::assertContains('proposal.2026-06-08.002', $complete->identityIds);
        self::assertContains('finding.2026-06-08.001', $complete->identityIds);
        self::assertContains('finding.2026-06-08.002', $complete->identityIds);
        self::assertCount(3, $complete->identityIds);
        self::assertFalse($complete->truncated);

        $limited = $service->lineage(
            $this->root,
            'proposal.2026-06-08.001',
            maximumDepth: 8,
            maximumResults: 1,
        );
        self::assertCount(1, $limited->identityIds);
        self::assertTrue($limited->truncated);
    }

    public function testLineageLimitsAreExplicitlyBounded(): void
    {
        $service = new LearningLineageService();
        $service->rebuild($this->root);

        try {
            $service->lineage($this->root, 'proposal.2026-06-08.001', maximumDepth: 9);
            self::fail('Expected an explicit maximum-depth refusal.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('between 1 and 8', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 500');
        $service->lineage($this->root, 'proposal.2026-06-08.001', maximumResults: 501);
    }

    private function addConflict(string $path, string $proposalId): void
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $record['conflicts_with'] = [$proposalId];
        $encoded = json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(file_put_contents($path, $encoded . "\n"));
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            self::fail('Unable to create test directory: ' . $target);
        }

        $entries = scandir($source);
        if ($entries === false) {
            self::fail('Unable to scan fixture directory: ' . $source);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $targetPath = $target . '/' . $entry;
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            self::assertTrue(copy($sourcePath, $targetPath), 'Unable to copy fixture: ' . $sourcePath);
        }
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }
}
