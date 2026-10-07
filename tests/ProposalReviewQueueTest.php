<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalReviewQueue;
use voku\AgentLearning\ProposalReviewRow;

final class ProposalReviewQueueTest extends TestCase
{
    private const string WORDING = 'Always assign the result of every with call on an immutable request because the clone is otherwise dropped silently.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-review-queue-' . bin2hex(random_bytes(8));
        foreach (['candidate', 'approved', 'rejected'] as $status) {
            mkdir($this->root . '/proposals/' . $status, 0777, true);
        }
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/src', 0777, true);
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOnlyCandidateAndApprovedProposalsAreListedAndRejectedOnesAreNot(): void
    {
        $this->writeNoDurableLearning('proposal.2026-06-08.001', 'candidate');
        $this->writeSkillProposal('proposal.2026-06-08.002', 'approved', 'src/skill.md');
        $this->writeSkillProposal('proposal.2026-06-08.003', 'rejected', 'src/other.md');

        $rows = $this->queue();

        self::assertSame(['proposal.2026-06-08.002', 'proposal.2026-06-08.001'], array_map(static fn (ProposalReviewRow $row): string => $row->id, $rows));
    }

    public function testAllowedTransitionsFollowStatusAndAction(): void
    {
        $this->writeNoDurableLearning('proposal.2026-06-08.001', 'candidate');
        $this->writeSkillProposal('proposal.2026-06-08.002', 'candidate', 'src/a.md');
        $this->writeSkillProposal('proposal.2026-06-08.003', 'approved', 'src/b.md');

        $byId = $this->byId($this->queue());

        self::assertSame(['proposal-approve', 'proposal-reject', 'proposal-acknowledge'], $byId['proposal.2026-06-08.001']->allowedTransitions);
        self::assertSame(['proposal-approve', 'proposal-reject'], $byId['proposal.2026-06-08.002']->allowedTransitions);
        self::assertSame(['proposal-mark-applied'], $byId['proposal.2026-06-08.003']->allowedTransitions);
    }

    public function testAgeIsMeasuredFromCreationAgainstTheInjectedClock(): void
    {
        $this->writeNoDurableLearning('proposal.2026-06-08.001', 'candidate');

        $rows = (new ProposalReviewQueue())->build($this->root, $this->root, [], new DateTimeImmutable('2026-06-18T12:00:00+00:00'));

        self::assertSame(10, $rows[0]->ageDays);
        self::assertSame(1, $rows[0]->sourceFindingCount);
    }

    public function testTargetThatAlreadyContainsTheWordingIsReportedAsExact(): void
    {
        file_put_contents($this->root . '/src/skill.md', "# Skill\n\n" . self::WORDING . "\n");
        $this->writeSkillProposal('proposal.2026-06-08.001', 'candidate', 'src/skill.md');

        $match = $this->queue()[0]->wordingMatches[0];

        self::assertSame('src/skill.md', $match->file);
        self::assertTrue($match->exact);
        self::assertSame(100, $match->containmentPercent);
    }

    public function testPartialOverlapIsMeasuredAsShareOfFourWordPhrasesAndMissingFilesAreSignalled(): void
    {
        file_put_contents($this->root . '/src/skill.md', 'Always assign the result of every with call on an immutable request. Unrelated words follow here.');
        $this->writeSkillProposal('proposal.2026-06-08.001', 'candidate', 'src/skill.md');

        $row = $this->queue(['src/probe-missing.md'])[0];

        self::assertFalse($row->wordingMatches[0]->exact);
        self::assertGreaterThan(0, $row->wordingMatches[0]->containmentPercent);
        self::assertLessThan(100, $row->wordingMatches[0]->containmentPercent);
        self::assertContains('probe_file_missing:src/probe-missing.md', $row->signals);
    }

    public function testMissingTargetFileAndMissingScopePathAreSignalledWithoutFailing(): void
    {
        $this->writeSkillProposal('proposal.2026-06-08.001', 'candidate', 'src/absent-skill.md');

        $signals = $this->queue()[0]->signals;

        self::assertContains('target_file_missing:src/absent-skill.md', $signals);
        self::assertContains('scope_path_missing:src/gone.php', $signals);
    }

    public function testProposalsOnTheSameTargetPointAtEachOtherWithTheirStatus(): void
    {
        file_put_contents($this->root . '/src/skill.md', "# Skill\n");
        $this->writeSkillProposal('proposal.2026-06-08.001', 'candidate', 'src/skill.md');
        $this->writeSkillProposal('proposal.2026-06-08.002', 'rejected', 'src/skill.md');

        $first = $this->byId($this->queue())['proposal.2026-06-08.001'];

        self::assertContains('same_target:proposal.2026-06-08.002(rejected)', $first->signals);
    }

    public function testCliPrintsMachineReadableRowsAndExitsZero(): void
    {
        file_put_contents($this->root . '/src/skill.md', "# Skill\n\n" . self::WORDING . "\n");
        $this->writeSkillProposal('proposal.2026-06-08.001', 'candidate', 'src/skill.md');

        // Out of process: the command writes to STDOUT, which an output buffer cannot observe.
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/agent-learning')
            . ' proposal-queue --root ' . escapeshellarg($this->root)
            . ' --project-root ' . escapeshellarg($this->root)
            . ' --probe src/skill.md --format json 2>&1',
            $output,
            $exitCode,
        );
        $rows = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertSame('proposal.2026-06-08.001', $rows[0]['id']);
        self::assertSame(['proposal-approve', 'proposal-reject'], $rows[0]['allowed_transitions']);
        self::assertTrue($rows[0]['wording_matches'][0]['exact']);
    }

    public function testQueueNeverContainsARecommendation(): void
    {
        $this->writeNoDurableLearning('proposal.2026-06-08.001', 'candidate');

        $properties = array_map(static fn (\ReflectionProperty $property): string => $property->getName(), (new \ReflectionClass(ProposalReviewRow::class))->getProperties());

        foreach ($properties as $property) {
            self::assertStringNotContainsString('recommend', strtolower($property));
            self::assertStringNotContainsString('verdict', strtolower($property));
        }
    }

    /**
     * @param list<string> $probes
     *
     * @return list<ProposalReviewRow>
     */
    private function queue(array $probes = []): array
    {
        return (new ProposalReviewQueue())->build($this->root, $this->root, $probes, new DateTimeImmutable('2026-06-18T12:00:00+00:00'));
    }

    /**
     * @param list<ProposalReviewRow> $rows
     *
     * @return array<string, ProposalReviewRow>
     */
    private function byId(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        return $byId;
    }

    private function writeNoDurableLearning(string $id, string $status): void
    {
        $this->write($id, $status, [
            'action' => 'NO_DURABLE_LEARNING',
            'reason' => 'No repeatable pattern beyond this single task.',
            'target_type' => null,
            'target' => null,
            'scope' => [],
            'old' => null,
            'new' => null,
            'boundary' => null,
            'validation' => [],
        ]);
    }

    private function writeSkillProposal(string $id, string $status, string $target): void
    {
        $this->write($id, $status, [
            'action' => 'ADD',
            'reason' => 'A repeated defect class deserves one reviewable rule in the owning skill.',
            'target_type' => 'skill',
            'target' => $target,
            'scope' => ['src/gone.php'],
            'old' => null,
            'new' => self::WORDING,
            'boundary' => 'Only immutable request builders.',
            'validation' => ['composer ci'],
            'scope_justification' => 'The owning skill is the place where this rule belongs and the scope names the one file.',
            'learning_decision' => 'UPDATE_SKILL',
            'pattern_key' => 'test.queue.pattern' . str_repeat('x', (int) substr($id, -1)),
            'validation_case' => ['given' => 'a request builder', 'when' => 'a with call is unassigned', 'then' => 'review flags it'],
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function write(string $id, string $status, array $fields): void
    {
        $data = array_merge([
            'id' => $id,
            'created_at' => '2026-06-08T12:00:00+00:00',
            'source_findings' => ['finding.2026-06-08.001'],
            'remaining_uncertainty' => [],
            'status' => $status,
            'proposed_by' => 'agent_alpha',
            'approved_by' => $status === 'approved' ? 'lars' : null,
            'approved_at' => $status === 'approved' ? '2026-06-09T12:00:00+00:00' : null,
            'existing_guidance_id' => null,
            'learning_decision' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'test.queue.pattern',
            'validation_case' => ['given' => 'a one-off task', 'when' => 'consolidating', 'then' => 'nothing durable'],
        ], $fields);
        if ($status === 'rejected') {
            $data['reason'] = 'Closed without adoption.';
        }
        file_put_contents($this->root . '/proposals/' . $status . '/' . $id . '.json', json_encode($data, JSON_THROW_ON_ERROR));
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
