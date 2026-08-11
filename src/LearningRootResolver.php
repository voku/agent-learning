<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use JsonException;

final class LearningRootResolver
{
    private const string DEFAULT_LEARNING_ROOT = '.agent-loop/learning';

    public function resolve(?string $root = null, ?string $startDirectory = null): LearningRootConfig
    {
        return $this->resolveWithOverrides(new CliOverrides(root: $root), $startDirectory);
    }

    public function resolveWithOverrides(CliOverrides $overrides, ?string $startDirectory = null): LearningRootConfig
    {
        $learningRoot = $overrides->root !== null && trim($overrides->root) !== ''
            ? $this->resolveExplicit($overrides->root)
            : $this->discover($startDirectory);
        $config = $this->loadConfig($learningRoot);

        return new LearningRootConfig(
            $learningRoot,
            $this->projectRoot($learningRoot, $overrides->projectRoot, $config),
            $this->configuredPath($learningRoot, $overrides->constraintGenerationDirectory, $config, 'constraint_generation_dir', 'constraint-generation'),
            $this->configuredPath($learningRoot, $overrides->activeConstraintsDirectory, $config, 'active_constraints_dir', 'constraints/active'),
        );
    }

    private function discover(?string $startDirectory): string
    {
        $start = $startDirectory ?? getcwd();
        if ($start === false || $start === '') {
            throw new ValidationException('.', null, null, 'cannot determine current working directory');
        }
        $directory = $this->normalizeExistingDirectory($start);
        while (true) {
            $candidatePath = $directory . '/' . self::DEFAULT_LEARNING_ROOT;
            if (is_dir($candidatePath) && $this->isLearningRoot($candidatePath)) {
                return $this->normalizeExistingDirectory($candidatePath);
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
        throw new ValidationException($start, null, null, 'cannot find .agent-loop/learning root; pass --root for an explicit learning root');
    }

    private function resolveExplicit(string $root): string
    {
        $directory = $this->normalizeExistingDirectory($root);
        if ($this->isLearningRoot($directory)) {
            return $directory;
        }

        $candidatePath = $directory . '/' . self::DEFAULT_LEARNING_ROOT;
        if (is_dir($candidatePath) && $this->isLearningRoot($candidatePath)) {
            return $this->normalizeExistingDirectory($candidatePath);
        }

        throw new ValidationException($root, null, null, 'directory is not an agent-learning root');
    }

    /** @param array<string, mixed> $config */
    private function projectRoot(string $root, ?string $override, array $config): string
    {
        $configured = $override ?? $this->configuredString($root, $config, 'project_root');
        if ($configured !== null) {
            $resolved = $this->resolvePath($root, $configured);
            $realPath = realpath($resolved);
            if ($realPath === false || !is_dir($realPath)) {
                throw new ValidationException($root . '/config.json', null, null, 'configured directory does not exist for project_root: ' . $configured);
            }
            return rtrim(str_replace('\\', '/', $realPath), '/');
        }
        if (str_ends_with($root, '/' . self::DEFAULT_LEARNING_ROOT)) {
            return substr($root, 0, -strlen('/' . self::DEFAULT_LEARNING_ROOT));
        }
        return $root;
    }

    /** @param array<string, mixed> $config */
    private function configuredPath(string $root, ?string $override, array $config, string $key, string $default): string
    {
        return $this->resolvePath($root, $override ?? $this->configuredString($root, $config, $key) ?? $default);
    }

    /** @param array<string, mixed> $config */
    private function configuredString(string $root, array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if ($value === null) return null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($root . '/config.json', null, null, 'invalid path configuration field: ' . $key);
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function loadConfig(string $root): array
    {
        $path = $root . '/config.json';
        if (!is_file($path)) return [];
        $content = file_get_contents($path);
        if ($content === false) throw new ValidationException($path, null, null, 'cannot read path configuration');
        try { $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new ValidationException($path, null, null, 'malformed path configuration JSON: ' . $e->getMessage()); }
        if (!is_array($config)) throw new ValidationException($path, null, null, 'path configuration must be a JSON object');
        return $config;
    }

    private function normalizeExistingDirectory(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) throw new ValidationException($path, null, null, 'directory does not exist');
        return rtrim(str_replace('\\', '/', $realPath), '/');
    }

    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') throw new ValidationException($root . '/config.json', null, null, 'configured path must not be empty');
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) return rtrim(str_replace('\\', '/', $path), '/');
        return rtrim(str_replace('\\', '/', $root . '/' . $path), '/');
    }

    private function isLearningRoot(string $path): bool
    {
        return is_dir($path . '/findings') || is_dir($path . '/proposals') || is_dir($path . '/history') || is_dir($path . '/templates');
    }
}
