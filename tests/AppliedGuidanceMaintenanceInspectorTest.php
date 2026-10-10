<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceMaintenanceInspector;
use voku\AgentLearning\ProposalTransitionManager;
use voku\AgentLearning\ValidationException;

/** @internal */
final class AppliedGuidanceMaintenanceInspectorTest extends TestCase
{
    private const string FIRST = 'proposal.2026-06-08.001';
    private const string SECOND = 'proposal.2026-06-08.002';
    private const string FIRST_RULE = 'Keep the packaged entrypoint callable.';
    private const string SECOND_RULE = 'Name the owner that enforces a rule.';

    private string $directory;
    private string $beforeRoot;
    private string $afterRoot;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maintenance-proof-' . bin2hex(random_bytes(8));
        $this->beforeRoot = $this->directory . '/before';
        $this->afterRoot = $this->directory . '/after';

        foreach ([$this->beforeRoot, $this->afterRoot] as $root) {
            mkdir($root . '/findings/validated', 0o775, true);
            mkdir($root . '/proposals/applied', 0o775, true);
            mkdir($root . '/docs', 0o775, true);
            copy(
                __DIR__ . '/fixtures/findings/finding.2026-06-08.001.json',
                $root . '/findings/validated/finding.2026-06-08.001.json',
            );
            file_put_contents($root . '/MEMORY.md', $this->memory('src/Dogfood/'));
            $this->writeApplied($root, self::FIRST, self::FIRST_RULE, 'MEMORY.md');
            $this->writeApplied($root, self::SECOND, self::SECOND_RULE, './MEMORY.md');
        }
        file_put_contents($this->afterRoot . '/MEMORY.md', $this->memory('tools/Dogfood/'));
        (new ProposalTransitionManager())->reanchorTarget(
            $this->afterRoot,
            'MEMORY.md',
            'maintainer',
            'Verified two factual source-path updates without changing the applied rules.',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testReadOnlyOwnerProofAdmitsTheRealShapeOfARuleTextPathRepair(): void
    {
        $beforeHash = (string) hash_file('sha256', $this->beforeRoot . '/MEMORY.md');
        $afterHash = (string) hash_file('sha256', $this->afterRoot . '/MEMORY.md');
        $beforeProposalHash = (string) hash_file('sha256', $this->beforeRoot . '/proposals/applied/' . self::FIRST . '.json');
        $afterProposalHash = (string) hash_file('sha256', $this->afterRoot . '/proposals/applied/' . self::FIRST . '.json');

        $proof = (new AppliedGuidanceMaintenanceInspector())->inspect(
            $this->beforeRoot,
            $this->afterRoot,
            'MEMORY.md',
        );

        self::assertSame('MEMORY.md', $proof->targetSourceRef);
        self::assertSame($beforeHash, $proof->beforeSha256);
        self::assertSame($afterHash, $proof->afterSha256);
        self::assertSame([self::FIRST, self::SECOND], array_column($proof->reanchors, 'proposal_id'));
        self::assertSame(['maintainer', 'maintainer'], array_column($proof->reanchors, 'reanchored_by'));
        self::assertSame($beforeProposalHash, hash_file('sha256', $this->beforeRoot . '/proposals/applied/' . self::FIRST . '.json'));
        self::assertSame($afterProposalHash, hash_file('sha256', $this->afterRoot . '/proposals/applied/' . self::FIRST . '.json'));
    }

    public function testMissingProofFailsValidationBeforeAReceiptIsIssued(): void
    {
        $path = $this->afterRoot . '/proposals/applied/' . self::SECOND . '.json';
        $this->modify($path, static function (array $data): array {
            $data['applied_validation']['target_content_hash'] = str_repeat('0', 64);

            return $data;
        });

        $this->expectException(ValidationException::class);
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'MEMORY.md');
    }

    public function testApprovalMutationIsNotProofMaintenance(): void
    {
        $path = $this->afterRoot . '/proposals/applied/' . self::FIRST . '.json';
        $this->modify($path, static function (array $data): array {
            $data['approved_by'] = 'different-reviewer';

            return $data;
        });

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('approval, guidance, or application evidence changed');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'MEMORY.md');
    }

    public function testUnattributedReanchorIsRejected(): void
    {
        $path = $this->afterRoot . '/proposals/applied/' . self::FIRST . '.json';
        $this->modify($path, static function (array $data): array {
            $data['applied_validation']['reanchor_reason'] = '';

            return $data;
        });

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing new reanchor provenance');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'MEMORY.md');
    }

    public function testUnrelatedProposalChangesAreRejected(): void
    {
        foreach ([$this->beforeRoot, $this->afterRoot] as $root) {
            file_put_contents($root . '/docs/other.md', self::FIRST_RULE);
            $this->writeApplied($root, 'proposal.2026-06-08.003', self::FIRST_RULE, 'docs/other.md');
        }
        $path = $this->afterRoot . '/proposals/applied/proposal.2026-06-08.003.json';
        $this->modify($path, static function (array $data): array {
            $data['reason'] = 'Unrelated edited proposal';

            return $data;
        });

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unrelated proposal changed');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'MEMORY.md');
    }

    public function testNewlyIntroducedProposalIsRejected(): void
    {
        file_put_contents($this->afterRoot . '/docs/other.md', self::FIRST_RULE);
        $this->writeApplied($this->afterRoot, 'proposal.2026-06-08.003', self::FIRST_RULE, 'docs/other.md');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal identities changed');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'MEMORY.md');
    }

    public function testMissingAppliedProposalForSelectedTargetIsRejected(): void
    {
        file_put_contents($this->beforeRoot . '/docs/other.md', 'old content');
        file_put_contents($this->afterRoot . '/docs/other.md', 'new content');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no applied guidance proofs');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, 'docs/other.md');
    }

    public function testDirectoryTraversalIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('target must be a repository-relative path');
        (new AppliedGuidanceMaintenanceInspector())->inspect($this->beforeRoot, $this->afterRoot, '../MEMORY.md');
    }

    private function memory(string $directory): string
    {
        return "# Memory\n\n"
            . self::FIRST_RULE . "\n"
            . self::SECOND_RULE . "\n"
            . 'All tested dogfood decisions live under ' . $directory . "\n";
    }

    private function writeApplied(string $root, string $id, string $rule, string $reference): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/proposals/' . self::FIRST . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $data['id'] = $id;
        $data['action'] = 'ADD';
        $data['target_type'] = 'memory';
        $data['target'] = $rule;
        $data['old'] = null;
        $data['new'] = $rule;
        $data['status'] = 'applied';
        $data['applied_by'] = 'maintainer';
        $data['applied_at'] = '2026-08-20T14:00:00+00:00';
        $data['commit'] = 'commit123';
        $data['applied_validation'] = [
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'commit' => 'commit123',
            'target_source_ref' => $reference,
            'target_content_hash' => (string) hash_file('sha256', $root . '/' . $reference),
            'summary' => 'Fixture application proof.',
        ];
        file_put_contents(
            $root . '/proposals/applied/' . $id . '.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function modify(string $path, callable $change): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($change($data), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
