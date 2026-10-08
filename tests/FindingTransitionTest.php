<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\FindingTransitionManager;
use voku\AgentLearning\ValidationException;

final class FindingTransitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/finding-transition-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/candidate', 0777, true);
        mkdir($this->root . '/findings/validated', 0777, true);

        // create candidate finding
        $finding = json_decode((string)file_get_contents(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json'), true);
        $finding['status'] = 'candidate';
        $finding['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTransitionsFindingSuccessfully(): void
    {
        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars');

        self::assertFileDoesNotExist($this->root . '/findings/candidate/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/validated/finding.2026-06-08.001.json');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/validated/finding.2026-06-08.001.json'), true);
        self::assertSame('validated', $updated['status']);
        self::assertSame('lars', $updated['validated_by']);
        self::assertArrayHasKey('validated_at', $updated);
    }

    public function testReasonIsRecordedWithWhoAndWhenOnTheFinding(): void
    {
        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars');
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::ARCHIVED, 'lars', null, '  Resolved in code by the batch lookup.  ');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/archived/finding.2026-06-08.001.json'), true);
        self::assertSame('archived', $updated['status']);
        self::assertSame('Resolved in code by the batch lookup.', $updated['status_reason']);
        self::assertSame('lars', $updated['status_changed_by']);
        self::assertArrayHasKey('status_changed_at', $updated);
    }

    public function testNoReasonLeavesNoStatusReasonFields(): void
    {
        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars', null, '   ');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/validated/finding.2026-06-08.001.json'), true);
        self::assertArrayNotHasKey('status_reason', $updated);
        self::assertArrayNotHasKey('status_changed_by', $updated);
        self::assertArrayNotHasKey('status_changed_at', $updated);
    }

    public function testCliRecordsTheReasonAndNotesAnArchiveWithoutOne(): void
    {
        (new FindingTransitionManager())->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars');
        $base = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/agent-learning') . ' finding-transition finding.2026-06-08.001 archived --by lars --root ' . escapeshellarg($this->root);

        // Out of process: the command writes to STDOUT and STDERR, which an output buffer cannot observe.
        exec($base . ' 2>&1', $withoutReason, $withoutExit);
        self::assertSame(0, $withoutExit);
        self::assertStringContainsString('archived without --reason', implode("\n", $withoutReason));
        $archived = json_decode((string)file_get_contents($this->root . '/findings/archived/finding.2026-06-08.001.json'), true);
        self::assertArrayNotHasKey('status_reason', $archived);
    }

    public function testCliStoresTheGivenReasonWithoutTheNote(): void
    {
        (new FindingTransitionManager())->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'lars');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/agent-learning') . ' finding-transition finding.2026-06-08.001 archived --by lars --reason ' . escapeshellarg('Contradicted by 12 migrations.') . ' --root ' . escapeshellarg($this->root) . ' 2>&1';

        exec($command, $output, $exit);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('without --reason', implode("\n", $output));
        $archived = json_decode((string)file_get_contents($this->root . '/findings/archived/finding.2026-06-08.001.json'), true);
        self::assertSame('Contradicted by 12 migrations.', $archived['status_reason']);
    }

    public function testValidatingCandidateWithoutStoredConclusionRequiresReviewerConclusion(): void
    {
        $path = $this->root . '/findings/candidate/finding.2026-06-08.001.json';
        $finding = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $finding['validated_conclusion'] = null;
        file_put_contents($path, json_encode($finding, JSON_THROW_ON_ERROR));

        $manager = new FindingTransitionManager();
        try {
            $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::VALIDATED, 'reviewer');
            self::fail('Expected validation without an explicit conclusion to fail.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('requires an explicit conclusion', $exception->getMessage());
        }
        self::assertFileExists($path);

        $manager->transition(
            $this->root,
            'finding.2026-06-08.001',
            FindingStatus::VALIDATED,
            'reviewer',
            'The reported workflow gap is reproducible and should proceed to learning triage.',
        );

        $updated = json_decode((string)file_get_contents($this->root . '/findings/validated/finding.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('The reported workflow gap is reproducible and should proceed to learning triage.', $updated['validated_conclusion']);
        self::assertSame('reviewer', $updated['validated_by']);
    }

    public function testForbiddenTransitionThrowsAndRollsBack(): void
    {
        // transition candidate to archived (forbidden)
        $manager = new FindingTransitionManager();
        $this->expectException(ValidationException::class);
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::ARCHIVED, 'lars');

        // File remains in candidate
        self::assertFileExists($this->root . '/findings/candidate/finding.2026-06-08.001.json');
    }

    public function testTransitionToRejected(): void
    {
        // Set correct initial validation_status for candidate (unverified)
        $finding = json_decode((string)file_get_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json'), true);
        $finding['validation_status'] = 'unverified';
        file_put_contents($this->root . '/findings/candidate/finding.2026-06-08.001.json', json_encode($finding));

        $manager = new FindingTransitionManager();
        $manager->transition($this->root, 'finding.2026-06-08.001', FindingStatus::REJECTED, 'lars');

        self::assertFileDoesNotExist($this->root . '/findings/candidate/finding.2026-06-08.001.json');
        self::assertFileExists($this->root . '/findings/rejected/finding.2026-06-08.001.json');

        $updated = json_decode((string)file_get_contents($this->root . '/findings/rejected/finding.2026-06-08.001.json'), true);
        self::assertSame('rejected', $updated['status']);
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
