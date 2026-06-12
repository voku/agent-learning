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
use voku\AgentLearning\ProposalStatus;
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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompleteGovernedLearningLoop(): void
    {
        // 1. Import candidate findings from two separate tasks
        $finding1Data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json'), true);
        $finding1Data['status'] = 'candidate';
        $finding1Data['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding1Data));

        $finding2Data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.002.json'), true);
        $finding2Data['status'] = 'candidate';
        $finding2Data['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.002.json', json_encode($finding2Data));

        // 2. Validate and transition them to validated
        $ftManager = new FindingTransitionManager();
        $ftManager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'maintainer');
        $ftManager->transition($this->root, 'finding.2026-06-08.002', FindingStatus::VALIDATED, 'maintainer');

        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.002.json');

        // 3. Select them by shared scope
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

        // 4. Load one relevant active memory entry
        $memoryFile = $this->root . '/MEMORY.md';
        file_put_contents($memoryFile, "<!-- id: global-memory -->\n<!-- type: memory -->\nActive guidance memory content");
        $activeRepo = new ActiveGuidanceRepository();
        $activeGuidance = $activeRepo->loadAll($this->root, ['MEMORY.md']);

        // 5. Load one relevant rejected proposal (create mock rejected proposal first)
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

        // 6. Generate safe consolidation input
        $input = new ConsolidationInput($selection, $selectedFindings, $activeGuidance, $rejectedGuidance);
        $prompt = (new ConsolidationPromptBuilder())->build($input);
        self::assertStringContainsString('untrusted repository data', strtolower($prompt));

        // 7. Receive one strict JSON REPLACE result
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

        // 8. Import it as candidate proposal
        $importer = new ProposalImporter();
        $proposalId = $importer->import($this->root, $resultFile);
        self::assertFileExists($this->root . '/proposals/candidate/' . $proposalId . '.json');

        // 9. Human approves it
        $ptManager = new ProposalTransitionManager();
        $ptManager->approve($this->root, $proposalId, 'lars');
        self::assertFileExists($this->root . '/proposals/approved/' . $proposalId . '.json');

        // 10. Mark applied with commit and validation evidence
        $validationFile = $this->root . '/validation-result.json';
        file_put_contents($validationFile, '{"passed": true}');
        $ptManager->apply($this->root, $proposalId, 'lars', 'commit_sha_xyz', $validationFile);
        self::assertFileExists($this->root . '/proposals/applied/' . $proposalId . '.json');

        // 11. Record outcome
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

        // 12. Run validation successfully
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
