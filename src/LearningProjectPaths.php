<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class LearningProjectPaths
{
    /**
     * @var list<string>
     */
    private const array LEGACY_LEARNING_ROOT_SUFFIXES = [
        'infra/doc/agent-learning',
        '.agent-learning',
        'docs/agent-learning',
        'agent-learning',
    ];

    public function projectRootForLearningRoot(string $root, ?string $configuredProjectRoot = null): string
    {
        $projectRoot = $configuredProjectRoot ?? $this->configuredString($root, 'project_root');
        if ($projectRoot !== null) {
            return $this->resolveDirectory($root, $projectRoot, 'project_root');
        }

        foreach (self::LEGACY_LEARNING_ROOT_SUFFIXES as $suffix) {
            if (str_ends_with($root, '/' . $suffix)) {
                return substr($root, 0, -strlen('/' . $suffix));
            }
        }

        return $root;
    }

    public function constraintGenerationDirectory(string $root, ?string $configuredDirectory = null): string
    {
        $directory = $configuredDirectory
            ?? $this->configuredString($root, 'constraint_generation_dir')
            ?? 'constraint-generation';

        return $this->resolvePath($root, $directory);
    }

    public function activeConstraintsDirectory(string $root, ?string $configuredDirectory = null): string
    {
        $directory = $configuredDirectory
            ?? $this->configuredString($root, 'active_constraints_dir')
            ?? 'constraints/active';

        return $this->resolvePath($root, $directory);
    }

    private function configuredString(string $root, string $key): ?string
    {
        $config = $this->loadConfig($root);
        $value = $config[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($root . '/config.json', null, null, 'invalid path configuration field: ' . $key);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(string $root): array
    {
        $configPath = $root . '/config.json';
        if (!is_file($configPath)) {
            return [];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new ValidationException($configPath, null, null, 'cannot read path configuration');
        }

        try {
            $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException($configPath, null, null, 'malformed path configuration JSON: ' . $exception->getMessage());
        }

        if (!is_array($config)) {
            throw new ValidationException($configPath, null, null, 'path configuration must be a JSON object');
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    private function resolveDirectory(string $root, string $path, string $field): string
    {
        $resolved = $this->resolvePath($root, $path);
        $realPath = realpath($resolved);
        if ($realPath === false || !is_dir($realPath)) {
            throw new ValidationException($root . '/config.json', null, null, 'configured directory does not exist for ' . $field . ': ' . $path);
        }

        return rtrim(str_replace('\\', '/', $realPath), '/');
    }

    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new ValidationException($root . '/config.json', null, null, 'configured path must not be empty');
        }

        if ($this->isAbsolutePath($path)) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim(str_replace('\\', '/', $root . '/' . $path), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
