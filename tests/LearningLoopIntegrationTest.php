<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ActiveGuidanceRepository;
use voku\AgentLearning\ConsolidationInput;
use voku\AgentLearning\ConsolidationPromptBuilder;
use voku\AgentLearning\FindingLifecycle;
use voku\AgentLearning\FindingSelection;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingTransitionManager;
use voku\AgentLearning\FindingValidator;
use voku\AgentLearning\OutcomeRepository;
use voku\AgentLearning\ProposalImporter;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\RejectedGuidanceRepository;
use voku\AgentLearning\RejectedGuidanceSelector;

final class LearningLoopIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learning-loop-integration-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/candidate', 0777, true);
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/proposals/approved', 0777, true);
        mkdir($this->root . '/proposals/rejected', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/skills', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompleteGovernedLearningLoop(): void
    {
        $finding1Data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json'), true);
        $finding1Data['status'] = 'candidate';
        $finding1Data['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding1Data));

        $finding2Data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json'), true);
        $finding2Data['status'] = 'candidate';
        $finding2Data['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.002.json', json_encode($finding2Data));

        $ftManager = new FindingTransitionManager();
        $ftManager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'maintainer');
        $ftManager->transition($this->root, 'finding.2026-06-08.002', FindingStatus::VALIDATED, 'maintainer');

        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.002.json');

        $findingValidator = new FindingValidator();
        $findingLifecycle = new FindingLifecycle();
        $findingsById = [];
        foreach ($findingLifecycle->findingFiles($this->root) as $file) {
            $finding = $findingValidator->validateFile($file);
            $findingsById[$finding->id] = $finding;
        }

        $selection = new FindingSelection([], [], ['src/'], null, null);
        $selectedFindings = [];
        foreach ($findingsById as $finding) {
            if ($finding->status === FindingStatus::VALIDATED && in_array('src/', $finding->scope, true)) {
                $selectedFindings[] = $finding;
            }
        }
        self::assertCount(1, $selectedFindings);
        self::assertSame('finding.2026-06-08.001', $selectedFindings[0]->id);

        $memoryFile = $this->root . '/MEMORY.md';
        file_put_contents($memoryFile, "<!-- id: global-memory -->\n<!-- type: memory -->\nActive guidance memory content");
        $activeGuidance = (new ActiveGuidanceRepository())->loadAll($this->root, ['MEMORY.md']);

        $proposalData = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.002.json'), true);
        file_put_contents($this->root . '/proposals/rejected/proposal.2026-06-08.002.json', json_encode($proposalData));
        $rejectionRecord = [
            'id' => 'rejection.2026-06-08.002',
            'proposal_id' => 'proposal.2026-06-08.002',
            'reason' => 'Layout mismatch',
        ];
        file_put_contents($this->root . '/history/rejected-proposals.jsonl', json_encode($rejectionRecord) . "\n");

        $allRejected = (new RejectedGuidanceRepository())->loadAll($this->root);
        $rejectedGuidance = (new RejectedGuidanceSelector())->select($allRejected, ['src/'], ['finding.2026-06-08.001']);

        $input = new ConsolidationInput($selection, $selectedFindings, $activeGuidance, $rejectedGuidance);
        $prompt = (new ConsolidationPromptBuilder())->build($input);
        self::assertStringContainsString('untrusted repository data', strtolower($prompt));

        $llmOutput = [
            'action' => 'REPLACE',
            'source_findings' => ['finding.2026-06-08.001'],
            'reason' => 'Need to clarify the wrapper path.',
            'target_type' => 'skill',
            'target' => 'skill.auth-context',
            'scope' => ['src/'],
            'old' => 'Old placeholder.',
            'new' => 'New refined wrapper code.',
            'boundary' => 'Strict wrapper boundaries apply.',
            'validation' => ['Run unit tests.'],
        ];
        $resultFile = $this->root . '/consolidation-result.json';
        file_put_contents($resultFile, json_encode($llmOutput));

        $proposalId = (new ProposalImporter())->import($this->root, $resultFile);
        self::assertFileExists($this->root . '/proposals/candidate/' . $proposalId . '.json');

        $ptManager = new ProposalTransitionManager();
        $ptManager->approve($this->root, $proposalId, 'lars');
        self::assertFileExists($this->root . '/proposals/approved/' . $proposalId . '.json');

        $skillPath = $this->root . '/skills/auth-context.md';
        file_put_contents($skillPath, 'New refined wrapper code.');
        $validationFile = $this->root . '/validation-result.json';
        file_put_contents($validationFile, json_encode([
            'passed' => true,
            'target_source_ref' => 'skills/auth-context.md',
            'target_content_hash' => hash_file('sha256', $skillPath),
        ], JSON_THROW_ON_ERROR));
        $ptManager->apply($this->root, $proposalId, 'lars', 'commit_sha_xyz', $validationFile);
        self::assertFileExists($this->root . '/proposals/applied/' . $proposalId . '.json');

        $outcomeRecord = [
            'id' => 'outcome.2026-06-20.001',
            'task_id' => 'PROJECT-204',
            'applied_proposals' => [$proposalId],
            'guidance_used' => ['skill.auth-context'],
            'result' => 'successful',
            'validation' => [
                'tests_passed' => true,
            ],
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];
        $outcomeRepo = new OutcomeRepository();
        $outcomeRepo->record($this->root, $outcomeRecord);

        $outcomes = $outcomeRepo->loadAll($this->root);
        self::assertCount(1, $outcomes);
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
