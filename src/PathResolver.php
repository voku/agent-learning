<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class PathResolver
{
    public function __construct(
        private readonly LearningRootResolver $resolver = new LearningRootResolver(),
    ) {
    }

    public function resolve(?string $root = null, ?string $startDirectory = null): string
    {
        return $this->resolver->resolve($root, $startDirectory)->root;
    }
}
