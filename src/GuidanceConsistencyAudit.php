<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Closure;

/**
 * Read-only consistency candidates over written guidance (AGENTS.md, MEMORY.md, skills, ADRs, ...).
 *
 * Only facts a machine can establish are reported: a repository path named in prose that does not exist,
 * and the same wording kept in two places. Whether two statements contradict each other needs judgement and
 * stays with the reviewer; this class never produces a verdict or a proposal.
 */
final readonly class GuidanceConsistencyAudit
{
    private const int MIN_TABLE_ROW_CHARS = 160;
    private const int MIN_PARAGRAPH_CHARS = 200;
    private const int MIN_PHRASES = 25;
    private const int COMMON_PHRASE_LIMIT = 6;

    public function __construct(private WordingOverlap $wording = new WordingOverlap())
    {
    }

    /**
     * @param list<string>       $sourceGlobs   patterns relative to the project root
     * @param Closure|null       $isIgnored     fn(string $relativePath): bool, true for a version-control-ignored (generated) path
     *
     * @return list<GuidanceConsistencyCandidate>
     */
    public function audit(string $projectRoot, array $sourceGlobs, ?Closure $isIgnored = null, int $minSharedPercent = 50): array
    {
        $isIgnored ??= static function (string $path) use ($projectRoot): bool {
            exec('git -C ' . escapeshellarg($projectRoot) . ' check-ignore -q -- ' . escapeshellarg($path) . ' 2>/dev/null', $output, $code);

            return $code === 0;
        };

        $files = $this->expand($projectRoot, $sourceGlobs);

        return array_merge(
            $this->unresolvedPaths($projectRoot, $files, $isIgnored),
            $this->duplicateWording($projectRoot, $files, $minSharedPercent),
        );
    }

    /**
     * @param list<string> $globs
     *
     * @return list<string> project-relative file paths, sorted
     */
    private function expand(string $projectRoot, array $globs): array
    {
        $files = [];
        foreach ($globs as $glob) {
            foreach (glob(rtrim($projectRoot, '/') . '/' . ltrim($glob, '/'), defined('GLOB_BRACE') ? GLOB_BRACE : 0) ?: [] as $path) {
                if (is_file($path)) {
                    $files[substr($path, strlen(rtrim($projectRoot, '/')) + 1)] = true;
                }
            }
        }
        $names = array_keys($files);
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $files
     *
     * @return list<GuidanceConsistencyCandidate>
     */
    private function unresolvedPaths(string $projectRoot, array $files, Closure $isIgnored): array
    {
        $root = rtrim($projectRoot, '/');
        $ignoredCache = [];
        $candidates = [];
        foreach ($files as $file) {
            $inFence = false;
            foreach (file($root . '/' . $file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (preg_match('/^\s*```/', $line) === 1) {
                    $inFence = !$inFence;

                    continue;
                }
                if ($inFence || preg_match_all('/`([^`\s]{3,160})`/', $line, $spans) === 0) {
                    continue;
                }
                foreach ($spans[1] as $span) {
                    $path = $this->claimedPath($span, $root);
                    if ($path === null || file_exists($root . '/' . $path) || file_exists($root . '/' . dirname($file) . '/' . $path)) {
                        continue;
                    }
                    $ignoredCache[$path] ??= $isIgnored($path);
                    if ($ignoredCache[$path]) {
                        continue;
                    }
                    $candidates[] = new GuidanceConsistencyCandidate(
                        GuidanceConsistencyCandidate::KIND_UNRESOLVED_PATH,
                        $file . ':' . ($index + 1),
                        null,
                        '`' . $path . '` does not exist, neither relative to the project root nor to the file naming it.',
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * The repository path an inline-code span claims, or null when the span is not such a claim.
     */
    private function claimedPath(string $span, string $root): ?string
    {
        $path = preg_replace('/(:\d+(-\d+)?|#L\d+.*)$/', '', $span) ?? $span;
        $hadTrailingSlash = str_ends_with($path, '/');
        $path = rtrim($path, '/');
        if (
            $path === ''
            || preg_match('/[*<>{}$()\[\]|=,;]|\.\.\.|^https?:|^[\/~]|\w::|->/', $path) === 1
            || !str_contains($path, '/') && !$hadTrailingSlash
        ) {
            return null;
        }
        $hasExtension = preg_match('/\.[A-Za-z0-9]{1,8}$/', $path) === 1;
        $first = explode('/', ltrim($path, './'))[0];
        if (!$hasExtension && !$hadTrailingSlash && !file_exists($root . '/' . $first)) {
            return null;
        }

        return ltrim($path, './') === '' ? null : (str_starts_with($path, './') ? substr($path, 2) : $path);
    }

    /**
     * @param list<string> $files
     *
     * @return list<GuidanceConsistencyCandidate>
     */
    private function duplicateWording(string $projectRoot, array $files, int $minSharedPercent): array
    {
        $root = rtrim($projectRoot, '/');
        $units = [];
        foreach ($files as $file) {
            foreach ($this->units(file($root . '/' . $file, FILE_IGNORE_NEW_LINES) ?: []) as [$line, $text]) {
                $phrases = $this->wording->shingles($text);
                if (count($phrases) >= self::MIN_PHRASES) {
                    $units[] = ['ref' => $file . ':' . $line, 'file' => $file, 'phrases' => $phrases];
                }
            }
        }

        $index = [];
        foreach ($units as $unitIndex => $unit) {
            foreach ($unit['phrases'] as $phrase) {
                $index[$phrase][] = $unitIndex;
            }
        }
        $shared = [];
        foreach ($index as $unitIndexes) {
            if (count($unitIndexes) < 2 || count($unitIndexes) > self::COMMON_PHRASE_LIMIT) {
                continue;
            }
            foreach ($unitIndexes as $a) {
                foreach ($unitIndexes as $b) {
                    if ($a < $b && $units[$a]['file'] !== $units[$b]['file']) {
                        $key = $a . '|' . $b;
                        $shared[$key] = ($shared[$key] ?? 0) + 1;
                    }
                }
            }
        }

        $candidates = [];
        foreach ($shared as $key => $count) {
            [$a, $b] = array_map('intval', explode('|', $key));
            $percent = intdiv($count * 100, min(count($units[$a]['phrases']), count($units[$b]['phrases'])));
            if ($percent >= $minSharedPercent) {
                $candidates[] = [$percent, new GuidanceConsistencyCandidate(
                    GuidanceConsistencyCandidate::KIND_DUPLICATE_WORDING,
                    $units[$a]['ref'],
                    $units[$b]['ref'],
                    $percent . '% of the shorter passage\'s 4-word phrases also appear in the other passage.',
                )];
            }
        }
        usort($candidates, static fn (array $x, array $y): int => [$y[0], $x[1]->sourceA] <=> [$x[0], $y[1]->sourceA]);

        return array_map(static fn (array $pair): GuidanceConsistencyCandidate => $pair[1], $candidates);
    }

    /**
     * Long table rows and paragraphs outside code fences, each with the line it starts on.
     *
     * @param list<string> $lines
     *
     * @return list<array{int, string}>
     */
    private function units(array $lines): array
    {
        $units = [];
        $buffer = '';
        $start = 0;
        $inFence = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $this->closeParagraph($units, $buffer, $start);
                $inFence = !$inFence;

                continue;
            }
            if ($inFence) {
                continue;
            }
            if (str_starts_with($line, '|')) {
                $this->closeParagraph($units, $buffer, $start);
                if (mb_strlen($line) > self::MIN_TABLE_ROW_CHARS && !str_starts_with($line, '| ---')) {
                    $units[] = [$index + 1, preg_replace('/\s+/', ' ', $line) ?? $line];
                }

                continue;
            }
            if (trim($line) === '' || preg_match('/^#{1,6} /', $line) === 1) {
                $this->closeParagraph($units, $buffer, $start);

                continue;
            }
            if ($buffer === '') {
                $start = $index;
            }
            $buffer .= ' ' . trim($line);
        }
        $this->closeParagraph($units, $buffer, $start);

        return $units;
    }

    /**
     * @param list<array{int, string}> $units
     */
    private function closeParagraph(array &$units, string &$buffer, int $start): void
    {
        if (mb_strlen($buffer) > self::MIN_PARAGRAPH_CHARS) {
            $units[] = [$start + 1, trim($buffer)];
        }
        $buffer = '';
    }
}
