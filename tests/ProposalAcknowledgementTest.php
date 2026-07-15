<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

final class ProposalAcknowledgementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-acknowledgement-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/acknowledged', 0777, true);
        mkdir($this->root . '/proposals/rejected', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAcknowledgesNoDurableLearningProposalSuccessfully(): void
    {
        $this->writeCandidateProposal($this->noDurableLearningProposalData());

        $manager = new ProposalTransitionManager();
        $manager->acknowledge($this->root, 'proposal.2026-06-08.001', 'lars', 'Correctly classified, closing formally');

        self::assertFileDoesNotExist($this->root . '/proposals/candidate/proposal.2026-06-08.001.json');
        self::assertFileExists($this->root . '/proposals/acknowledged/proposal.2026-06-08.001.json');

        $data = json_decode((string)file_get_contents($this->root . '/proposals/acknowledged/proposal.2026-06-08.001.json'), true);
        self::assertSame('acknowledged', $data['status']);
        self::assertSame('lars', $data['acknowledged_by']);
        self::assertSame('Correctly classified, closing formally', $data['reason']);
        self::assertIsString($data['acknowledged_at']);

        self::assertFileExists($this->root . '/history/acknowledged-proposals.jsonl');
        $history = file_get_contents($this->root . '/history/acknowledged-proposals.jsonl');
        self::assertIsString($history);
        self::assertStringContainsString('proposal.2026-06-08.001', $history);
        self::assertStringContainsString('Correctly classified, closing formally', $history);
    }

    public function testAcknowledgeRejectsNonNoDurableLearningAction(): void
    {
        $data = $this->noDurableLearningProposalData();
        $data['action'] = 'REPLACE';
        $data['target_type'] = 'skill';
        $data['target'] = 'some-skill';
        $data['old'] = 'old text';
        $data['new'] = 'new text';
        $this->writeCandidateProposal($data);

        $manager = new ProposalTransitionManager();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/only a NO_DURABLE_LEARNING proposal can be acknowledged/');

        $manager->acknowledge($this->root, 'proposal.2026-06-08.001', 'lars', 'irrelevant');
    }

    public function testAcknowledgeRequiresExplicitReason(): void
    {
        $this->writeCandidateProposal($this->noDurableLearningProposalData());

        $manager = new ProposalTransitionManager();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/acknowledgement reason must be explicit/');

        $manager->acknowledge($this->root, 'proposal.2026-06-08.001', 'lars', '   ');
    }

    public function testAcknowledgeRequiresCandidateStatus(): void
    {
        $data = $this->noDurableLearningProposalData();
        $data['status'] = 'rejected';
        $data['reason'] = 'already closed once';
        file_put_contents($this->root . '/proposals/rejected/proposal.2026-06-08.001.json', json_encode($data));

        $manager = new ProposalTransitionManager();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/proposal is not a candidate/');

        $manager->acknowledge($this->root, 'proposal.2026-06-08.001', 'lars', 'irrelevant');
    }

    /**
     * @return array<string, mixed>
     */
    private function noDurableLearningProposalData(): array
    {
        return [
            'id' => 'proposal.2026-06-08.001',
            'created_at' => '2026-06-08T12:00:00+00:00',
            'action' => 'NO_DURABLE_LEARNING',
            'source_findings' => ['finding.2026-06-08.001'],
            'reason' => 'No repeatable pattern beyond this single task.',
            'remaining_uncertainty' => [],
            'status' => 'candidate',
            'proposed_by' => 'agent_alpha',
            'approved_by' => null,
            'approved_at' => null,
            'existing_guidance_id' => null,
            'learning_decision' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'test.no_durable_learning_pattern',
            'validation_case' => [
                'given' => 'a bounded one-off task',
                'when' => 'consolidating findings',
                'then' => 'no durable guidance mutation is produced',
            ],
            'target_type' => null,
            'target' => null,
            'scope' => [],
            'old' => null,
            'new' => null,
            'boundary' => null,
            'validation' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCandidateProposal(array $data): void
    {
        file_put_contents(
            $this->root . '/proposals/candidate/proposal.2026-06-08.001.json',
            json_encode($data)
        );
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
}
