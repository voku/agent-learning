<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RuntimeException;

/**
 * Fails when approved or applied guidance names a project-local validation
 * script that no longer exists. Recall refuses to compile any task that selects
 * such guidance, so this surfaces the break in the change that removed the file.
 */
final readonly class GuidanceValidationEntryPointValidator
{
    public function __construct(private LearningRootResolver $rootResolver = new LearningRootResolver())
    {
    }

    /**
     * @param array<string, Proposal> $proposalsById
     */
    public function validate(string $root, array $proposalsById): void
    {
        $projectRoot = null;
        foreach ($proposalsById as $proposal) {
            if ($proposal->status !== ProposalStatus::APPROVED && $proposal->status !== ProposalStatus::APPLIED) {
                continue;
            }
            foreach ($proposal->validation as $command) {
                $entryPoint = self::projectLocalEntryPoint($command);
                if ($entryPoint === null) {
                    continue;
                }
                $projectRoot ??= $this->projectRoot($root, $proposal);
                if (!is_file($projectRoot . '/' . $entryPoint)) {
                    throw new ValidationException(
                        $root,
                        null,
                        $proposal->id,
                        'validation command references missing project-local entry point: ' . $entryPoint,
                    );
                }
            }
        }
    }

    private function projectRoot(string $root, Proposal $proposal): string
    {
        try {
            return rtrim($this->rootResolver->resolve($root)->projectRoot, '/\\');
        } catch (RuntimeException $exception) {
            throw new ValidationException($root, null, $proposal->id, 'cannot resolve project root to check validation entry points: ' . $exception->getMessage());
        }
    }

    /**
     * The repository-relative script of a `php|bash|sh <script>` command, or null
     * when the command does not name a project-local script.
     */
    public static function projectLocalEntryPoint(string $command): ?string
    {
        if (preg_match('/^(?:php|bash|sh)\s+(\S+)(?:\s|$)/', trim($command), $matches) !== 1) {
            return null;
        }

        $entryPoint = $matches[1];
        if (preg_match('/^(?:\.\/)?[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/D', $entryPoint) !== 1 || str_starts_with($entryPoint, '-')) {
            return null;
        }
        if (str_starts_with($entryPoint, './')) {
            $entryPoint = substr($entryPoint, 2);
        }

        $segments = explode('/', $entryPoint);
        if (str_starts_with($entryPoint, 'vendor/') || in_array('..', $segments, true)) {
            return null;
        }
        if (count($segments) === 1 && !str_ends_with($entryPoint, '.php') && !str_ends_with($entryPoint, '.sh')) {
            return null;
        }

        return $entryPoint;
    }
}
