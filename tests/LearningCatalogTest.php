<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\LearningCatalog;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteService;

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

    public function testOverviewDoesNotFlagCurrentLearningNoteSourceForImmediateAttention(): void
    {
        $base = sys_get_temp_dir() . '/learning-catalog-note-' . bin2hex(random_bytes(6));
        $root = $base . '/learning';
        $projectRoot = $base . '/project';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($projectRoot, 0777, true);
        file_put_contents(
            $root . '/config.json',
            json_encode([
                'schema_version' => '1.0',
                'project_root' => '../project',
                'constraint_generation_dir' => 'constraint-generation',
                'active_constraints_dir' => 'constraints/active',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $findingId = 'finding.2026-09-19.001';
        file_put_contents(
            $root . '/findings/validated/' . $findingId . '.json',
            json_encode([
                'id' => $findingId,
                'task_id' => 'GH-124',
                'session' => 'session_GH-124',
                'created_at' => '2026-09-19T03:00:00+00:00',
                'created_by' => 'test',
                'scope' => ['src/'],
                'observation' => 'A solved case should remain precedent.',
                'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced.']],
                'hypothesis' => 'Precedent should not be immediate Proposal work.',
                'validated_conclusion' => 'The Finding is reusable precedent.',
                'confidence' => 'high',
                'validation_status' => 'validated',
                'status' => 'validated',
                'sensitivity' => 'public',
                'classification' => 'ADD_LEARNING_NOTE',
                'pattern_key' => 'workflow.precedent',
                'validation_case' => [
                    'given' => 'A later related task.',
                    'when' => 'The precedent applies.',
                    'then' => 'Reuse it without treating it as active guidance.',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        (new LearningNoteService())->publish(
            $root,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['workflow'],
                repositoryEvidence: [],
                content: new LearningNoteContent(
                    title: 'Precedent',
                    context: 'A solved case exists.',
                    guidance: 'Reuse the solved case when relevant.',
                    whyItWorks: 'It preserves bounded prior evidence.',
                    whenToApply: 'On related work.',
                    whenNotToApply: 'When current evidence conflicts.',
                    verification: 'Inspect current evidence and source lineage.',
                ),
            ),
            $projectRoot,
        );

        $overview = (new LearningCatalog($root))->overview();

        self::assertSame(1, $overview->findingCounts['validated']);
        self::assertSame([], $overview->findingAttentionIds);

        $this->removeDirectory($base);
    }

    public function testCompleteListQueriesPreserveOwnerProjectionAndStatusFiltering(): void
    {
        $catalog = new LearningCatalog($this->root);

        $findings = $catalog->findings();
        self::assertCount(2, $findings);
        self::assertSame(
            ['finding.2026-06-08.002', 'finding.2026-06-08.001'],
            array_map(static fn ($finding): string => $finding->id, $findings),
        );
        self::assertSame(
            ['finding.2026-06-08.002', 'finding.2026-06-08.001'],
            array_map(static fn ($finding): string => $finding->id, $catalog->findings('validated')),
        );
        self::assertSame([], $catalog->findings('rejected'));

        $proposals = $catalog->proposals();
        self::assertCount(2, $proposals);
        self::assertSame(
            ['proposal.2026-06-08.002', 'proposal.2026-06-08.001'],
            array_map(static fn ($proposal): string => $proposal->id, $proposals),
        );
        self::assertSame(['proposal.2026-06-08.001'], array_map(
            static fn ($proposal): string => $proposal->id,
            $catalog->proposals('approved'),
        ));
        self::assertSame(['proposal.2026-06-08.002'], array_map(
            static fn ($proposal): string => $proposal->id,
            $catalog->proposals('rejected'),
        ));
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

    public function testFindingsAndProposalsListQueriesSupportFiltering(): void
    {
        $catalog = new LearningCatalog($this->root);

        $allFindings = $catalog->findings();
        self::assertCount(2, $allFindings);

        $validatedFindings = $catalog->findings('validated');
        self::assertCount(2, $validatedFindings);

        $candidateFindings = $catalog->findings('candidate');
        self::assertSame([], $candidateFindings);

        $allProposals = $catalog->proposals();
        self::assertCount(2, $allProposals);

        $approvedProposals = $catalog->proposals('approved');
        self::assertCount(1, $approvedProposals);
        self::assertSame('proposal.2026-06-08.001', $approvedProposals[0]->id);

        $rejectedProposals = $catalog->proposals('rejected');
        self::assertCount(1, $rejectedProposals);
        self::assertSame('proposal.2026-06-08.002', $rejectedProposals[0]->id);

        $candidateProposals = $catalog->proposals('candidate');
        self::assertSame([], $candidateProposals);
    }

    public function testCatalogReadsDoNotMutateLearningRoot(): void
    {
        $before = $this->snapshot($this->root);
        $catalog = new LearningCatalog($this->root);

        $catalog->overview();
        $catalog->findings();
        $catalog->proposals();
        $catalog->finding('finding.2026-06-08.001');
        $catalog->proposal('proposal.2026-06-08.002');
        $catalog->guidance('proposal.2026-06-08.001');
        $catalog->forTask('PROJECT-1234');

        self::assertSame($before, $this->snapshot($this->root));
    }

    public function testCorpusAnalyticsReturnsAggregatedResult(): void
    {
        $catalog = new LearningCatalog($this->root);
        $analytics = $catalog->corpusAnalytics();

        self::assertGreaterThanOrEqual(2, $analytics->summary['total_findings']);
        self::assertGreaterThanOrEqual(2, $analytics->summary['total_proposals']);
        self::assertNotEmpty($analytics->cohorts);
        self::assertArrayHasKey('terminal_proposals', $analytics->lifecycleBreakdown);
        self::assertArrayHasKey('findings_per_proposal_distribution', $analytics->consolidation);
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
