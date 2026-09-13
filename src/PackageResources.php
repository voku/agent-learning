<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * The single owner of package-shipped resource locations.
 */
final class PackageResources
{
    public const string SKILLS = 'resources/skills';

    public const string CONSUMER_INSTRUCTIONS = 'resources/instructions/consumer.md';

    public const string CONSTRAINT_PRECEDENTS = 'examples/constraints';

    public static function skillsRoot(): string
    {
        return dirname(__DIR__) . '/' . self::SKILLS;
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function consumerSkills(): array
    {
        $root = self::skillsRoot();

        return [
            'agent-learning-consumer' => $root . '/agent-learning-consumer',
            'agent-learning-note' => $root . '/agent-learning-note',
            'agent-hard-constraint-author' => $root . '/agent-hard-constraint-author',
            'agent-learning-ctx-evidence' => $root . '/agent-learning-ctx-evidence',
        ];
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function maintainerSkills(): array
    {
        $root = self::skillsRoot();

        return [
            'agent-learning-maintainer' => $root . '/agent-learning-maintainer',
        ];
    }

    public static function consumerInstructionFragment(): ?string
    {
        $path = dirname(__DIR__) . '/' . self::CONSUMER_INSTRUCTIONS;

        return is_file($path) ? $path : null;
    }

    public static function constraintPrecedentsRoot(): string
    {
        return dirname(__DIR__) . '/' . self::CONSTRAINT_PRECEDENTS;
    }
}
