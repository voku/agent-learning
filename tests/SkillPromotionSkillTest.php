<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\PackageResources;

/** @internal */
final class SkillPromotionSkillTest extends TestCase
{
    public function testPackageShipsLearningOwnedSkillPromotionAuthor(): void
    {
        $skills = PackageResources::consumerSkills();

        self::assertArrayHasKey('agent-skill-promotion', $skills);
        self::assertFileExists($skills['agent-skill-promotion'] . '/SKILL.md');
    }

    public function testSkillPreservesPromotionAndHumanAuthorityBoundaries(): void
    {
        $skill = (string) file_get_contents(
            __DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md',
        );

        self::assertStringContainsString('name: agent-skill-promotion', $skill);
        self::assertStringContainsString('UPDATE_SKILL', $skill);
        self::assertStringContainsString('CREATE_SKILL', $skill);
        self::assertStringContainsString('Prefer the existing semantic owner', $skill);
        self::assertStringContainsString('Define the discovery contract', $skill);
        self::assertStringContainsString('Keep one coherent context boundary', $skill);
        self::assertStringContainsString('Use progressive disclosure', $skill);
        self::assertStringContainsString('Do not write the candidate into Learning-private proposal storage.', $skill);
        self::assertStringContainsString('A candidate means "ready for proposal review", not approved guidance.', $skill);
    }

    public function testSkillDoesNotInventAutomaticPromotionOrDirectProposalMutation(): void
    {
        $skill = (string) file_get_contents(
            __DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md',
        );

        self::assertStringContainsString('must never:', $skill);
        self::assertStringContainsString('change a Finding classification silently', $skill);
        self::assertStringContainsString('approve, apply, retire, or acknowledge a Proposal', $skill);
        self::assertStringContainsString('edit `proposals/**` directly', $skill);
        self::assertStringContainsString('mutate the target skill as if a candidate were already approved', $skill);
    }
}
