<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FindingLifecycle
{
    /**
     * @var array<string, FindingStatus>
     */
    private const array DIRECTORY_TO_STATUS = [
        'candidate' => FindingStatus::CANDIDATE,
        'validated' => FindingStatus::VALIDATED,
        'invalidated' => FindingStatus::INVALIDATED,
        'rejected' => FindingStatus::REJECTED,
        'superseded' => FindingStatus::SUPERSEDED,
        'consolidated' => FindingStatus::CONSOLIDATED,
        'archived' => FindingStatus::ARCHIVED,
    ];

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return array_keys(self::DIRECTORY_TO_STATUS);
    }

    public function directoryFor(FindingStatus $status): string
    {
        foreach (self::DIRECTORY_TO_STATUS as $directory => $directoryStatus) {
            if ($directoryStatus === $status) {
                return $directory;
            }
        }

        throw new ValidationException('', null, null, 'unsupported finding status: ' . $status->value);
    }

    /**
     * @return list<string>
     */
    public function findingFiles(string $root): array
    {
        $files = [];
        foreach ($this->directories() as $directory) {
            $path = $root . '/findings/' . $directory;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json' || $fileInfo->getSize() === 0) {
                    continue;
                }
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function assertPathMatchesStatus(Finding $finding, string $file, string $root): void
    {
        $expectedDirectory = $this->directoryFor($finding->status);
        $relativePath = $this->relativePath($file, $root);
        if ($relativePath === null) {
            return;
        }

        $parts = explode('/', $relativePath);
        if ($parts[0] !== 'findings') {
            return;
        }

        $actualDirectory = $parts[1] ?? '';
        if ($actualDirectory !== $expectedDirectory) {
            throw new ValidationException(
                $file,
                null,
                $finding->id,
                'finding status ' . $finding->status->value . ' must be stored under findings/' . $expectedDirectory
            );
        }
    }

    private function relativePath(string $file, string $root): ?string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $file = str_replace('\\', '/', $file);
        if (!str_starts_with($file, $root)) {
            return null;
        }

        return substr($file, strlen($root));
    }
}
