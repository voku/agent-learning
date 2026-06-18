<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class LearningProjectPaths
{
    public function __construct(
        private readonly LearningRootResolver $resolver = new LearningRootResolver(),
    ) {
    }

    public function projectRootForLearningRoot(string $root, ?string $configuredProjectRoot = null): string
    {
        return $this->resolver->resolveWithOverrides(new CliOverrides(root: $root, projectRoot: $configuredProjectRoot))->projectRoot;
    }

    public function constraintGenerationDirectory(string $root, ?string $configuredDirectory = null): string
    {
        return $this->resolver->resolveWithOverrides(new CliOverrides(root: $root, constraintGenerationDirectory: $configuredDirectory))->constraintGenerationDirectory;
    }

    public function activeConstraintsDirectory(string $root, ?string $configuredDirectory = null): string
    {
        return $this->resolver->resolveWithOverrides(new CliOverrides(root: $root, activeConstraintsDirectory: $configuredDirectory))->activeConstraintsDirectory;
    }
}
