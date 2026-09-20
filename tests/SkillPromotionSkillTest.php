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

    public function testSkillUsesExistingLearningPrepareAndProposalImportOwners(): void
    {
        $skill = (string) file_get_contents(
            __DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md',
        );

        self::assertStringContainsString('name: agent-skill-promotion', $skill);
        self::assertStringContainsString('vendor/bin/agent-learning prepare', $skill);
        self::assertStringContainsString('vendor/bin/agent-learning proposal-import', $skill);
        self::assertStringContainsString('Use the generated consolidation input as the Learning-owned evidence envelope.', $skill);
        self::assertStringContainsString('The owner re-parses the result', $skill);
        self::assertStringContainsString('SKILL_PROMOTION_CANDIDATE', $skill);
        self::assertStringContainsString('SKILL_PROMOTION_REDIRECT', $skill);
        self::assertStringContainsString('SKILL_PROMOTION_BLOCKED', $skill);
    }

    public function testSkillShapesGuidanceWithoutDuplicatingPromotionAuthority(): void
    {
        $skill = (string) file_get_contents(
            __DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md',
        );

        self::assertStringContainsString('Prefer the existing semantic owner', $skill);
        self::assertStringContainsString('Define a discoverable skill boundary', $skill);
        self::assertStringContainsString('Keep one coherent context boundary', $skill);
        self::assertStringContainsString('Use progressive disclosure', $skill);
        self::assertStringContainsString('CREATE_SKILL` requires `ADD`', $skill);
        self::assertStringContainsString('UPDATE_SKILL` requires `target_type=skill`', $skill);
    }

    public function testSkillDoesNotInventAutomaticPromotionOrDirectProposalMutation(): void
    {
        $skill = (string) file_get_contents(
            __DIR__ . '/../resources/skills/agent-skill-promotion/SKILL.md',
        );

        self::assertStringContainsString('must never:', $skill);
        self::assertStringContainsString('change a Finding classification silently', $skill);
        self::assertStringContainsString('approve, apply, reject, retire, or acknowledge a Proposal', $skill);
        self::assertStringContainsString('Do not edit `proposals/**` directly', $skill);
        self::assertStringContainsString('mutate the target skill as if a candidate were already approved', $skill);
        self::assertStringContainsString('Human/owner review remains the authority-bearing step', $skill);
    }
}
