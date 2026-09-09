<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNote;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteProjection;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\ValidationCase;

final class LearningLineageBoundedPrecedentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-bounded-precedent-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/project', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTopUpProjectsOnlyBoundedCandidatesAndReportsTruncation(): void
    {
        $evidencePath = $this->root . '/evidence.php';
        self::assertNotFalse(file_put_contents($evidencePath, "<?php\nreturn true;\n"));
        $evidenceHash = hash_file('sha256', $evidencePath);
        self::assertIsString($evidenceHash);

        $repository = new LearningNoteRepository();
        $repository->publish($this->root, $this->note('learning-note.2026-09-09.aaaaaa', 'scale.first'));
        $repository->publish($this->root, $this->note('learning-note.2026-09-09.bbbbbb', 'scale.second'));
        $repository->publish(
            $this->root,
            $this->note(
                'learning-note.2026-09-09.cccccc',
                'scale.third',
                [new LearningNoteRepositoryEvidence('evidence.php', $evidenceHash)],
            ),
        );

        $service = new LearningLineageService();
        $service->rebuild($this->root, $this->root);

        $missingProjectRoot = $this->root . '/missing-project-root';
        self::assertDirectoryDoesNotExist($missingProjectRoot);

        $limited = $service->precedentsForTask(
            $this->root,
            'NO-LINEAGE-84',
            projectRoot: $missingProjectRoot,
            maximumRelatedIdentities: 2,
        );

        self::assertSame(
            ['learning-note.2026-09-09.aaaaaa', 'learning-note.2026-09-09.bbbbbb'],
            array_map(static fn (LearningNoteProjection $precedent): string => $precedent->id, $limited->precedents),
        );
        self::assertTrue($limited->precedentsTruncated);
        self::assertFalse($limited->lineage->truncated);
        self::assertTrue($limited->toArray()['precedents_truncated']);

        $complete = $service->precedentsForTask(
            $this->root,
            'NO-LINEAGE-84',
            projectRoot: $this->root,
            maximumRelatedIdentities: 3,
        );
        self::assertSame(
            [
                'learning-note.2026-09-09.aaaaaa',
                'learning-note.2026-09-09.bbbbbb',
                'learning-note.2026-09-09.cccccc',
            ],
            array_map(static fn (LearningNoteProjection $precedent): string => $precedent->id, $complete->precedents),
        );
        self::assertFalse($complete->precedentsTruncated);
    }

    /** @param list<LearningNoteRepositoryEvidence> $repositoryEvidence */
    private function note(string $id, string $patternKey, array $repositoryEvidence = []): LearningNote
    {
        return new LearningNote(
            id: $id,
            patternKey: $patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: ['scale'],
            sourceFindings: ['finding.2026-06-08.001'],
            sourceProposals: [],
            validationCase: new ValidationCase('Given.', 'When.', 'Then.'),
            repositoryEvidence: $repositoryEvidence,
            content: new LearningNoteContent(
                title: 'Bound precedent lookup',
                context: 'Large Learning roots need bounded owner work.',
                guidance: 'Project only selected precedent candidates.',
                whyItWorks: 'The result limit bounds projection work as well as output size.',
                whenToApply: 'When topping up task precedents from active LearningNotes.',
                whenNotToApply: 'When rebuilding the complete derived lineage graph.',
                verification: 'Query with more active notes than the configured precedent limit.',
            ),
            createdAt: '2026-09-09T00:00:00+00:00',
            updatedAt: '2026-09-09T00:00:00+00:00',
        );
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            self::fail('Unable to create test directory: ' . $target);
        }

        $entries = scandir($source);
        if ($entries === false) {
            self::fail('Unable to scan fixture directory: ' . $source);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $targetPath = $target . '/' . $entry;
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            self::assertTrue(copy($sourcePath, $targetPath), 'Unable to copy fixture: ' . $sourcePath);
        }
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($root);
    }
}
