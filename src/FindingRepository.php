<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FindingRepository
{
    public function __construct(
        private readonly FindingValidator $validator = new FindingValidator(),
        private readonly FindingLifecycle $lifecycle = new FindingLifecycle(),
    ) {
    }

    /**
     * Load every owner-recognized finding lifecycle state.
     *
     * @return array<string, Finding>
     */
    public function loadAll(string $root): array
    {
        $findings = [];
        foreach ($this->lifecycle->directories() as $statusDirectory) {
            foreach ($this->jsonFiles($root . '/findings/' . $statusDirectory) as $file) {
                $finding = $this->validator->validateFile($file);
                $this->lifecycle->assertPathMatchesStatus($finding, $file, $root);
                if (isset($findings[$finding->id])) {
                    throw new ValidationException($file, null, $finding->id, 'duplicate finding ID');
                }
                $findings[$finding->id] = $finding;
            }
        }
        ksort($findings, SORT_STRING);

        return $findings;
    }

    /**
     * @return array<string, Finding>
     */
    public function loadValidated(string $root): array
    {
        return array_filter(
            $this->loadAll($root),
            static fn (Finding $finding): bool => $finding->status === FindingStatus::VALIDATED,
        );
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
