<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\FindingToMemoryPromotionPolicy;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\ValidationCase;

final class FindingToMemoryPromotionPolicyTest extends TestCase
{
    public function testIgnoredPatternBearingEvidenceDoesNotResurrectMemoryCandidate(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-ignore-promotion-' . bin2hex(random_bytes(8));
        $creator = new FindingCreator();
        $validationCase = new ValidationCase(
            'A recurring pattern is already enforced by a deterministic constraint.',
            'Another real manifestation is captured as a Finding.',
            'The evidence remains attributable without recreating soft guidance.',
        );

        $first = $creator->createValidated(
            root: $root,
            taskId: 'PROJECT-112',
            session: 'session_PROJECT-112',
            createdBy: 'test',
            scope: ['src/Rule.php'],
            observation: 'First post-enforcement manifestation.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Observed first manifestation.']],
            hypothesis: 'The detector needed refinement.',
            validatedConclusion: 'The active detector was refined.',
            confidence: 'high',
            sensitivity: 'public',
            id: 'finding.2026-09-14.112001',
            classification: LearningClassification::IGNORE,
            patternKey: 'rule.already.enforced',
            validationCase: $validationCase,
        )->finding;
        $second = $creator->createValidated(
            root: $root,
            taskId: 'PROJECT-113',
            session: 'session_PROJECT-113',
            createdBy: 'test',
            scope: ['src/Rule.php'],
            observation: 'Second post-enforcement manifestation.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Observed second manifestation.']],
            hypothesis: 'The same enforced pattern appeared again.',
            validatedConclusion: 'No new soft guidance is needed.',
            confidence: 'high',
            sensitivity: 'public',
            id: 'finding.2026-09-14.112002',
            classification: LearningClassification::IGNORE,
            patternKey: 'rule.already.enforced',
            validationCase: $validationCase,
        )->finding;

        self::assertSame([], (new FindingToMemoryPromotionPolicy())->evaluate([
            $first->id => $first,
            $second->id => $second,
        ]));
    }
}
