<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ProposalImporter;
use voku\AgentLearning\ValidationException;

final class ProposalImportIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proposal-import-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/findings/validated', 0777, true);
        mkdir($this->root . '/proposals/candidate', 0777, true);
        mkdir($this->root . '/history', 0777, true);

        // Copy a validated finding fixture
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $this->root . '/findings/validated/finding.2026-06-08.001.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testImportsValidResultAsCandidateProposal(): void
    {
        $resultData = [
            'action' => 'ADD',
            'source_findings' => ['finding.2026-06-08.001'],
            'reason' => 'Repeated auth failures require better rules.',
            'target_type' => 'skill',
            'target' => 'skill.auth',
            'scope' => ['src/'],
            'new' => 'Do not allow bypasses.',
            'boundary' => 'boundary info',
            'validation' => ['test auth'],
        ];

        $jsonFile = $this->root . '/result.json';
        file_put_contents($jsonFile, json_encode($resultData));

        $importer = new ProposalImporter();
        $proposalId = $importer->import($this->root, $jsonFile);

        self::assertStringStartsWith('proposal.', $proposalId);
        self::assertFileExists($this->root . '/proposals/candidate/' . $proposalId . '.json');
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
