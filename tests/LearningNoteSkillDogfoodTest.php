<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;

final class LearningNoteSkillDogfoodTest extends TestCase
{
    private string $base;
    private string $learningRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/learning-note-skill-dogfood-' . bin2hex(random_bytes(8));
        $this->learningRoot = $this->base . '/learning';
        $this->projectRoot = $this->base . '/project';

        mkdir($this->learningRoot . '/findings/consolidated', 0777, true);
        mkdir($this->projectRoot . '/src', 0777, true);
        mkdir($this->projectRoot . '/phpstan/Rules', 0777, true);
        mkdir($this->projectRoot . '/agent-loop/resources', 0777, true);
        mkdir($this->projectRoot . '/agent-recall-compiler/skills/agent-recall-consumer', 0777, true);

        file_put_contents($this->learningRoot . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../project',
            'constraint_generation_dir' => 'constraint-generation',
            'active_constraints_dir' => 'constraints/active',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        // Bounded current-tree evidence copied from the current owner repositories.
        file_put_contents(
            $this->projectRoot . '/src/GitWorkTree.php',
            "<?php\nfinal class GitWorkTree { public static function detected(string \$rootPath): bool { return true; } }\n",
        );
        file_put_contents(
            $this->projectRoot . '/phpstan/Rules/NoGitDirectoryShapeAssumptionRule.php',
            "<?php\n// Ask Git through GitWorkTree instead of inferring repository state from is_dir(.git).\n",
        );
        file_put_contents(
            $this->projectRoot . '/agent-loop/resources/operating-prompts.json',
            "{\"schema_version\":\"1.0\",\"prompts\":[{\"id\":\"momentum\",\"level\":1}]}\n",
        );
        file_put_contents(
            $this->projectRoot . '/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json',
            "{\"schema_version\":\"1.0\",\"prompts\":[{\"id\":\"adversarial-review\",\"level\":2}]}\n",
        );

        $bugValidation = [
            'given' => 'a checkout created with git worktree add, where .git is a file',
            'when' => 'code decides whether the path is a repository by testing is_dir on the .git entry',
            'then' => 'a valid checkout is treated as non-Git and dependent setup is skipped with a warning rather than an error',
        ];
        $this->writeHistoricalFinding([
            'id' => 'finding.2026-08-08.001',
            'task_id' => 'TODO@agent-loop/self-shape-pr-29',
            'session' => '2026-08-08-self-shape',
            'created_at' => '2026-08-08T05:53:00+02:00',
            'created_by' => 'self-dogfood',
            'scope' => ['src/Edit/WorkingTreeSnapshotter.php', 'tests/AgentResultContractTest.php', 'tools/self-edit-dogfood.php'],
            'observation' => 'WorkingTreeSnapshotter treated a path as Git-backed only when <root>/.git was a directory. A linked git worktree stores .git as a file, so valid edit evidence became unavailable.',
            'evidence' => [[
                'type' => 'test_result',
                'command' => 'GitHub Actions CI run 31238145054: tests (PHP 8.3, 8.4, 8.5)',
                'summary' => 'The linked-worktree regression and installed release-set dogfood passed.',
            ]],
            'hypothesis' => 'Repository evidence should be discovered by invoking Git, not by predicting Git metadata shape.',
            'validated_conclusion' => 'WorkingTreeSnapshotter must let git rev-parse/status determine whether a path is a usable working tree.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'consolidated',
            'sensitivity' => 'public',
        ], 'git.repository.state.asked.not.inferred', $bugValidation);
        $this->writeHistoricalFinding([
            'id' => 'finding.2026-08-14.013',
            'task_id' => 'GH-96',
            'session' => '2026-08-14-agent-loop-consolidation',
            'created_at' => '2026-08-14T05:10:00+02:00',
            'created_by' => 'agent-loop-consolidation',
            'scope' => ['src/GitWorkTree.php', 'src/Init/InitDoctorCommand.php', 'src/Init/InitSyncGitHooksCommand.php', 'phpstan/Rules/NoGitDirectoryShapeAssumptionRule.php'],
            'observation' => 'init doctor and init sync-githooks both treated is_dir($root . "/.git") as repository detection, which fails in a linked worktree where .git is a file.',
            'evidence' => [[
                'type' => 'test_result',
                'command' => 'vendor/bin/phpunit --filter GitWorkTreeDetectionTest',
                'summary' => 'Reverting either production call site to the .git directory-shape check fails the regression.',
            ]],
            'hypothesis' => 'Filesystem shape was treated as a repository contract and produced silent warning paths.',
            'validated_conclusion' => 'Repository state is asked of Git; GitWorkTree owns the question and static analysis rejects the production shape test.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'consolidated',
            'sensitivity' => 'public',
        ], 'git.repository.state.asked.not.inferred', $bugValidation);

        $architectureValidation = [
            'given' => 'a machine-readable instruction asset whose correctness depends on a tool CLI, schema or generated artifacts',
            'when' => 'the canonical copy lives in a separate repository that the consumer must pin by commit',
            'then' => 'code and instructions acquire independent release cadences, and a green gate depends on an unrelated repository SHA',
        ];
        $this->writeHistoricalFinding([
            'id' => 'finding.2026-08-14.005',
            'task_id' => 'PROMPT-OWNERSHIP-001',
            'session' => '2026-08-14-prompt-ownership',
            'created_at' => '2026-08-14T05:38:00+02:00',
            'created_by' => 'cross-repo-prompt-sync',
            'scope' => ['.github/workflows/ci.yml', 'docs/agents/PROMPT_PRIMITIVES.md', 'composer.json', 'voku/agent-recall-compiler:skills/agent-recall-consumer/SKILL.md'],
            'observation' => 'agent-loop pinned a separate agent-skills commit solely to provide a Recall-specific operating-prompt catalog while Recall code and releases evolved independently.',
            'evidence' => [[
                'type' => 'test_result',
                'command' => 'agent-recall-compiler PR #44 CI',
                'summary' => 'A Recall-owned catalog compiled through the real CLI and was validated with the implementation.',
            ]],
            'hypothesis' => 'Tool-coupled machine-readable instructions drift when their canonical copy has an independent release cadence.',
            'validated_conclusion' => 'Tool-coupled skills and machine-readable instruction assets ship and test with the repository that owns the tool contract.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'consolidated',
            'sensitivity' => 'public',
        ], 'tool.coupled.instructions.ship.with.the.tool', $architectureValidation);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    public function testRealBugLearningBecomesBugShapedCurrentPrecedent(): void
    {
        $service = new LearningNoteService();
        $findingIds = ['finding.2026-08-08.001', 'finding.2026-08-14.013'];
        $prepared = $service->prepare($this->learningRoot, $findingIds, $this->projectRoot);

        self::assertSame('git.repository.state.asked.not.inferred', $prepared->patternKey);
        self::assertCount(2, $prepared->findings);

        $note = $service->publish(
            $this->learningRoot,
            new LearningNoteDraft(
                sourceFindings: $findingIds,
                sourceProposals: [],
                tags: ['git', 'worktree', 'repository-detection'],
                repositoryEvidence: [
                    $this->repositoryEvidence('src/GitWorkTree.php'),
                    $this->repositoryEvidence('phpstan/Rules/NoGitDirectoryShapeAssumptionRule.php'),
                ],
                content: new LearningNoteContent(
                    title: 'Ask Git for repository state',
                    context: 'Two historical agent-loop defects inferred repository state from the filesystem shape of .git in linked worktrees.',
                    guidance: 'Treat the old is_dir(.git) failures as historical; current repository detection asks Git through GitWorkTree and static analysis rejects the production shape shortcut.',
                    whyItWorks: 'Git understands linked worktrees and submodules without requiring callers to predict whether .git is a file or directory.',
                    whenToApply: 'When production code needs to decide whether a path is a usable Git working tree or needs repository-derived state.',
                    whenNotToApply: 'Tests may inspect the .git shape itself when that shape is the behavior being reproduced.',
                    verification: 'Inspect current GitWorkTree and NoGitDirectoryShapeAssumptionRule, then run the linked-worktree regression.',
                    symptoms: 'Valid linked worktrees were historically reported as non-Git and dependent setup or edit evidence was skipped.',
                    failedApproaches: ['Using is_dir($root . "/.git") as repository detection.'],
                    rootCause: 'The implementation treated one Git metadata filesystem shape as the repository contract.',
                ),
            ),
            $this->projectRoot,
        );

        self::assertSame(LearningNoteEvidenceState::CURRENT, $note->evidenceState);
        self::assertSame($findingIds, $note->sourceFindings);
        self::assertNotNull($note->content->symptoms);
        self::assertNotNull($note->content->rootCause);
        self::assertStringContainsString('historical', $note->content->guidance);
        self::assertNotSame('', $note->digest);
    }

    public function testRealArchitectureLearningStaysKnowledgeShapedWithoutFakeRootCause(): void
    {
        $service = new LearningNoteService();
        $findingIds = ['finding.2026-08-14.005'];
        $prepared = $service->prepare($this->learningRoot, $findingIds, $this->projectRoot);

        self::assertSame('tool.coupled.instructions.ship.with.the.tool', $prepared->patternKey);

        $note = $service->publish(
            $this->learningRoot,
            new LearningNoteDraft(
                sourceFindings: $findingIds,
                sourceProposals: [],
                tags: ['ownership', 'skills', 'machine-readable-assets'],
                repositoryEvidence: [
                    $this->repositoryEvidence('agent-loop/resources/operating-prompts.json'),
                    $this->repositoryEvidence('agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json'),
                ],
                content: new LearningNoteContent(
                    title: 'Tool-coupled instruction assets ship with their owner',
                    context: 'A historical cross-repository prompt sync exposed an independent release cadence between Recall behavior and a canonical Recall-specific catalog kept elsewhere.',
                    guidance: 'Keep machine-readable assets with the repository whose CLI/schema/output contract defines them. Distinct tools may each own distinct operating-prompt catalogs; identical filenames do not imply shared ownership.',
                    whyItWorks: 'The asset and the contract that validates it then share one release, test and compatibility boundary.',
                    whenToApply: 'When correctness of an instruction or recipe asset depends on a specific tool API, CLI, schema, generated artifact or output contract.',
                    whenNotToApply: 'Tool-neutral principles may remain in a shared skill collection, and another tool may own a different catalog for its own contract.',
                    verification: 'Verify the current Recall consumer skill and its bundled operating-prompts.json, and separately verify agent-loop owns its loop-specific catalog.',
                    failedApproaches: ['Pinning an unrelated skill-repository commit solely to obtain a tool-specific canonical catalog.'],
                    examples: ['Recall owns adversarial-review recipes; agent-loop owns loop-level momentum/checkpoint recipes.'],
                ),
            ),
            $this->projectRoot,
        );

        self::assertSame(LearningNoteEvidenceState::CURRENT, $note->evidenceState);
        self::assertNull($note->content->symptoms);
        self::assertNull($note->content->rootCause);
        self::assertStringContainsString('Distinct tools may each own distinct', $note->content->guidance);
        self::assertNotSame('', $note->digest);
        self::assertCount(1, $service->activeProjections($this->learningRoot, $this->projectRoot));
    }

    /**
     * Historical Findings predate first-class Finding triage metadata. The
     * classification, pattern key and validation case are copied mechanically
     * from their later user-reviewed approved/applied agent-loop Proposals.
     *
     * @param array<string, mixed> $record
     * @param array{given: string, when: string, then: string} $validationCase
     */
    private function writeHistoricalFinding(array $record, string $patternKey, array $validationCase): void
    {
        $record['classification'] = 'ADD_LEARNING_NOTE';
        $record['pattern_key'] = $patternKey;
        $record['validation_case'] = $validationCase;
        $id = $record['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \LogicException('Dogfood Finding requires a string id.');
        }

        file_put_contents(
            $this->learningRoot . '/findings/consolidated/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function repositoryEvidence(string $sourceRef): LearningNoteRepositoryEvidence
    {
        $sha256 = hash_file('sha256', $this->projectRoot . '/' . $sourceRef);
        if (!is_string($sha256)) {
            throw new \RuntimeException('Cannot hash dogfood source: ' . $sourceRef);
        }

        return new LearningNoteRepositoryEvidence($sourceRef, $sha256);
    }
}
