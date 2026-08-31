<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\ValidationException;

final class LearningNoteServiceTest extends TestCase
{
    public function testPublishesAndAccumulatesOneStablePatternOwner(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $source = $projectRoot . '/src/Workflow/Foo.php';
        mkdir(dirname($source), 0777, true);
        file_put_contents($source, "<?php\n");
        $sha = hash_file('sha256', $source);
        self::assertIsString($sha);

        $service = new LearningNoteService();
        $first = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: ['workflow', 'ownership'],
            repositoryEvidence: [new LearningNoteRepositoryEvidence('src/Workflow/Foo.php', $sha)],
            content: $this->content('Use the project-layout owner.'),
        ), $projectRoot);

        self::assertSame('workflow.project-layout', $first->patternKey);
        self::assertSame(LearningNoteEvidenceState::CURRENT, $first->evidenceState);
        self::assertCount(1, (new LearningNoteRepository())->loadActive($root));

        $this->writeFinding($root, 'finding.2026-08-31.002', 'workflow.project-layout', 'tests/');
        $second = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.002'],
            sourceProposals: [],
            tags: ['workflow'],
            repositoryEvidence: [],
            content: $this->content('Use the typed project-layout owner API.'),
        ), $projectRoot);

        self::assertSame($first->id, $second->id);
        self::assertCount(1, (new LearningNoteRepository())->loadActive($root));
        self::assertSame('Use the typed project-layout owner API.', $second->content->guidance);
        self::assertSame(
            ['finding.2026-08-31.001', 'finding.2026-08-31.002'],
            $second->sourceFindings,
        );
        self::assertSame(['src/Workflow/', 'tests/'], $second->scope);
        self::assertSame(['ownership', 'workflow'], $second->tags);
        self::assertSame(LearningNoteEvidenceState::CURRENT, $second->evidenceState);
    }

    public function testPrepareRejectsDifferentPatternKeys(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $this->writeFinding($root, 'finding.2026-08-31.002', 'workflow.review-binding', 'src/Workflow/');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot merge different pattern_key');
        (new LearningNoteService())->prepare(
            $root,
            ['finding.2026-08-31.001', 'finding.2026-08-31.002'],
            $projectRoot,
        );
    }

    public function testPrepareRejectsUnclassifiedFinding(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/', false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be classified ADD_LEARNING_NOTE');
        (new LearningNoteService())->prepare($root, ['finding.2026-08-31.001'], $projectRoot);
    }

    public function testSourceDriftBecomesReviewNeededWithoutMutation(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $source = $projectRoot . '/src/Workflow/Foo.php';
        mkdir(dirname($source), 0777, true);
        file_put_contents($source, "<?php\n");
        $sha = hash_file('sha256', $source);
        self::assertIsString($sha);

        $service = new LearningNoteService();
        $created = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [new LearningNoteRepositoryEvidence('src/Workflow/Foo.php', $sha)],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);
        file_put_contents($source, "<?php\n// changed\n");

        $projection = $service->activeProjections($root, $projectRoot)[0];
        self::assertSame($created->id, $projection->id);
        self::assertSame(LearningNoteEvidenceState::REVIEW_NEEDED, $projection->evidenceState);
        self::assertSame(LearningNoteStatus::ACTIVE, $projection->status);
    }

    public function testMissingSourceIsDistinctFromChangedSource(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');

        $service = new LearningNoteService();
        $projection = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [new LearningNoteRepositoryEvidence('src/Workflow/Missing.php', str_repeat('a', 64))],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);

        self::assertSame(LearningNoteEvidenceState::SOURCE_MISSING, $projection->evidenceState);
    }

    public function testRedactionRejectsPublication(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sensitive-data match');
        (new LearningNoteService())->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [],
            content: $this->content('token=super-secret'),
        ), $projectRoot);
    }

    public function testRepositoryEvidenceRejectsProjectEscape(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must stay project-relative');

        new LearningNoteRepositoryEvidence('../outside.php', str_repeat('a', 64));
    }

    public function testRetirementPreservesLineageAndRemovesActiveOwner(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $service = new LearningNoteService();
        $created = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);

        $retired = $service->retire($root, $created->id, 'Superseded by narrower evidence.');

        self::assertSame(LearningNoteStatus::RETIRED, $retired->status);
        self::assertSame([], (new LearningNoteRepository())->loadActive($root));
        $stored = (new LearningNoteRepository())->find($root, $created->id);
        self::assertNotNull($stored);
        self::assertSame(['finding.2026-08-31.001'], $stored->sourceFindings);
        self::assertSame('Superseded by narrower evidence.', $stored->retiredReason);
    }

    public function testDuplicateActivePatternOwnershipFailsExplicitly(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $service = new LearningNoteService();
        $projection = $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);

        $sourcePath = $root . '/notes/active/' . $projection->id . '.json';
        $raw = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw);
        $raw['id'] = 'learning-note.2026-08-31.abcdef';
        file_put_contents(
            $root . '/notes/active/learning-note.2026-08-31.abcdef.json',
            json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('duplicate active LearningNote pattern_key');
        (new LearningNoteRepository())->findActiveByPatternKey($root, 'workflow.project-layout');
    }

    public function testUnsupportedSchemaFailsInsteadOfBeingReinterpreted(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $projection = (new LearningNoteService())->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);
        $path = $root . '/notes/active/' . $projection->id . '.json';
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw);
        $raw['schema_version'] = '2.0';
        file_put_contents($path, json_encode($raw, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported LearningNote schema_version');
        (new LearningNoteRepository())->loadAll($root);
    }

    public function testSemanticDigestSurvivesIncidentalJsonKeyOrdering(): void
    {
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'workflow.project-layout', 'src/Workflow/');
        $projection = (new LearningNoteService())->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: ['workflow'],
            repositoryEvidence: [],
            content: $this->content('Use the owner API.'),
        ), $projectRoot);
        $path = $root . '/notes/active/' . $projection->id . '.json';
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $record = array_reverse($record, true);
        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

        $reloaded = (new LearningNoteRepository())->find($root, $projection->id);
        self::assertNotNull($reloaded);
        self::assertSame($projection->digest, $reloaded->digest());
    }

    /** @return array{string, string} */
    private function root(): array
    {
        $base = sys_get_temp_dir() . '/agent-learning-note-' . bin2hex(random_bytes(6));
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

        return [$root, $projectRoot];
    }

    private function writeFinding(string $root, string $id, string $patternKey, string $scope, bool $classified = true): void
    {
        $record = [
            'id' => $id,
            'task_id' => 'TEST-54',
            'session' => 'session_TEST-54',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'test',
            'scope' => [$scope],
            'observation' => 'A consumer reconstructed owner behavior.',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in the test fixture.']],
            'hypothesis' => 'The missing owner boundary causes drift.',
            'validated_conclusion' => 'The owner boundary must remain explicit.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ];
        if ($classified) {
            $record['classification'] = 'ADD_LEARNING_NOTE';
            $record['pattern_key'] = $patternKey;
            $record['validation_case'] = [
                'given' => 'A later related task.',
                'when' => 'The prior owner-boundary problem applies.',
                'then' => 'The precedent is available without becoming authority.',
            ];
        }
        file_put_contents(
            $root . '/findings/validated/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function content(string $guidance): LearningNoteContent
    {
        return new LearningNoteContent(
            title: 'Project layout ownership',
            context: 'A consumer needed repository layout information.',
            guidance: $guidance,
            whyItWorks: 'The semantic owner remains the only place that knows its private layout.',
            whenToApply: 'When a consumer needs project-relative owner paths.',
            whenNotToApply: 'When the caller already owns the path.',
            verification: 'Run the owner API regression and consumer test.',
        );
    }
}
