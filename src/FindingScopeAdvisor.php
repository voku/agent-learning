<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Flags a Finding scope that names only individual files.
 *
 * A LearningNote inherits its scope from its source Findings, and Recall
 * selects a note only for tasks whose files fall inside that scope. A lesson
 * about how to change a decision that is scoped to the files of the incident
 * where it was observed cannot reach the next change to that decision in a
 * sibling file (agent-learning#203). The advisory is non-blocking: many
 * lessons really are file-specific.
 */
final class FindingScopeAdvisor
{
    public const string SCOPE_NAMES_ONLY_FILES = 'scope_names_only_files';

    /**
     * When $projectRoot is given, an entry that exists there is classified by
     * the filesystem (so a dotted directory such as `src/Init.v2` is a directory
     * and an extensionless `Dockerfile` is a file). Only entries that do not
     * exist fall back to the basename heuristic.
     *
     * @param list<string> $scope
     *
     * @return list<array{code: string, message: string}>
     */
    public function advise(array $scope, ?string $projectRoot = null): array
    {
        if ($scope === []) {
            return [];
        }
        foreach ($scope as $entry) {
            if (!self::isFilePath($entry, $projectRoot)) {
                return [];
            }
        }

        return [[
            'code' => self::SCOPE_NAMES_ONLY_FILES,
            'message' => 'Every scope entry is a single file. A LearningNote inherits this scope, and Recall selects it only for tasks touching these files. '
                . 'If the lesson is about a decision that lives elsewhere, add that home with another --scope (its directory, when sibling files must receive the lesson) so the lesson reaches its next occurrence.',
        ]];
    }

    private static function isFilePath(string $entry, ?string $projectRoot): bool
    {
        $entry = trim($entry);
        if ($entry === '' || str_ends_with($entry, '/') || strpbrk($entry, '*?[') !== false) {
            return false;
        }
        if ($projectRoot !== null && !str_starts_with($entry, '/') && !str_contains($entry, '..')) {
            $path = rtrim($projectRoot, '/\\') . '/' . $entry;
            if (is_dir($path)) {
                return false;
            }
            if (is_file($path)) {
                return true;
            }
        }
        $basename = basename(str_replace('\\', '/', $entry));

        // Not on disk: a leading dot alone (".github") names a directory as often as a file; stay silent.
        return str_contains(ltrim($basename, '.'), '.');
    }
}
