<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNote;
use voku\AgentLearning\LearningNoteContent;
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

    public function testRebuildExposesBoundedOwnerLineageDeterministically(): void
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
        self::assertCount(1, $first->relations);
        self::assertSame(LearningLineageProjector::PROPOSAL_FROM_FINDING, $first->relations[0]->kind);
        self::assertSame('finding.2026-06-08.001', $first->relations[0]->sourceId);
        self::assertSame('proposal.2026-06-08.001', $first->relations[0]->targetId);
        self::assertFalse($first->truncated);

        $service->rebuild($this->root);
        $service->verifyCurrent($this->root);
        self::assertSame($first->toArray(), $service->lineage($this->root, 'proposal.2026-06-08.001')->toArray());
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

    public function testProjectorUsesOnlyMechanicallyEncodedV1Relations(): void
    {
        $finding = new FindingProjection(
            id: 'finding.2026-09-07.001',
            status: 'validated',
            taskId: 'TASK-1',
            session: 'SESSION-1',
            createdAt: '2026-09-07T00:00:00+00:00',
            observation: 'Observed.',
            validatedConclusion: 'Confirmed.',
            scope: ['src/'],
            evidence: [],
            proposalIds: ['proposal.2026-09-07.002'],
        );
        $proposal = new ProposalProjection(
            id: 'proposal.2026-09-07.002',
            status: 'approved',
            createdAt: '2026-09-07T00:01:00+00:00',
            action: 'REPLACE',
            targetType: null,
            target: null,
            scope: ['src/'],
            sourceFindingIds: [$finding->id],
            sourceTaskIds: ['TASK-1'],
            proposedChange: 'Use the corrected rule.',
            reason: 'Confirmed evidence.',
            boundary: null,
            validation: [],
            proposedBy: 'agent',
            approvedBy: 'maintainer',
            approvedAt: '2026-09-07T00:02:00+00:00',
            supersedesProposalIds: ['proposal.2026-09-06.001'],
            conflictsWithProposalIds: ['proposal.2026-09-06.002'],
            correctsProposalId: 'proposal.2026-09-06.003',
        );
        $note = new LearningNote(
            id: 'learning-note.2026-09-07.001',
            patternKey: 'lineage.test',
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: [],
            sourceFindings: [$finding->id],
            sourceProposals: [$proposal->id],
            validationCase: new ValidationCase('Given.', 'When.', 'Then.'),
            repositoryEvidence: [],
            content: new LearningNoteContent(
                title: 'Lineage test',
                context: 'Context.',
                guidance: 'Guidance.',
                whyItWorks: 'Because.',
                whenToApply: 'When applicable.',
                whenNotToApply: 'When not applicable.',
                verification: 'Verify it.',
            ),
            createdAt: '2026-09-07T00:03:00+00:00',
            updatedAt: '2026-09-07T00:03:00+00:00',
        );

        $relations = (new LearningLineageProjector())->relations([$finding], [$proposal], [$note]);
        $actual = array_map(
            static function (GraphRelation $relation): array {
                self::assertCount(1, $relation->targetIds);

                return [$relation->kind, $relation->sourceId, $relation->targetIds[0]];
            },
            $relations,
        );
        sort($actual);

        $expected = [
            [LearningLineageProjector::NOTE_FROM_FINDING, $finding->id, $note->id],
            [LearningLineageProjector::NOTE_FROM_PROPOSAL, $proposal->id, $note->id],
            [LearningLineageProjector::PROPOSAL_CONFLICTS_WITH, 'proposal.2026-09-06.002', $proposal->id],
            [LearningLineageProjector::PROPOSAL_CORRECTS, 'proposal.2026-09-06.003', $proposal->id],
            [LearningLineageProjector::PROPOSAL_FROM_FINDING, $finding->id, $proposal->id],
            [LearningLineageProjector::PROPOSAL_SUPERSEDES, 'proposal.2026-09-06.001', $proposal->id],
        ];
        sort($expected);

        self::assertSame($expected, $actual);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            self::fail('Unable to create test directory: ' . $target);
        }

        $entries = scandir($source);
        self::assertIsArray($entries);
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
