<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Maps a ConsolidationResult to a candidate Proposal.
 */
final class ProposalFromConsolidationResult
{
    /**
     * Map a ConsolidationResult to a candidate Proposal.
     *
     * @param ConsolidationResult $result
     * @param string              $proposalId
     * @param string|null         $createdAt
     * @return Proposal
     */
    public function map(ConsolidationResult $result, string $proposalId, ?string $createdAt = null): Proposal
    {
        $createdAtStr = $createdAt ?? (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

        $raw = [
            'id' => $proposalId,
            'created_at' => $createdAtStr,
            'action' => $result->getAction()->value,
            'source_findings' => $result->getSourceFindings(),
            'reason' => $result->getReason(),
            'remaining_uncertainty' => $result->getRemainingUncertainty(),
            'status' => ProposalStatus::CANDIDATE->value,
            'proposed_by' => 'consolidation',
            'approved_by' => null,
            'approved_at' => null,
        ];

        if ($result instanceof NoDurableLearningResult) {
            $raw['existing_guidance_id'] = $result->existingGuidanceId;
            $raw['target_type'] = null;
            $raw['target'] = null;
            $raw['scope'] = [];
            $raw['old'] = null;
            $raw['new'] = null;
            $raw['boundary'] = null;
            $raw['validation'] = [];
        } else {
            // Durable mutation
            /** @var AddResult|DeleteResult|ReplaceResult|RejectResult $result */
            $raw['target_type'] = $result->targetType;
            $raw['target'] = $result->target;
            $raw['scope'] = $result->scope;
            $raw['old'] = $result->old;
            $raw['new'] = $result->new;
            $raw['boundary'] = $result->boundary;
            $raw['validation'] = $result->validation;
            $raw['scope_justification'] = $result->getReason();
        }

        return new Proposal(
            $proposalId,
            $createdAtStr,
            $result->getAction(),
            $raw['target_type'],
            $raw['target'],
            $raw['scope'],
            $raw['source_findings'],
            $raw['old'],
            $raw['new'],
            $raw['reason'],
            $raw['boundary'],
            $raw['validation'],
            ProposalStatus::CANDIDATE,
            'consolidation',
            null,
            null,
            $raw
        );
    }
}
