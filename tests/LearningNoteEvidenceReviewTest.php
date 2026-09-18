<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;

final class LearningNoteEvidenceReviewTest extends TestCase
{
    private string $base;

    private string $root;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/learning-note-evidence-review-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/learning';
        $this->projectRoot = $this->base . '/project';

        mkdir($this->root . '/findings/validated', 0o775, true);
        mkdir($this->projectRoot . '/src', 0o775, true);
        file_put_contents($this->root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../project',
            'constraint_generation_dir' => 'constraint-generation',
            'active_constraints_dir' => 'constraints/active',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($this->root . '/findings/validated/finding.2026-09-18.121001.json', json_encode([
            'id' => 'finding.2026-09-18.121001',
            'task_id' => 'GH-121',
            'session' => 'session_GH-121',
            'created_at' => '2026-09-18T14:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/'],
            'observation' => 'Repository evidence can drift after a LearningNote is published.',
            'evidence' => [[
                'type' => 'manual_verification',
                'summary' => 'A referenced source changed after publication.',
            ]],
            'hypothesis' => 'A typed read-only review can expose exact drift without mutating the note.',
            'validated_conclusion' => 'Evidence review must show recorded/current hashes and preserve review authority.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'learning_note.repository_evidence_review',
            'validation_case' => [
                'given' => 'An active LearningNote with hash-bound repository evidence.',
                'when' => 'One evidence source changes or disappears.',
                'then' => 'The owner reports the exact drift without rewriting the note.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        file_put_contents($this->projectRoot . '/src/Current.php', "<?php\n");
        file_put_contents($this->projectRoot . '/src/Drift.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    public function testReviewReportsCurrentChangedAndMissingEvidenceWithoutMutation(): void
    {
        $currentPath = $this->projectRoot . '/src/Current.php';
        $driftPath = $this->projectRoot . '/src/Drift.php';
        $currentSha = hash_file('sha256', $currentPath);
        $driftSha = hash_file('sha256', $driftPath);
        self::assertIsString($currentSha);
        self::assertIsString($driftSha);

        $service = new LearningNoteService();
        $published = $service->publish(
            $this->root,
            new LearningNoteDraft(
                sourceFindings: ['finding.2026-09-18.121001'],
                sourceProposals: [],
                tags: ['learning'],
                repositoryEvidence: [
                    new LearningNoteRepositoryEvidence('src/Current.php', $currentSha),
                    new LearningNoteRepositoryEvidence('src/Drift.php', $driftSha),
                ],
                content: new LearningNoteContent(
                    title: 'Review drifted LearningNote evidence',
                    context: 'A durable precedent points at current repository evidence.',
                    guidance: 'Review changed evidence before republishing or retiring the note.',
                    whyItWorks: 'The owner exposes exact byte drift without treating drift as semantic approval.',
                    whenToApply: 'When a LearningNote reports review_needed or source_missing.',
                    whenNotToApply: 'When the note has no hashable repository evidence.',
                    verification: 'Compare recorded and current hashes, then inspect the changed source semantically.',
                ),
            ),
            $this->projectRoot,
        );

        $notePath = $this->root . '/notes/active/' . $published->id . '.json';
        $beforeReview = file_get_contents($notePath);
        self::assertIsString($beforeReview);

        $current = $service->reviewEvidence($this->root, $published->id, $this->projectRoot);
        self::assertSame(LearningNoteEvidenceState::CURRENT, $current->evidenceState);
        self::assertCount(2, $current->repositoryEvidence);
        self::assertSame('src/Current.php', $current->repositoryEvidence[0]->sourceRef);
        self::assertSame($currentSha, $current->repositoryEvidence[0]->recordedSha256);
        self::assertSame($currentSha, $current->repositoryEvidence[0]->currentSha256);
        self::assertSame(LearningNoteEvidenceState::CURRENT, $current->repositoryEvidence[0]->state);

        file_put_contents($driftPath, "<?php\n// changed\n");
        $changedSha = hash_file('sha256', $driftPath);
        self::assertIsString($changedSha);
        self::assertNotSame($driftSha, $changedSha);

        $changed = $service->reviewEvidence($this->root, $published->id, $this->projectRoot);
        self::assertSame(LearningNoteEvidenceState::REVIEW_NEEDED, $changed->evidenceState);
        self::assertSame('src/Drift.php', $changed->repositoryEvidence[1]->sourceRef);
        self::assertSame($driftSha, $changed->repositoryEvidence[1]->recordedSha256);
        self::assertSame($changedSha, $changed->repositoryEvidence[1]->currentSha256);
        self::assertSame(LearningNoteEvidenceState::REVIEW_NEEDED, $changed->repositoryEvidence[1]->state);

        unlink($currentPath);

        $missing = $service->reviewEvidence($this->root, $published->id, $this->projectRoot);
        self::assertSame(LearningNoteEvidenceState::SOURCE_MISSING, $missing->evidenceState);
        self::assertSame('src/Current.php', $missing->repositoryEvidence[0]->sourceRef);
        self::assertNull($missing->repositoryEvidence[0]->currentSha256);
        self::assertSame(LearningNoteEvidenceState::SOURCE_MISSING, $missing->repositoryEvidence[0]->state);

        self::assertSame($beforeReview, file_get_contents($notePath));
    }
}
