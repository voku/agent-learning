<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingConsolidationService;
use voku\AgentLearning\FindingReviewQueue;
use voku\AgentLearning\LearningCatalog;
use voku\AgentLearning\ProposalTransitionManager;

final class FindingConsolidationTest extends TestCase
{
    private const string FIRST = 'finding.2026-06-08.001';
    private const string SECOND = 'finding.2026-06-08.002';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/finding-consolidation-' . bin2hex(random_bytes(8));
        foreach (['findings/validated', 'proposals/candidate', 'proposals/rejected', 'proposals/acknowledged', 'history', 'src'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        foreach ([self::FIRST, self::SECOND] as $id) {
            $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/findings/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $data['validated_by'] = 'original-validator';
            $data['validated_at'] = '2026-06-09T10:00:00+00:00';
            file_put_contents($this->root . '/findings/validated/' . $id . '.json', json_encode($data, JSON_THROW_ON_ERROR));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectingTheOnlyProposalConsolidatesItsFindingWithoutRewritingWhoValidatedIt(): void
    {
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST]);

        (new ProposalTransitionManager())->reject($this->root, 'proposal.2026-06-08.001', 'lars', 'Not worth a rule.');

        $finding = $this->readFinding('consolidated', self::FIRST);
        self::assertSame('consolidated', $finding['status']);
        self::assertSame('original-validator', $finding['validated_by'], 'consolidating must not rewrite the validation provenance');
        self::assertSame('2026-06-09T10:00:00+00:00', $finding['validated_at']);
        self::assertFileExists($this->root . '/findings/validated/' . self::SECOND . '.json', 'an uncited finding stays backlog');
    }

    public function testAcknowledgingConsolidatesTheSourceFindingToo(): void
    {
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST], noDurableLearning: true);

        (new ProposalTransitionManager())->acknowledge($this->root, 'proposal.2026-06-08.001', 'lars', 'Already covered.');

        self::assertFileExists($this->root . '/findings/consolidated/' . self::FIRST . '.json');
    }

    public function testFindingStaysValidatedWhileAnotherProposalStillWaitsOnIt(): void
    {
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST]);
        $this->writeCandidate('proposal.2026-06-08.002', [self::FIRST]);

        (new ProposalTransitionManager())->reject($this->root, 'proposal.2026-06-08.001', 'lars', 'Superseded by the second.');

        self::assertFileExists($this->root . '/findings/validated/' . self::FIRST . '.json');
        self::assertFileDoesNotExist($this->root . '/findings/consolidated/' . self::FIRST . '.json');
    }

    public function testReconcileRepairsFindingsDecidedBeforeTransitionsConsolidatedThemAndLeavesOpenOnesAlone(): void
    {
        // A root written by an older package: the proposal is already rejected, the finding is still validated.
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST]);
        $this->writeCandidate('proposal.2026-06-08.002', [self::SECOND]);
        rename($this->root . '/proposals/candidate/proposal.2026-06-08.001.json', $this->root . '/proposals/rejected/proposal.2026-06-08.001.json');
        $rejected = json_decode((string) file_get_contents($this->root . '/proposals/rejected/proposal.2026-06-08.001.json'), true);
        $rejected['status'] = 'rejected';
        $rejected['reason'] = 'Closed without adoption.';
        file_put_contents($this->root . '/proposals/rejected/proposal.2026-06-08.001.json', json_encode($rejected));

        $service = new FindingConsolidationService();
        self::assertSame([self::FIRST], $service->decidedFindingIds($this->root));

        self::assertSame([self::FIRST], $service->consolidate($this->root, 'lars'));
        self::assertFileExists($this->root . '/findings/consolidated/' . self::FIRST . '.json');
        self::assertFileExists($this->root . '/findings/validated/' . self::SECOND . '.json');
        self::assertSame([], $service->decidedFindingIds($this->root));
    }

    public function testOverviewCountsOnlyOpenWorkAsAttention(): void
    {
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST]);
        rename($this->root . '/proposals/candidate/proposal.2026-06-08.001.json', $this->root . '/proposals/rejected/proposal.2026-06-08.001.json');
        $rejected = json_decode((string) file_get_contents($this->root . '/proposals/rejected/proposal.2026-06-08.001.json'), true);
        $rejected['status'] = 'rejected';
        $rejected['reason'] = 'Closed without adoption.';
        file_put_contents($this->root . '/proposals/rejected/proposal.2026-06-08.001.json', json_encode($rejected));

        // FIRST is decided (bookkeeping), SECOND has no proposal at all (real backlog).
        self::assertSame([self::SECOND], (new LearningCatalog($this->root))->overview()->findingAttentionIds);
    }

    public function testFindingQueueStatesFactsAndNeverRecommends(): void
    {
        $this->writeCandidate('proposal.2026-06-08.001', [self::FIRST]);
        file_put_contents($this->root . '/MEMORY.md', "# Memory\n");

        $rows = (new FindingReviewQueue())->build($this->root, $this->root, ['MEMORY.md', 'gone.md'], new DateTimeImmutable('2026-06-18T12:00:00+00:00'));
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        self::assertContains('proposal_open:proposal.2026-06-08.001(candidate)', $byId[self::FIRST]->signals);
        self::assertContains('no_proposal', $byId[self::SECOND]->signals);
        self::assertContains('probe_file_missing:gone.md', $byId[self::SECOND]->signals);
        self::assertSame(['consolidated', 'superseded', 'archived'], $byId[self::SECOND]->allowedTransitions);
        self::assertSame('MEMORY.md', $byId[self::SECOND]->wordingMatches[0]->file);

        foreach ((new \ReflectionClass($rows[0]))->getProperties() as $property) {
            self::assertStringNotContainsString('recommend', strtolower($property->getName()));
            self::assertStringNotContainsString('verdict', strtolower($property->getName()));
        }
    }

    public function testReconcileCommandRequiresAnActorUnlessDryRun(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' finding-reconcile --root ' . escapeshellarg($this->root);

        exec($command . ' 2>&1', $output, $exitCode);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires --by', implode("\n", $output));

        $output = [];
        exec($command . ' --dry-run 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Would consolidate 0 finding(s)', implode("\n", $output));
    }

    /**
     * @param list<string> $sourceFindings
     */
    private function writeCandidate(string $id, array $sourceFindings, bool $noDurableLearning = false): void
    {
        $data = [
            'id' => $id,
            'created_at' => '2026-06-08T12:00:00+00:00',
            'action' => $noDurableLearning ? 'NO_DURABLE_LEARNING' : 'ADD',
            'source_findings' => $sourceFindings,
            'reason' => $noDurableLearning ? 'No repeatable pattern beyond this single task.' : 'A repeated defect class deserves one reviewable rule.',
            'remaining_uncertainty' => [],
            'status' => 'candidate',
            'proposed_by' => 'agent_alpha',
            'approved_by' => null,
            'approved_at' => null,
            'existing_guidance_id' => null,
            'learning_decision' => $noDurableLearning ? 'ADD_LEARNING_NOTE' : 'UPDATE_SKILL',
            'pattern_key' => 'test.consolidation.pattern' . str_repeat('x', (int) substr($id, -1)),
            'validation_case' => ['given' => 'a finding', 'when' => 'its proposal is decided', 'then' => 'the finding is consolidated'],
            'target_type' => $noDurableLearning ? null : 'skill',
            'target' => $noDurableLearning ? null : 'src/skill.md',
            'scope' => $noDurableLearning ? [] : ['src/'],
            'old' => null,
            'new' => $noDurableLearning ? null : 'Always assign the result of every with call on an immutable request.',
            'boundary' => $noDurableLearning ? null : 'Only immutable request builders.',
            'validation' => $noDurableLearning ? [] : ['composer ci'],
        ];
        if (!$noDurableLearning) {
            $data['scope_justification'] = 'The owning skill is where this rule belongs and the scope names its one directory.';
        }
        file_put_contents($this->root . '/proposals/candidate/' . $id . '.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function readFinding(string $directory, string $id): array
    {
        return json_decode((string) file_get_contents($this->root . '/findings/' . $directory . '/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
