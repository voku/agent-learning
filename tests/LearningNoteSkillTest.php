<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\PackageResources;

final class LearningNoteSkillTest extends TestCase
{
    public function testPackageResourcesResolvesShippedSkillsRoot(): void
    {
        self::assertSame('resources/skills', PackageResources::SKILLS);
        self::assertDirectoryExists(PackageResources::skillsRoot());
        self::assertFileExists(PackageResources::skillsRoot() . '/agent-learning-note/SKILL.md');
        self::assertFileExists(PackageResources::skillsRoot() . '/agent-learning-consumer/SKILL.md');
    }

    public function testPackagedSkillUsesTypedOwnerBoundariesAndPreservesAuthority(): void
    {
        $path = __DIR__ . '/../resources/skills/agent-learning-note/SKILL.md';
        self::assertFileExists($path);
        $skill = (string) file_get_contents($path);

        self::assertStringContainsString('name: agent-learning-note', $skill);
        self::assertStringContainsString('agent-learning-note prepare', $skill);
        self::assertStringContainsString('agent-learning-note publish', $skill);
        self::assertStringContainsString('one stable `pattern_key`', $skill);
        self::assertStringContainsString('LearningNote preserves useful prior experience', $skill);
        self::assertStringContainsString('not active guidance', $skill);
        self::assertStringContainsString('Verified current fact', $skill);
        self::assertStringContainsString('Validated historical finding', $skill);
        self::assertStringContainsString('Synthesis', $skill);
        self::assertStringContainsString('Unknown / changed', $skill);
        self::assertStringContainsString('LEARNING_NOTE_COMPLETE', $skill);
        self::assertStringContainsString('LEARNING_NOTE_SKIPPED', $skill);
        self::assertStringContainsString('LEARNING_NOTE_BLOCKED', $skill);
        self::assertStringContainsString('Do not perform whole-repository semantic similarity search', $skill);
        self::assertStringContainsString('must never:', $skill);
        self::assertStringContainsString('approve or apply a Proposal', $skill);
        self::assertStringContainsString('create or activate a Constraint automatically', $skill);
        self::assertStringContainsString('rewrite `MEMORY.md`', $skill);
        self::assertStringContainsString('Do not persist raw chat/session transcripts', $skill);
    }

    public function testSkillNeverInstructsDirectLearningNoteStorageMutation(): void
    {
        $skill = (string) file_get_contents(__DIR__ . '/../resources/skills/agent-learning-note/SKILL.md');

        self::assertStringNotContainsString('mkdir notes/', $skill);
        self::assertStringNotContainsString('file_put_contents', $skill);
        self::assertStringNotContainsString('cat > notes/', $skill);
        self::assertStringContainsString('Never infer source Findings from chat history or scan `notes/**` directly.', $skill);
        self::assertStringContainsString('do not edit `notes/active/*.json` or `notes/retired/*.json` directly', $skill);
    }

    public function testConsumerSkillRoutesAddLearningNoteToTheDedicatedSkill(): void
    {
        $consumer = (string) file_get_contents(__DIR__ . '/../resources/skills/agent-learning-consumer/SKILL.md');

        self::assertStringContainsString('package-owned `agent-learning-note` skill', $consumer);
        self::assertStringContainsString('agent-learning-note prepare', $consumer);
        self::assertStringContainsString('agent-learning-note publish', $consumer);
        self::assertStringContainsString('never write `notes/**` directly', $consumer);
        self::assertStringContainsString('never required proof that the software task itself is complete', $consumer);
    }
}
