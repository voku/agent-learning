<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ProposalRepository
{
    public function __construct(
        private readonly ProposalValidator $validator = new ProposalValidator(),
    ) {
    }

    /**
     * @param array<string, Finding> $findingsById
     *
     * @return array<string, Proposal>
     */
    public function loadAll(string $root, array $findingsById): array
    {
        $proposals = [];
        foreach (['candidate', 'approved', 'rejected', 'applied'] as $statusDirectory) {
            foreach ($this->jsonFiles($root . '/proposals/' . $statusDirectory) as $file) {
                $proposal = $this->validator->validateFile($file, $findingsById);
                if (isset($proposals[$proposal->id])) {
                    throw new ValidationException($file, null, $proposal->id, 'duplicate proposal ID');
                }
                $proposals[$proposal->id] = $proposal;
            }
        }

        return $proposals;
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
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
        sort($files);

        return $files;
    }
}
