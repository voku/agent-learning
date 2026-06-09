<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FindingRepository
{
    public function __construct(
        private readonly FindingValidator $validator = new FindingValidator(),
    ) {
    }

    /**
     * @return array<string, Finding>
     */
    public function loadValidated(string $root): array
    {
        $findings = [];
        foreach ($this->jsonFiles($root . '/findings/validated') as $file) {
            $finding = $this->validator->validateFile($file);
            if (isset($findings[$finding->id])) {
                throw new ValidationException($file, null, $finding->id, 'duplicate finding ID');
            }
            $findings[$finding->id] = $finding;
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json' || $fileInfo->getSize() === 0) {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
        sort($files);

        return $files;
    }
}
