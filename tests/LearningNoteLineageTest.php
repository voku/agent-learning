<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationException;

final class LearningNoteLineageTest extends TestCase
{
    public function testReadProjectionRejectsMissingSourceFinding(): void
    {
        $base = sys_get_temp_dir() . '/learning-note-lineage-' . bin2hex(random_bytes(6));
        $root = $base . '/learning';
        $projectRoot = $base . '/project';
        mkdir($root . '/findings/validated', 0777, true);
        mkdir($projectRoot, 0777, true);
        file_put_contents($root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../project',
            'constraint_generation_dir' => 'constraint-generation',
            'active_constraints_dir' => 'constraints/active',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $findingPath = $root . '/findings/validated/finding.2026-08-31.001.json';
        file_put_contents($findingPath, json_encode([
            'id' => 'finding.2026-08-31.001',
            'task_id' => 'TEST-54',
            'session' => 'session_TEST-54',
            'created_at' => '2026-08-31T20:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/'],
            'observation' => 'A durable note has source lineage.',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in fixture.']],
            'hypothesis' => 'Missing lineage must be visible.',
            'validated_conclusion' => 'Missing lineage is durable-state corruption.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'learning.lineage_integrity',
            'validation_case' => [
                'given' => 'A persisted LearningNote.',
                'when' => 'Its source Finding disappears.',
                'then' => 'The owner read fails explicitly.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $service = new LearningNoteService();
        $service->publish($root, new LearningNoteDraft(
            sourceFindings: ['finding.2026-08-31.001'],
            sourceProposals: [],
            tags: [],
            repositoryEvidence: [],
            content: new LearningNoteContent(
                title: 'Lineage integrity',
                context: 'A note references durable source evidence.',
                guidance: 'Keep source lineage resolvable.',
                whyItWorks: 'A consumer can audit why the note exists.',
                whenToApply: 'When durable precedent is read.',
                whenNotToApply: 'Never treat missing lineage as clean absence.',
                verification: 'Remove the source Finding and read projections.',
            ),
        ), $projectRoot);
        unlink($findingPath);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('LearningNote source Finding is missing');
        $service->activeProjections($root, $projectRoot);
    }
}
