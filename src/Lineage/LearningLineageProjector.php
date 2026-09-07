<?php

declare(strict_types=1);

namespace voku\AgentLearning\Lineage;

use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\LearningNoteProjection;

final readonly class LearningLineageProjector
{
    public const string FINDING_FROM_TASK = 'finding_from_task';
    public const string PROPOSAL_FROM_FINDING = 'proposal_from_finding';
    public const string PROPOSAL_SUPERSEDES = 'proposal_supersedes';
    public const string PROPOSAL_CONFLICTS_WITH = 'proposal_conflicts_with';
    public const string PROPOSAL_CORRECTS = 'proposal_corrects';
    public const string NOTE_FROM_FINDING = 'note_from_finding';
    public const string NOTE_FROM_PROPOSAL = 'note_from_proposal';

    /**
     * @param list<FindingProjection> $findings
     * @param list<ProposalProjection> $proposals
     * @param list<LearningNoteProjection> $notes
     *
     * @return list<GraphRelation>
     */
    public function project(array $findings, array $proposals, array $notes): array
    {
        /** @var array<string, GraphRelation> $relationsByKey */
        $relationsByKey = [];

        foreach ($findings as $finding) {
            $this->add($relationsByKey, $finding->taskId, self::FINDING_FROM_TASK, $finding->id);
            foreach ($finding->proposalIds as $proposalId) {
                $this->add($relationsByKey, $finding->id, self::PROPOSAL_FROM_FINDING, $proposalId);
            }
        }

        foreach ($proposals as $proposal) {
            foreach ($proposal->supersedesProposalIds as $supersededProposalId) {
                $this->add($relationsByKey, $proposal->id, self::PROPOSAL_SUPERSEDES, $supersededProposalId);
            }
            foreach ($proposal->conflictsWithProposalIds as $conflictingProposalId) {
                $this->add($relationsByKey, $proposal->id, self::PROPOSAL_CONFLICTS_WITH, $conflictingProposalId);
            }
            if ($proposal->correctsProposalId !== null) {
                $this->add($relationsByKey, $proposal->id, self::PROPOSAL_CORRECTS, $proposal->correctsProposalId);
            }
        }

        foreach ($notes as $note) {
            foreach ($note->sourceFindings as $findingId) {
                $this->add($relationsByKey, $findingId, self::NOTE_FROM_FINDING, $note->id);
            }
            foreach ($note->sourceProposals as $proposalId) {
                $this->add($relationsByKey, $proposalId, self::NOTE_FROM_PROPOSAL, $note->id);
            }
        }

        $relations = array_values($relationsByKey);
        usort(
            $relations,
            static fn (GraphRelation $left, GraphRelation $right): int => [
                $left->sourceId,
                $left->kind,
                implode("\0", $left->targetIds),
                $left->id,
            ] <=> [
                $right->sourceId,
                $right->kind,
                implode("\0", $right->targetIds),
                $right->id,
            ],
        );

        return $relations;
    }

    /**
     * @param array<string, GraphRelation> $relationsByKey
     */
    private function add(array &$relationsByKey, string $sourceId, string $kind, string $targetId): void
    {
        $key = $kind . "\0" . $sourceId . "\0" . $targetId;
        if (isset($relationsByKey[$key])) {
            return;
        }

        $relationsByKey[$key] = new GraphRelation(
            id: 'learning-lineage.' . $kind . '.' . substr(hash('sha256', $key), 0, 24),
            sourceId: $sourceId,
            kind: $kind,
            targetIds: [$targetId],
        );
    }
}
