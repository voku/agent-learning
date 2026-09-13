<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\PackageResources;

/** @internal */
final class PackageResourcesTest extends TestCase
{
    public function testSkillsRootExists(): void
    {
        self::assertDirectoryExists(PackageResources::skillsRoot());
    }

    public function testConsumerSkillsExistAndHaveSkillMd(): void
    {
        $skills = PackageResources::consumerSkills();
        self::assertNotEmpty($skills);
        self::assertArrayHasKey('agent-learning-consumer', $skills);
        self::assertArrayHasKey('agent-learning-note', $skills);
        self::assertArrayHasKey('agent-hard-constraint-author', $skills);
        self::assertArrayHasKey('agent-learning-ctx-evidence', $skills);
        self::assertArrayNotHasKey('agent-learning-maintainer', $skills);

        foreach ($skills as $id => $path) {
            self::assertDirectoryExists($path, "Skill directory for {$id} must exist.");
            self::assertFileExists($path . '/SKILL.md', "SKILL.md for {$id} must exist.");
        }
    }

    public function testMaintainerSkillsExistAndHaveSkillMd(): void
    {
        $skills = PackageResources::maintainerSkills();
        self::assertArrayHasKey('agent-learning-maintainer', $skills);
        self::assertArrayNotHasKey('agent-learning-consumer', $skills);

        foreach ($skills as $id => $path) {
            self::assertDirectoryExists($path, "Skill directory for {$id} must exist.");
            self::assertFileExists($path . '/SKILL.md', "SKILL.md for {$id} must exist.");
        }
    }

    public function testConsumerInstructionFragmentExists(): void
    {
        $fragment = PackageResources::consumerInstructionFragment();
        self::assertNotNull($fragment);
        self::assertFileExists($fragment);
        $content = file_get_contents($fragment);
        self::assertIsString($content);
        self::assertStringContainsString('Agent Learning', $content);
    }
}
