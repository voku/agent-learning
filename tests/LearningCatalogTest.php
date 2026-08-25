<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\LearningCatalog;

final class LearningCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = __DIR__ . '/fixtures/project';
    }

    public function testOverviewUsesOwnerStatusesAndKeepsHistoricalProposalState(): void
    {
        $overview = (new LearningCatalog($this->root))->overview();

        self::assertSame(2, $overview->findingCounts['validated']);
        self::assertSame(1, $overview->proposalCounts['approved']);
        self::assertSame(1, $overview->proposalCounts['rejected']);
        self::assertSame(1, $overview->guidanceCounts[GuidanceType::SKILL->value]);
        self::assertSame(0, $overview->guidanceCounts[GuidanceType::MEMORY->value]);
        self::assertSame(
            ['finding.2026-06-08.001', 'finding.2026-06-08.002'],
            $overview->findingAttentionIds,
        );
        self::assertSame([], $overview->proposalAttentionIds);
        self::assertSame(['proposal.2026-06-08.001'], $overview->recentDurableGuidanceIds);
        self::assertCount(2, $overview->recentFindingIds);
        self::assertContains('finding.2026-06-08.001', $overview->recentFindingIds);
        self::assertContains('finding.2026-06-08.002', $overview->recentFindingIds);
        self::assertCount(2, $overview->recentProposalIds);
        self::assertContains('proposal.2026-06-08.001', $overview->recentProposalIds);
        self::assertContains('proposal.2026-06-08.002', $overview->recentProposalIds);
    }

    public function testDetailQueriesPreserveFindingProposalGuidanceLineage(): void
    {
        $catalog = new LearningCatalog($this->root);

        $finding = $catalog->finding('finding.2026-06-08.001');
        self::assertNotNull($finding);
        self::assertSame('PROJECT-1234', $finding->taskId);
        self::assertSame(['proposal.2026-06-08.001'], $finding->proposalIds);

        $proposal = $catalog->proposal('proposal.2026-06-08.001');
        self::assertNotNull($proposal);
        self::assertSame('approved', $proposal->status);
        self::assertSame(['finding.2026-06-08.001'], $proposal->sourceFindingIds);
        self::assertSame(['PROJECT-1234'], $proposal->sourceTaskIds);
        self::assertSame('maintainer', $proposal->approvedBy);

        $guidance = $catalog->guidance('proposal.2026-06-08.001');
        self::assertNotNull($guidance);
        self::assertSame(GuidanceType::SKILL, $guidance->type);
        self::assertSame('approved', $guidance->status);
        self::assertSame('proposal.2026-06-08.001', $guidance->sourceProposalId);
        self::assertSame(['finding.2026-06-08.001'], $guidance->sourceFindingIds);

        $rejected = $catalog->guidance('proposal.2026-06-08.002');
        self::assertNotNull($rejected);
        self::assertSame(GuidanceType::MEMORY, $rejected->type);
        self::assertSame('rejected', $rejected->status);
    }

    public function testTaskProjectionUsesRecordedFindingLineageOnly(): void
    {
        $catalog = new LearningCatalog($this->root);
        $task = $catalog->forTask('PROJECT-1234');

        self::assertCount(1, $task->findings);
        self::assertSame('finding.2026-06-08.001', $task->findings[0]->id);
        self::assertCount(1, $task->proposals);
        self::assertSame('proposal.2026-06-08.001', $task->proposals[0]->id);
        self::assertCount(1, $task->guidance);
        self::assertSame([], $task->outcomeIds);

        $unknown = $catalog->forTask('NOT-RECORDED');
        self::assertSame([], $unknown->findings);
        self::assertSame([], $unknown->proposals);
        self::assertSame([], $unknown->guidance);
    }

    public function testCatalogReadsDoNotMutateLearningRoot(): void
    {
        $before = $this->snapshot($this->root);
        $catalog = new LearningCatalog($this->root);

        $catalog->overview();
        $catalog->finding('finding.2026-06-08.001');
        $catalog->proposal('proposal.2026-06-08.002');
        $catalog->guidance('proposal.2026-06-08.001');
        $catalog->forTask('PROJECT-1234');

        self::assertSame($before, $this->snapshot($this->root));
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            $snapshot[substr($path, strlen($root) + 1)] = hash('sha256', $contents);
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
