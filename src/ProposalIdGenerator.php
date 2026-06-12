<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Generates sequential proposal IDs matching the proposal.YYYY-MM-DD.NNN format.
 */
final class ProposalIdGenerator
{
    private readonly ProposalLifecycle $lifecycle;

    public function __construct()
    {
        $this->lifecycle = new ProposalLifecycle();
    }

    /**
     * Generate the next proposal ID for a given date.
     *
     * @param string                 $root
     * @param DateTimeImmutable|null $date
     * @return string
     */
    public function generate(string $root, ?DateTimeImmutable $date = null): string
    {
        $dateObj = $date ?? new DateTimeImmutable('now');
        $dateStr = $dateObj->format('Y-m-d');
        $prefix = 'proposal.' . $dateStr . '.';

        $maxNum = 0;
        foreach ($this->lifecycle->directories() as $statusDir) {
            $dir = $root . '/proposals/' . $statusDir;
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                    continue;
                }
                $filename = $fileInfo->getBasename('.json');
                if (str_starts_with($filename, $prefix)) {
                    $suffix = substr($filename, strlen($prefix));
                    if (is_numeric($suffix)) {
                        $num = (int)$suffix;
                        if ($num > $maxNum) {
                            $maxNum = $num;
                        }
                    }
                }
            }
        }

        $nextNum = $maxNum + 1;

        return $prefix . sprintf('%03d', $nextNum);
    }
}
