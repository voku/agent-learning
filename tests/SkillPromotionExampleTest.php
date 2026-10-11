<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Action;
use voku\AgentLearning\LearningClassification;

/** Regression for the real enum-example review in voku/agent-ui#104. */
final class SkillPromotionExampleTest extends TestCase
{
    public function testConsolidationJsonExampleUsesSupportedOwnerEnums(): void
    {
        $skill = file_get_contents(__DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md');
        self::assertIsString($skill);

        $matches = [];
        self::assertSame(1, preg_match('/```json\s*(\{.*?\})\s*```/s', $skill, $matches));
        $json = $matches[1] ?? null;
        self::assertIsString($json);
        $example = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($example);
        self::assertIsString($example['action'] ?? null);
        self::assertIsString($example['learning_decision'] ?? null);

        self::assertSame(Action::ADD, Action::tryFrom($example['action']));
        self::assertSame(LearningClassification::CREATE_SKILL, LearningClassification::tryFrom($example['learning_decision']));
        // Other sample fields are placeholders; this checks the enum boundary,
        // not a complete proposal-import acceptance run.
    }
}
