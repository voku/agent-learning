<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\CliOverrides;
use voku\AgentLearning\LearningProjectPaths;
use voku\AgentLearning\LearningRootResolver;
use voku\AgentLearning\PathResolver;
use voku\AgentLearning\ValidationException;

final class LearningRootResolverTest extends TestCase
{
    public function testDiscoversAllLegacyProjectRootLayouts(): void
    {
        foreach (['infra/doc/agent-learning', '.agent-learning', 'docs/agent-learning', 'agent-learning'] as $layout) {
            $project = $this->tempDir('legacy-root');
            $root = $project . '/' . $layout;
            mkdir($root . '/history', 0777, true);

            $config = (new LearningRootResolver())->resolve($project);

            self::assertSame($root, $config->root);
            self::assertSame($project, $config->projectRoot);
            self::assertSame($root, (new PathResolver())->resolve($project));
        }
    }

    public function testConfigJsonLoadsPathDefaultsAndCliOverridesWin(): void
    {
        $project = $this->tempDir('config-root');
        $root = $project . '/infra/doc/agent-learning';
        mkdir($root . '/history', 0777, true);
        mkdir($project . '/configured-project', 0777, true);
        mkdir($project . '/cli-project', 0777, true);
        file_put_contents($root . '/config.json', json_encode([
            'project_root' => '../../../configured-project',
            'constraint_generation_dir' => 'configured-generation',
            'active_constraints_dir' => 'configured-active',
        ], JSON_THROW_ON_ERROR));

        $fromConfig = (new LearningRootResolver())->resolve($root);
        self::assertSame($project . '/configured-project', $fromConfig->projectRoot);
        self::assertSame($root . '/configured-generation', $fromConfig->constraintGenerationDirectory);
        self::assertSame($root . '/configured-active', $fromConfig->activeConstraintsDirectory);

        $fromCli = (new LearningRootResolver())->resolveWithOverrides(new CliOverrides(
            root: $root,
            projectRoot: '../../../cli-project',
            constraintGenerationDirectory: 'cli-generation',
            activeConstraintsDirectory: 'cli-active',
        ));
        self::assertSame($project . '/cli-project', $fromCli->projectRoot);
        self::assertSame($root . '/cli-generation', $fromCli->constraintGenerationDirectory);
        self::assertSame($root . '/cli-active', $fromCli->activeConstraintsDirectory);
    }

    public function testLearningProjectPathsUsesCentralResolverCompatibly(): void
    {
        $project = $this->tempDir('paths-root');
        $root = $project . '/infra/doc/agent-learning';
        mkdir($root . '/history', 0777, true);
        mkdir($project . '/configured-project', 0777, true);
        file_put_contents($root . '/config.json', json_encode([
            'project_root' => '../../../configured-project',
            'constraint_generation_dir' => 'configured-generation',
            'active_constraints_dir' => 'configured-active',
        ], JSON_THROW_ON_ERROR));

        $paths = new LearningProjectPaths();

        self::assertSame($project . '/configured-project', $paths->projectRootForLearningRoot($root));
        self::assertSame($root . '/configured-generation', $paths->constraintGenerationDirectory($root));
        self::assertSame($root . '/configured-active', $paths->activeConstraintsDirectory($root));
    }

    public function testInvalidConfigErrorsRemainContextual(): void
    {
        $root = $this->tempDir('invalid-config') . '/.agent-learning';
        mkdir($root . '/history', 0777, true);
        file_put_contents($root . '/config.json', json_encode(['constraint_generation_dir' => []], JSON_THROW_ON_ERROR));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('invalid path configuration field: constraint_generation_dir');

        (new LearningRootResolver())->resolve($root);
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/agent-learning-' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
