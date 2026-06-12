<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Repository for active guidance files.
 */
final class ActiveGuidanceRepository
{
    public function __construct(
        private readonly RedactionGuard $redactionGuard = new RedactionGuard()
    ) {
    }

    /**
     * Load, parse, and validate guidance files.
     *
     * @param string $root The root directory path.
     * @param list<string> $guidancePaths Relative or absolute paths to guidance files.
     * @return list<ActiveGuidance>
     * @throws ValidationException
     */
    public function loadAll(string $root, array $guidancePaths): array
    {
        $guidances = [];
        $ids = [];

        foreach ($guidancePaths as $path) {
            $absolutePath = str_starts_with($path, '/') ? $path : $root . '/' . $path;
            if (!is_file($absolutePath)) {
                throw new ValidationException($absolutePath, null, null, 'guidance file does not exist');
            }

            $content = file_get_contents($absolutePath);
            if ($content === false) {
                throw new ValidationException($absolutePath, null, null, 'cannot read guidance file');
            }

            if (trim($content) === '') {
                throw new ValidationException($absolutePath, null, null, 'empty guidance content');
            }

            // Check redaction
            $this->redactionGuard->assertSafeValue($content, $absolutePath);

            // Get relative path for source field
            $relativePath = str_starts_with($absolutePath, $root . '/')
                ? substr($absolutePath, strlen($root) + 1)
                : $path;

            $guidance = $this->parse($content, $relativePath, $absolutePath);

            if (isset($ids[$guidance->id])) {
                throw new ValidationException($absolutePath, null, $guidance->id, 'duplicate guidance ID: ' . $guidance->id);
            }

            $ids[$guidance->id] = true;
            $guidances[] = $guidance;
        }

        // Ordering is deterministic (sort by ID)
        usort($guidances, static fn(ActiveGuidance $a, ActiveGuidance $b) => strcmp($a->id, $b->id));

        return $guidances;
    }

    private function parse(string $content, string $relativePath, string $absolutePath): ActiveGuidance
    {
        $id = null;
        $type = null;
        $scope = [];

        // Parse comments: <!-- key: value -->
        if (preg_match_all('/<!--\s*([a-zA-Z0-9_-]+)\s*:\s*(.*?)\s*-->/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1]);
                $val = trim($match[2]);
                if ($key === 'id') {
                    $id = $val;
                } elseif ($key === 'type') {
                    $type = $val;
                } elseif ($key === 'scope') {
                    $scope = array_filter(array_map('trim', explode(',', $val)));
                }
            }
        }

        // Parse frontmatter if present
        if (preg_match('/^---\s*\r?\n(.*?)\r?\n---\s*(?:\r?\n|$)/s', $content, $fmMatches)) {
            $fmLines = explode("\n", $fmMatches[1]);
            foreach ($fmLines as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = strtolower(trim($parts[0]));
                    $val = trim($parts[1]);
                    $val = trim($val, '"\'');
                    if ($key === 'id') {
                        $id = $val;
                    } elseif ($key === 'type') {
                        $type = $val;
                    } elseif ($key === 'scope') {
                        if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
                            $val = substr($val, 1, -1);
                        }
                        $scope = array_filter(array_map('trim', explode(',', $val)));
                    }
                }
            }
        }

        // fallback type
        if ($type === null) {
            if (str_contains($relativePath, 'skill')) {
                $typeVal = GuidanceType::SKILL;
            } elseif (str_contains($relativePath, 'constraint')) {
                $typeVal = GuidanceType::CONSTRAINT;
            } else {
                $typeVal = GuidanceType::MEMORY;
            }
        } else {
            $typeVal = GuidanceType::tryFrom(strtolower($type));
            if ($typeVal === null) {
                throw new ValidationException($absolutePath, null, null, 'unsupported guidance type: ' . $type);
            }
        }

        // fallback id
        if ($id === null) {
            $filename = pathinfo($relativePath, PATHINFO_FILENAME);
            if ($typeVal === GuidanceType::SKILL) {
                $id = 'skill.' . $filename;
            } elseif ($typeVal === GuidanceType::CONSTRAINT) {
                $id = 'constraint.' . $filename;
            } else {
                $id = strtolower($filename) === 'memory' ? 'memory' : $filename;
            }
        }

        // fallback scope
        if ($scope === []) {
            $scope = ['/'];
        }

        if ($id === '') {
            throw new ValidationException($absolutePath, null, null, 'guidance ID cannot be empty');
        }

        if ($relativePath === '') {
            throw new ValidationException($absolutePath, null, null, 'guidance relative path cannot be empty');
        }

        if (trim($content) === '') {
            throw new ValidationException($absolutePath, null, null, 'empty guidance content');
        }

        /** @var list<non-empty-string> $scopeList */
        $scopeList = array_values($scope);

        return new ActiveGuidance($id, $typeVal, $relativePath, $scopeList, $content);
    }
}
