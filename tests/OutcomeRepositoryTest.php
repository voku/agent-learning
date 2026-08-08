<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\OutcomeRepository;
use voku\AgentLearning\ValidationException;

final class OutcomeRepositoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/outcome-repository-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/history', 0777, true);
        mkdir($this->root . '/skills', 0777, true);

        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');

        $target = $this->root . '/skills/agent-learning-cli.md';
        file_put_contents($target, 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');

        $proposal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        $proposal['status'] = 'applied';
        $proposal['approved_by'] = 'maintainer';
        $proposal['approved_at'] = '2026-06-08T13:00:00+00:00';
        $proposal['applied_validation'] = [
            'target_source_ref' => 'skills/agent-learning-cli.md',
            'target_content_hash' => hash_file('sha256', $target),
        ];
        file_put_contents($this->root . '/proposals/applied/proposal.2026-06-08.001.json', json_encode($proposal));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRecordsOutcomeSuccessfully(): void
    {
        $repo = new OutcomeRepository();
        $record = [
            'id' => 'outcome.2026-06-20.001',
            'task_id' => 'PROJECT-204',
            'applied_proposals' => ['proposal.2026-06-08.001'],
            'guidance_used' => ['skill.auth-context'],
            'result' => 'successful',
            'validation' => [
                'tests_passed' => true,
            ],
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];

        $repo->record($this->root, $record);

        $outcomes = $repo->loadAll($this->root);
        self::assertCount(1, $outcomes);
        self::assertSame('outcome.2026-06-20.001', $outcomes[0]['id']);
    }

    public function testRecordsConstraintOutcomeSuccessfully(): void
    {
        $repo = new OutcomeRepository();
        $record = [
            'id' => 'outcome.2026-06-20.002',
            'task_id' => 'PROJECT-205',
            'applied_proposals' => [],
            'guidance_used' => ['constraint.project.inline-template.render-data'],
            'result' => 'violation_detected',
            'recorded_by' => 'lars',
            'recorded_at' => '2026-06-20T12:00:00+00:00',
        ];

        $repo->record($this->root, $record);

        $outcomes = $repo->loadAll($this->root);
        self::assertSame('violation_detected', $outcomes[0]['result']);
    }

    public function testThrowsOnUnknownProposalReference(): void
    {
        $repo = new OutcomeRepository();
        $record = [
            'id' => 'outcome.2026-06-20.001',
            'task_id' => 'PROJECT-204',
            'applied_proposals' => ['unknown-proposal-id'],
            'guidance_used' => [],
            'result' => 'successful',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('outcome references unknown proposal');
        $repo->record($this->root, $record);
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
