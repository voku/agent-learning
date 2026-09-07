<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\LearningNote;

final readonly class LearningLineageProjector
{
    public const PROPOSAL_FROM_FINDING = 'proposal_from_finding';
    public const PROPOSAL_SUPERSEDES = 'proposal_supersedes';
    public const PROPOSAL_CONFLICTS_WITH = 'proposal_conflicts_with';
    public const PROPOSAL_CORRECTS = 'proposal_corrects';
    public const NOTE_FROM_FINDING = 'note_from_finding';
    public const NOTE_FROM_PROPOSAL = 'note_from_proposal';

    /**
     * @param list<FindingProjection> $findings
     * @param list<ProposalProjection> $proposals
     * @param list<LearningNote> $notes
     * @return list<GraphRelation>
     */
    public function relations(array $findings, array $proposals, array $notes): array
    {
        /** @var array<string, GraphRelation> $relations */
        $relations = [];

        foreach ($findings as $finding) {
            foreach ($finding->proposalIds as $proposalId) {
                $this->add($relations, self::PROPOSAL_FROM_FINDING, $finding->id, $proposalId);
            }
        }

        foreach ($proposals as $proposal) {
            foreach ($proposal->supersedesProposalIds as $supersededProposalId) {
                $this->add($relations, self::PROPOSAL_SUPERSEDES, $supersededProposalId, $proposal->id);
            }
            foreach ($proposal->conflictsWithProposalIds as $conflictingProposalId) {
                $this->add($relations, self::PROPOSAL_CONFLICTS_WITH, $conflictingProposalId, $proposal->id);
            }
            if ($proposal->correctsProposalId !== null) {
                $this->add($relations, self::PROPOSAL_CORRECTS, $proposal->correctsProposalId, $proposal->id);
            }
        }

        foreach ($notes as $note) {
            foreach ($note->sourceFindings as $findingId) {
                $this->add($relations, self::NOTE_FROM_FINDING, $findingId, $note->id);
            }
            foreach ($note->sourceProposals as $proposalId) {
                $this->add($relations, self::NOTE_FROM_PROPOSAL, $proposalId, $note->id);
            }
        }

        ksort($relations, SORT_STRING);

        return array_values($relations);
    }

    /** @param array<string, GraphRelation> $relations */
    private function add(array &$relations, string $kind, string $sourceId, string $targetId): void
    {
        $key = $kind . "\0" . $sourceId . "\0" . $targetId;
        if (isset($relations[$key])) {
            return;
        }

        $relations[$key] = new GraphRelation(
            id: 'learning-lineage.' . hash('sha256', $key),
            sourceId: $sourceId,
            kind: $kind,
            targetIds: [$targetId],
        );
    }
}
