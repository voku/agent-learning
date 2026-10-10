<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\AppliedGuidanceMaintenanceInspector;
use voku\AgentLearning\ProposalTransitionManager;

/** @internal */
final class AppliedGuidanceMaintenanceInspectorTest extends TestCase
{
    private string $directory;
    private string $before;
    private string $after;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/learning-maintenance-' . bin2hex(random_bytes(8));
        $this->before = $this->directory . '/before';
        $this->after = $this->directory . '/after';
        foreach ([$this->before, $this->after] as $project) {
            $learning = $project . '/.agent-loop/learning';
            mkdir($learning . '/findings/validated', 0777, true);
            mkdir($learning . '/proposals/applied', 0777, true);
            mkdir($learning . '/history', 0777, true);
            copy(
                __DIR__ . '/fixtures/findings/finding.2026-06-08.001.json',
                $learning . '/findings/validated/finding.2026-06-08.001.json',
            );
            file_put_contents($project . '/MEMORY.md', $this->memory(false));
            $this->writeProposal($project, 'proposal.2026-06-08.001', 'Reviewed guidance A.');
            $this->writeProposal($project, 'proposal.2026-06-08.002', 'Reviewed guidance B.');
        }

        // The owner performs the real target-scoped reanchor transaction.
        file_put_contents($this->after . '/MEMORY.md', $this->memory(true));
        (new ProposalTransitionManager())->reanchorTarget(
            $this->learning($this->after),
            'MEMORY.md',
            'maintainer',
            'Four reviewed reference corrections after moving files.',
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testProjectsOnlyPhysicalProofMaintenance(): void
    {
        $evidence = (new AppliedGuidanceMaintenanceInspector())->inspect(
            $this->learning($this->before),
            $this->learning($this->after),
            'MEMORY.md',
        );

        self::assertSame(['proposal.2026-06-08.001', 'proposal.2026-06-08.002'], $evidence->proposalIds);
        self::assertSame(hash_file('sha256', $this->before . '/MEMORY.md'), $evidence->beforeSha256);
        self::assertSame(hash_file('sha256', $this->after . '/MEMORY.md'), $evidence->afterSha256);
        self::assertSame('maintainer', $evidence->reanchors['proposal.2026-06-08.001']['actor']);
    }

    public function testAChangedApprovalIsRejected(): void
    {
        $this->mutateProposal('proposal.2026-06-08.001', static function (array $record): array {
            $record['approved_by'] = 'someone-else';
            return $record;
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reviewed proposal or application evidence changed');
        $this->inspect();
    }

    public function testMissingReanchorReasonIsRejected(): void
    {
        $this->mutateProposal('proposal.2026-06-08.001', static function (array $record): array {
            $record['applied_validation']['reanchor_reason'] = '';
            return $record;
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing new reanchor provenance');
        $this->inspect();
    }

    public function testMissingTargetFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source does not resolve');
        $this->inspect('not-existing.md');
    }

    public function testWrongButExistingTargetDoesNotBecomeMaintenanceEvidence(): void
    {
        file_put_contents($this->before . '/WRONG.md', 'Unrelated before content.');
        file_put_contents($this->after . '/WRONG.md', 'Unrelated after content.');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No applied guidance proof names the target');
        $this->inspect('WRONG.md');
    }

    public function testTamperedPhysicalHashFailsOwnerValidation(): void
    {
        $this->mutateProposal('proposal.2026-06-08.001', static function (array $record): array {
            $record['applied_validation']['target_content_hash'] = str_repeat('0', 64);
            return $record;
        });
        $this->expectException(\voku\AgentLearning\ValidationException::class);
        $this->expectExceptionMessage('target_content_hash does not match target file');
        $this->inspect();
    }

    public function testChangedReviewedWordingCannotHideUnderAReanchor(): void
    {
        $this->mutateProposal('proposal.2026-06-08.001', static function (array $record): array {
            $record['new'] = 'Reviewed guidance B.';
            return $record;
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reviewed proposal or application evidence changed');
        $this->inspect();
    }

    public function testAChangedUnreviewedRowCannotBeDeclaredHumanApprovedByThisApi(): void
    {
        // Both approved proposal wordings still exist, so owner validation passes.
        // The human review of changed *other* prose belongs to the consumer, not here.
        file_put_contents($this->after . '/MEMORY.md', $this->memory(true) . "A wholly new policy.\n");
        (new ProposalTransitionManager())->reanchorTarget(
            $this->learning($this->after),
            'MEMORY.md',
            'maintainer',
            'Reanchored proof, not a semantic verdict.',
        );
        $evidence = $this->inspect();
        self::assertSame(2, count($evidence->proposalIds));
    }

    private function inspect(string $target = 'MEMORY.md'): \voku\AgentLearning\AppliedGuidanceMaintenanceEvidence
    {
        return (new AppliedGuidanceMaintenanceInspector())->inspect(
            $this->learning($this->before),
            $this->learning($this->after),
            $target,
        );
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    private function mutateProposal(string $id, \Closure $change): void
    {
        $path = $this->learning($this->after) . '/proposals/applied/' . $id . '.json';
        /** @var array<string, mixed> $record */
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($change($record), JSON_THROW_ON_ERROR));
    }

    private function learning(string $project): string
    {
        return $project . '/.agent-loop/learning';
    }

    private function memory(bool $updated): string
    {
        $state = $updated ? 'consolidated' : 'validated';
        $dogfood = $updated ? 'tools/Dogfood' : 'src/Dogfood';
        return "# MEMORY\nReviewed guidance A.\nReviewed guidance B.\n"
            . "| Credentials | Keep access read-only. | findings/{$state}/finding.007.json |\n"
            . "| Learning | Record provenance. | findings/{$state}/finding.006.json |\n"
            . "| Truth | Follow code. | findings/{$state}/finding.014.json |\n"
            . "| Dogfood | Code under {$dogfood}/ has tests. | {$dogfood}/SelfShapeEvidence.php |\n";
    }

    private function writeProposal(string $project, string $id, string $wording): void
    {
        /** @var array<string, mixed> $record */
        $record = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $record['id'] = $id;
        $record['action'] = 'ADD';
        $record['target_type'] = 'memory';
        $record['target'] = $id;
        $record['old'] = null;
        $record['new'] = $wording;
        $record['status'] = 'applied';
        $record['applied_by'] = 'maintainer';
        $record['applied_at'] = '2026-09-10T12:00:00+00:00';
        $record['commit'] = 'fixture-commit';
        $record['applied_validation'] = [
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'commit' => 'fixture-commit',
            'target_source_ref' => 'MEMORY.md',
            'target_content_hash' => hash_file('sha256', $project . '/MEMORY.md'),
            'summary' => 'Fixture only.',
        ];
        $path = $this->learning($project) . '/proposals/applied/' . $id . '.json';
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    }
}
