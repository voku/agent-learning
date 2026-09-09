<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\LearningNotePromotionReadiness;
use voku\AgentLearning\LearningNoteService;

final class LearningNotePromotionReadinessTest extends TestCase
{
    public function testAFullyTriagedFindingIsPromotableAndCarriesItsPatternKey(): void
    {
        [$root] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.001', 'pattern.owner.boundary');

        $readiness = (new LearningNoteService())->promotionReadiness($root, 'finding.2026-08-31.001');

        self::assertTrue($readiness->promotable);
        self::assertSame([], $readiness->blockers);
        self::assertSame('pattern.owner.boundary', $readiness->patternKey);
    }

    public function testAnUntriagedFindingReportsEveryMissingInputAtOnce(): void
    {
        // The point of a read-only answer: a Finding needing three fields is a
        // different amount of work from one needing a single classification,
        // and prepare() can only ever name the first.
        [$root] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.002', 'unused', classified: false);

        $readiness = (new LearningNoteService())->promotionReadiness($root, 'finding.2026-08-31.002');

        self::assertFalse($readiness->promotable);
        self::assertSame([
            LearningNotePromotionReadiness::BLOCKER_CLASSIFICATION,
            LearningNotePromotionReadiness::BLOCKER_PATTERN_KEY,
            LearningNotePromotionReadiness::BLOCKER_VALIDATION_CASE,
        ], $readiness->blockers);
        self::assertNull($readiness->patternKey);
    }

    public function testAnAbsentFindingIsReportedRatherThanThrown(): void
    {
        [$root] = $this->root();

        $readiness = (new LearningNoteService())->promotionReadiness($root, 'finding.2026-08-31.404');

        self::assertFalse($readiness->promotable);
        self::assertSame([LearningNotePromotionReadiness::BLOCKER_MISSING], $readiness->blockers);
        self::assertSame('finding.2026-08-31.404', $readiness->findingId);
    }

    public function testCandidateReadinessCoversEveryPromotableStatusAndOmitsClosedFindings(): void
    {
        [$root] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.010', 'pattern.a');
        $this->writeFinding($root, 'finding.2026-08-31.011', 'unused', classified: false);
        $this->writeFinding($root, 'finding.2026-08-31.012', 'pattern.c', status: 'consolidated');
        // Rejected findings are not waiting for triage, so listing them as
        // blocked would overstate how much work is outstanding.
        $this->writeFinding($root, 'finding.2026-08-31.013', 'pattern.d', status: 'rejected');

        $readiness = (new LearningNoteService())->candidatePromotionReadiness($root);

        self::assertSame([
            'finding.2026-08-31.010',
            'finding.2026-08-31.011',
            'finding.2026-08-31.012',
        ], array_map(
            static fn (LearningNotePromotionReadiness $entry): string => $entry->findingId,
            $readiness,
        ));
        self::assertSame([true, false, true], array_map(
            static fn (LearningNotePromotionReadiness $entry): bool => $entry->promotable,
            $readiness,
        ));
    }

    public function testReadinessAgreesWithWhatPrepareActuallyDoes(): void
    {
        // The two must not drift: whatever readiness calls promotable, prepare
        // must accept, and whatever it blocks, prepare must refuse.
        [$root, $projectRoot] = $this->root();
        $this->writeFinding($root, 'finding.2026-08-31.020', 'pattern.ok');
        $this->writeFinding($root, 'finding.2026-08-31.021', 'unused', classified: false);

        $service = new LearningNoteService();
        foreach (['finding.2026-08-31.020', 'finding.2026-08-31.021'] as $findingId) {
            $promotable = $service->promotionReadiness($root, $findingId)->promotable;
            $accepted = true;
            try {
                $service->prepare($root, [$findingId], $projectRoot);
            } catch (\Throwable) {
                $accepted = false;
            }
            self::assertSame($promotable, $accepted, $findingId);
        }
    }

    /** @return array{0: string, 1: string} */
    private function root(): array
    {
        $base = sys_get_temp_dir() . '/agent-learning-promotion-' . bin2hex(random_bytes(6));
        $root = $base . '/learning';
        $projectRoot = $base . '/project';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/findings/consolidated', 0777, true);
        mkdir($root . '/findings/rejected', 0777, true);
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

    private function writeFinding(
        string $root,
        string $id,
        string $patternKey,
        bool $classified = true,
        string $status = 'validated',
    ): void {
        $record = [
            'id' => $id,
            'task_id' => 'TEST-87',
            'session' => 'session_TEST-87',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Example.php'],
            'observation' => 'A consumer reconstructed owner behavior.',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in the test fixture.']],
            'hypothesis' => 'The missing owner boundary causes drift.',
            'validated_conclusion' => 'The owner boundary must remain explicit.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => $status,
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
            $root . '/findings/' . $status . '/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
