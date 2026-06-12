<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Repository for loading rejected guidance history.
 */
final class RejectedGuidanceRepository
{
    public function __construct(
        private readonly JsonlValidator $jsonlValidator = new JsonlValidator(),
        private readonly ProposalParser $proposalParser = new ProposalParser(),
    ) {
    }

    /**
     * Load all rejected guidance records from the repository root.
     *
     * @param string $root
     * @return list<RejectedGuidance>
     * @throws ValidationException
     */
    public function loadAll(string $root): array
    {
        $historyPath = $root . '/history/rejected-proposals.jsonl';
        if (!is_file($historyPath)) {
            return [];
        }

        $records = $this->jsonlValidator->parseFile($historyPath);
        $rejectedGuidances = [];

        foreach ($records as $index => $record) {
            $lineNumber = $index + 1;
            $id = $record['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw new ValidationException($historyPath, $lineNumber, null, 'missing or empty rejection id');
            }

            $proposalId = $record['proposal_id'] ?? null;
            if (!is_string($proposalId) || trim($proposalId) === '') {
                throw new ValidationException($historyPath, $lineNumber, $id, 'missing or empty proposal_id');
            }

            $reason = $record['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                throw new ValidationException($historyPath, $lineNumber, $id, 'missing or empty rejection reason');
            }

            $proposalFile = $root . '/proposals/rejected/' . $proposalId . '.json';
            if (!is_file($proposalFile)) {
                throw new ValidationException($historyPath, $lineNumber, $id, 'rejected proposal file not found: ' . $proposalId . '.json');
            }

            try {
                $proposal = $this->proposalParser->parseFile($proposalFile);
            } catch (ValidationException $e) {
                throw new ValidationException($historyPath, $lineNumber, $id, 'failed to parse rejected proposal: ' . $e->getMessage());
            }

            if ($proposal->status !== ProposalStatus::REJECTED) {
                throw new ValidationException($proposalFile, null, $proposalId, 'proposal status must be rejected');
            }

            $rejectedGuidances[] = new RejectedGuidance($id, $proposal, $reason);
        }

        return $rejectedGuidances;
    }
}
