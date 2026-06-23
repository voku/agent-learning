<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ProposalLifecycle
{
    /**
     * @var array<string, ProposalStatus>
     */
    private const array DIRECTORY_TO_STATUS = [
        'candidate' => ProposalStatus::CANDIDATE,
        'approved' => ProposalStatus::APPROVED,
        'rejected' => ProposalStatus::REJECTED,
        'applied' => ProposalStatus::APPLIED,
        'retired' => ProposalStatus::RETIRED,
    ];

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return array_keys(self::DIRECTORY_TO_STATUS);
    }

    public function directoryFor(ProposalStatus $status): string
    {
        foreach (self::DIRECTORY_TO_STATUS as $directory => $directoryStatus) {
            if ($directoryStatus === $status) {
                return $directory;
            }
        }

        throw new ValidationException('', null, null, 'unsupported proposal status: ' . $status->value);
    }

    public function assertPathMatchesStatus(Proposal $proposal, string $file, string $root): void
    {
        $expectedDirectory = $this->directoryFor($proposal->status);
        $relativePath = $this->relativePath($file, $root);
        if ($relativePath === null) {
            return;
        }

        $parts = explode('/', $relativePath);
        if ($parts[0] !== 'proposals') {
            return;
        }

        $actualDirectory = $parts[1] ?? '';
        if ($actualDirectory !== $expectedDirectory) {
            throw new ValidationException(
                $file,
                null,
                $proposal->id,
                'proposal status ' . $proposal->status->value . ' must be stored under proposals/' . $expectedDirectory
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
