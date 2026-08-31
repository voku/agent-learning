<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\LearningNoteCodec;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNotePublisher;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\LearningNoteStatusInspector;
use voku\AgentLearning\ValidationException;

final class LearningNoteTest extends TestCase
{
    public function testPublishesAndUpdatesOneActivePatternOwner(): void
    {
        $root = $this->root();
        $first = $this->writeFinding($root, 'finding.2026-08-31.aaa111');
        $publisher = new LearningNotePublisher();

        $created = $publisher->publish(
            $root,
            [$first],
            $this->content('First case'),
            tags: ['workflow'],
            id: 'learning-note.2026-08-31.abc123',
        );

        self::assertSame('learning-note.2026-08-31.abc123', $created->note->id);
        self::assertSame('workflow.learning-note', $created->note->patternKey);
        self::assertFileExists($created->path);

        $second = $this->writeFinding($root, 'finding.2026-08-31.bbb222');
        $updated = $publisher->publish($root, [$second], $this->content('Updated case'), tags: ['precedent']);

        self::assertSame($created->note->id, $updated->note->id);
        self::assertSame([$first, $second], $updated->note->sourceFindings);
        self::assertSame(['precedent', 'workflow'], $updated->note->tags);
        self::assertSame('Updated case', $updated->note->content->title);
        self::assertCount(1, (new LearningNoteRepository())->active($root));
    }

    public function testPreparationRejectsNonValidatedSource(): void
    {
        $root = $this->root();
        $candidate = $this->writeFinding(
            $root,
            'finding.2026-08-31.ccc333',
            FindingStatus::CANDIDATE,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be validated or consolidated');
        (new LearningNotePublisher())->publish($root, [$candidate], $this->content('Invalid'));
    }

    public function testPreparationRejectsDifferentPatternKeys(): void
    {
        $root = $this->root();
        $first = $this->writeFinding($root, 'finding.2026-08-31.ddd444');
        $second = $this->writeFinding(
            $root,
            'finding.2026-08-31.eee555',
            FindingStatus::VALIDATED,
            'workflow.other-pattern',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot combine different pattern_key values');
        (new \voku\AgentLearning\LearningNotePreparer())->prepare($root, [$first, $second]);
    }

    public function testRepositoryRejectsDuplicateActivePatternOwnership(): void
    {
        $root = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.abc123');
        $directory = $root . '/notes/active';
        self::assertTrue(mkdir($directory, 0777, true));
        $first = $this->rawNote('learning-note.2026-08-31.111aaa');
        $second = $this->rawNote('learning-note.2026-08-31.222bbb');
        self::assertNotFalse(file_put_contents($directory . '/' . $first['id'] . '.json', json_encode($first, JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($directory . '/' . $second['id'] . '.json', json_encode($second, JSON_THROW_ON_ERROR)));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('duplicate active LearningNote pattern_key');
        (new LearningNoteRepository())->loadAll($root);
    }

    public function testStatusReportsCurrentChangedAndMissingRepositoryEvidence(): void
    {
        $root = $this->root();
        $projectRoot = dirname(dirname($root));
        $source = $projectRoot . '/src/Owner.php';
        self::assertTrue(mkdir(dirname($source), 0777, true));
        self::assertNotFalse(file_put_contents($source, "<?php\nfinal class Owner {}\n"));
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);
        $finding = $this->writeFinding(
            $root,
            'finding.2026-08-31.fff666',
            FindingStatus::VALIDATED,
            'workflow.learning-note',
            ['source_ref' => 'src/Owner.php', 'content_sha256' => $hash],
        );
        $note = (new LearningNotePublisher())->publish($root, [$finding], $this->content('Source-bound'))->note;
        $inspector = new LearningNoteStatusInspector();

        self::assertSame(LearningNoteEvidenceState::CURRENT, $inspector->inspect($root, $note, $projectRoot)->state);
        self::assertNotFalse(file_put_contents($source, "<?php\nfinal class Owner { public function changed(): void {} }\n"));
        self::assertSame(LearningNoteEvidenceState::REVIEW_NEEDED, $inspector->inspect($root, $note, $projectRoot)->state);
        self::assertTrue(unlink($source));
        self::assertSame(LearningNoteEvidenceState::SOURCE_MISSING, $inspector->inspect($root, $note, $projectRoot)->state);
    }

    public function testRedactionRejectsSecretLikeDurableContentWithoutPartialNote(): void
    {
        $root = $this->root();
        $finding = $this->writeFinding($root, 'finding.2026-08-31.123abc');

        try {
            (new LearningNotePublisher())->publish(
                $root,
                [$finding],
                new LearningNoteContent('Unsafe', 'Context', 'token=super-secret'),
            );
            self::fail('Expected sensitive content to be rejected.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('sensitive-data match', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($root . '/notes/active');
    }

    public function testRetirementPreservesLineageAndRemovesActiveOwnership(): void
    {
        $root = $this->root();
        $finding = $this->writeFinding($root, 'finding.2026-08-31.456def');
        $publisher = new LearningNotePublisher();
        $created = $publisher->publish($root, [$finding], $this->content('Retire me'));

        $retired = $publisher->retire($root, $created->note->id, 'Superseded by reviewed guidance.');

        self::assertSame(LearningNoteStatus::RETIRED, $retired->note->status);
        self::assertSame([$finding], $retired->note->sourceFindings);
        self::assertSame('Superseded by reviewed guidance.', $retired->note->retiredReason);
        self::assertFileDoesNotExist($root . '/notes/active/' . $created->note->id . '.json');
        self::assertFileExists($retired->path);
    }

    public function testCodecCanonicalizesListOrderForStableDigest(): void
    {
        $codec = new LearningNoteCodec();
        $left = $this->rawNote('learning-note.2026-08-31.789abc');
        $left['scope'] = ['tests/', 'src/'];
        $left['tags'] = ['z', 'a'];
        $right = $left;
        $right['scope'] = ['src/', 'tests/'];
        $right['tags'] = ['a', 'z'];

        self::assertSame(
            $codec->digest($codec->decode($left)),
            $codec->digest($codec->decode($right)),
        );
    }

    private function root(): string
    {
        $base = sys_get_temp_dir() . '/agent-learning-note-' . bin2hex(random_bytes(8));
        $root = $base . '/.agent-loop/learning';
        self::assertTrue(mkdir($root . '/findings', 0777, true));

        return $root;
    }

    private function content(string $title): LearningNoteContent
    {
        return new LearningNoteContent(
            title: $title,
            context: 'A validated run exposed a bounded reusable case.',
            guidance: 'Use the owner boundary and preserve exact evidence lineage.',
            failedApproaches: 'Writing owner-private storage directly.',
            whenToApply: 'When the same bounded pattern is present.',
            whenNotToApply: 'When stronger current evidence contradicts the old case.',
            verification: 'Re-run the owner validation and current source checks.',
        );
    }

    /**
     * @param array<string, string> $repositoryEvidence
     */
    private function writeFinding(
        string $root,
        string $id,
        FindingStatus $status = FindingStatus::VALIDATED,
        string $patternKey = 'workflow.learning-note',
        array $repositoryEvidence = [],
    ): string {
        $directory = $root . '/findings/' . $status->value;
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        $validated = in_array($status, [FindingStatus::VALIDATED, FindingStatus::CONSOLIDATED], true);
        $evidence = [
            'type' => 'manual_verification',
            'summary' => 'Observed and reproduced during governed work.',
            ...$repositoryEvidence,
        ];
        $data = [
            'id' => $id,
            'task_id' => 'PROJECT-54',
            'session' => 'session_PROJECT-54',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'agent',
            'scope' => ['src/'],
            'tags' => ['learning'],
            'observation' => 'A solved case contained reusable bounded context.',
            'evidence' => [$evidence],
            'hypothesis' => 'A first-class LearningNote can preserve the case.',
            'validated_conclusion' => $validated ? 'The validated case should be retained as non-authoritative precedent.' : null,
            'confidence' => 'high',
            'validation_status' => $validated ? 'validated' : 'unverified',
            'status' => $status->value,
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => $patternKey,
            'validation_case' => [
                'given' => 'A later task matches the same bounded pattern.',
                'when' => 'Recall selects prior solved-case context.',
                'then' => 'The precedent is available without becoming active guidance.',
            ],
        ];
        $path = $directory . '/' . $id . '.json';
        self::assertNotFalse(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));

        return $id;
    }

    /** @return array<string, mixed> */
    private function rawNote(string $id): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $id,
            'pattern_key' => 'workflow.learning-note',
            'status' => 'active',
            'scope' => ['src/'],
            'tags' => ['learning'],
            'source_findings' => ['finding.2026-08-31.abc123'],
            'source_proposals' => [],
            'validation_case' => [
                'given' => 'A later task matches the same bounded pattern.',
                'when' => 'The prior case is selected.',
                'then' => 'It is shown as non-authoritative precedent.',
            ],
            'repository_evidence' => [],
            'content' => $this->content('Example')->toArray(),
            'created_at' => '2026-08-31T20:00:00+00:00',
            'updated_at' => '2026-08-31T20:00:00+00:00',
        ];
    }
}
