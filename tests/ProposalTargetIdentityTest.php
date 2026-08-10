<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalTransitionManager;

final class ProposalTargetIdentityTest extends TestCase
{
    public function testApplyNeverHashesSemanticTargetAsFilesystemPath(): void
    {
        $root = sys_get_temp_dir() . '/proposal-target-identity-' . bin2hex(random_bytes(8));
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($root . '/proposals/approved', 0777, true);
        mkdir($root . '/proposals/applied', 0777, true);
        mkdir($root . '/history', 0777, true);
        mkdir($root . '/skills', 0777, true);

        try {
            copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $root . '/findings/validated/finding.2026-06-08.001.json');
            $proposal = json_decode((string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($proposal);
            file_put_contents($root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposal, JSON_THROW_ON_ERROR));
            file_put_contents($root . '/history/decisions.jsonl', json_encode([
                'id' => 'decision.2026-06-08.001',
                'proposal_id' => 'proposal.2026-06-08.001',
                'status' => 'approved',
                'approved_by' => 'maintainer',
                'approved_at' => '2026-06-08T13:00:00+00:00',
            ], JSON_THROW_ON_ERROR) . "\n");

            // This file deliberately shares the semantic target id. It is NOT the
            // physical canonical target proved by applied_validation.
            file_put_contents($root . '/agent-learning-cli', 'unrelated semantic-id collision');

            $canonical = $root . '/skills/agent-learning-cli.md';
            file_put_contents($canonical, 'Call the packaged Composer bin entrypoint and keep consuming-project scripts as wrappers.');
            $validation = $root . '/validation.json';
            file_put_contents($validation, json_encode([
                'target_source_ref' => 'skills/agent-learning-cli.md',
                'target_content_hash' => hash_file('sha256', $canonical),
            ], JSON_THROW_ON_ERROR));

            (new ProposalTransitionManager())->apply($root, 'proposal.2026-06-08.001', 'lars', 'commit123', $validation);

            $applied = json_decode((string) file_get_contents($root . '/proposals/applied/proposal.2026-06-08.001.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($applied);
            self::assertArrayNotHasKey('target_content_hash', $applied);
            self::assertSame(hash_file('sha256', $canonical), $applied['applied_validation']['target_content_hash'] ?? null);

            $decisions = file($root . '/history/decisions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($decisions);
            $last = json_decode($decisions[count($decisions) - 1], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($last);
            self::assertArrayNotHasKey('target_content_hash', $last);
            self::assertSame(hash_file('sha256', $canonical), $last['validation']['target_content_hash'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
