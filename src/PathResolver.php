<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class PathResolver
{
    /**
     * @var list<string>
     */
    private const array LEARNING_ROOT_CANDIDATES = [
        'infra/doc/agent-learning',
        '.agent-learning',
        'docs/agent-learning',
        'agent-learning',
    ];

    public function resolve(?string $root = null, ?string $startDirectory = null): string
    {
        if ($root !== null && trim($root) !== '') {
            return $this->resolveExplicit($root);
        }

        $start = $startDirectory ?? getcwd();
        if ($start === false || $start === '') {
            throw new ValidationException('.', null, null, 'cannot determine current working directory');
        }

        $directory = $this->normalizeExistingDirectory($start);
        while (true) {
            if ($this->isLearningRoot($directory)) {
                return $directory;
            }

            foreach (self::LEARNING_ROOT_CANDIDATES as $candidate) {
                $candidatePath = $directory . '/' . $candidate;
                if (is_dir($candidatePath) && $this->isLearningRoot($candidatePath)) {
                    return $this->normalizeExistingDirectory($candidatePath);
                }
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        throw new ValidationException($start, null, null, 'cannot find agent-learning root; pass --root');
    }

    private function resolveExplicit(string $root): string
    {
        $directory = $this->normalizeExistingDirectory($root);
        if ($this->isLearningRoot($directory)) {
            return $directory;
        }

        foreach (self::LEARNING_ROOT_CANDIDATES as $candidate) {
            $candidatePath = $directory . '/' . $candidate;
            if (is_dir($candidatePath) && $this->isLearningRoot($candidatePath)) {
                return $this->normalizeExistingDirectory($candidatePath);
            }
        }

        throw new ValidationException($root, null, null, 'directory is not an agent-learning root');
    }

    private function normalizeExistingDirectory(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new ValidationException($path, null, null, 'directory does not exist');
        }

        return rtrim(str_replace('\\', '/', $realPath), '/');
    }

    private function isLearningRoot(string $path): bool
    {
        return is_dir($path . '/findings')
            || is_dir($path . '/proposals')
            || is_dir($path . '/history')
            || is_dir($path . '/templates');
    }
}
