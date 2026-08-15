<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\FindingExporter;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;

final class FindingExporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-learning-finding-export-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/findings/validated', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testHttpfulDogfoodFindingExportsWithExistingRunLineage(): void
    {
        $targeted = $this->findingRecord(
            'finding.2026-08-16.001',
            'The workflow had no governed place for feedback about agent-loop itself.',
            'voku/agent-loop',
        );
        $this->writeFinding($targeted);
        $this->writeFinding($this->findingRecord(
            'finding.2026-08-16.002',
            'A map-specific observation belongs to another package.',
            'voku/agent-map',
        ));
        $this->writeFinding($this->findingRecord(
            'finding.2026-08-16.003',
            'A host-project finding has no external target.',
            null,
        ));

        (new RunLearningDecisionStore($this->root))->record(
            'run:HTTPFUL-1:dogfood',
            RunLearningDecisionStatus::FINDINGS_RECORDED,
            'dogfood-agent',
            'Host-side workflow friction was captured as governed findings.',
            ['finding.2026-08-16.001'],
        );

        /** @var array<string, mixed> $export */
        $export = json_decode(
            (new FindingExporter())->export($this->root, 'voku/agent-loop', 'voku/httpful'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('finding_export', $export['kind']);
        self::assertSame('voku/httpful', $export['source_repository']);
        self::assertSame('voku/agent-loop', $export['target_package']);
        self::assertSame(1, $export['finding_count']);
        self::assertSame($targeted, $export['findings'][0]['record']);
        self::assertSame(['run:HTTPFUL-1:dogfood'], $export['findings'][0]['run_ids']);
    }

    /** @return array<string, mixed> */
    private function findingRecord(string $id, string $observation, ?string $targetPackage): array
    {
        $record = [
            'id' => $id,
            'task_id' => 'HTTPFUL-1',
            'session' => 'session-httpful-1',
            'created_at' => '2026-08-16T00:30:00+02:00',
            'created_by' => 'dogfood-agent',
            'scope' => ['tools/agent-loop'],
            'observation' => $observation,
            'evidence' => [[
                'type' => 'runtime_observation',
                'summary' => 'Observed during the governed httpful#34 consumer run.',
            ]],
            'hypothesis' => 'The host-side observation identifies reusable tooling friction.',
            'validated_conclusion' => 'The observation is reproducible and should be reviewable by the owning package.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ];
        if ($targetPackage !== null) {
            $record['target_package'] = $targetPackage;
            $record['tested_ref'] = '0.16.3';
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function writeFinding(array $record): void
    {
        file_put_contents(
            $this->root . '/findings/validated/' . $record['id'] . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
