<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNoteProjection;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\ValidationCase;

final class LearningLineageProjectorTest extends TestCase
{
    public function testProjectsOnlyMechanicallyEncodedOwnerRelations(): void
    {
        $relations = (new LearningLineageProjector())->project(
            findings: [
                $this->finding('finding.a', ['proposal.2', 'proposal.1', 'proposal.1']),
                $this->finding('finding.b', []),
            ],
            proposals: [
                $this->proposal('proposal.1'),
                $this->proposal(
                    'proposal.2',
                    supersedes: ['proposal.1'],
                    conflicts: ['proposal.3'],
                    corrects: 'proposal.0',
                    sourceFindings: ['finding.a'],
                ),
            ],
            notes: [
                $this->note('learning-note.1', ['finding.b', 'finding.a'], ['proposal.2']),
            ],
        );

        $signatures = array_map(
            static fn (GraphRelation $relation): string => $relation->sourceId . '|' . $relation->kind . '|' . implode(',', $relation->targetIds),
            $relations,
        );
        sort($signatures, SORT_STRING);

        $expected = [
            'finding.a|note_from_finding|learning-note.1',
            'finding.a|proposal_from_finding|proposal.1',
            'finding.a|proposal_from_finding|proposal.2',
            'finding.b|note_from_finding|learning-note.1',
            'proposal.2|note_from_proposal|learning-note.1',
            'proposal.2|proposal_conflicts_with|proposal.3',
            'proposal.2|proposal_corrects|proposal.0',
            'proposal.2|proposal_supersedes|proposal.1',
        ];
        sort($expected, SORT_STRING);

        self::assertSame($expected, $signatures);
        self::assertCount(8, $relations);
        foreach ($relations as $relation) {
            self::assertCount(1, $relation->targetIds);
            self::assertMatchesRegularExpression(
                '/^learning-lineage\.(?:proposal_from_finding|proposal_supersedes|proposal_conflicts_with|proposal_corrects|note_from_finding|note_from_proposal)\.[a-f0-9]{24}$/',
                $relation->id,
            );
        }
    }

    public function testProjectionIsDeterministicAcrossOwnerInputOrder(): void
    {
        $projector = new LearningLineageProjector();
        $findingA = $this->finding('finding.a', ['proposal.2', 'proposal.1']);
        $findingB = $this->finding('finding.b', []);
        $proposal1 = $this->proposal('proposal.1');
        $proposal2 = $this->proposal('proposal.2', supersedes: ['proposal.1'], sourceFindings: ['finding.a']);
        $note = $this->note('learning-note.1', ['finding.a'], ['proposal.2']);

        $first = $projector->project([$findingA, $findingB], [$proposal1, $proposal2], [$note]);
        $second = $projector->project([$findingB, $findingA], [$proposal2, $proposal1], [$note]);

        self::assertSame(
            array_map(static fn (GraphRelation $relation): array => $relation->toArray(), $first),
            array_map(static fn (GraphRelation $relation): array => $relation->toArray(), $second),
        );
    }

    /** @param list<string> $proposalIds */
    private function finding(string $id, array $proposalIds): FindingProjection
    {
        return new FindingProjection(
            id: $id,
            status: 'validated',
            taskId: 'TASK-1',
            session: 'session-1',
            createdAt: '2026-09-07T00:00:00+00:00',
            observation: 'Observed behaviour.',
            validatedConclusion: 'Validated conclusion.',
            scope: [],
            evidence: [],
            proposalIds: $proposalIds,
        );
    }

    /**
     * @param list<string> $supersedes
     * @param list<string> $conflicts
     * @param list<string> $sourceFindings
     */
    private function proposal(
        string $id,
        array $supersedes = [],
        array $conflicts = [],
        ?string $corrects = null,
        array $sourceFindings = [],
    ): ProposalProjection {
        return new ProposalProjection(
            id: $id,
            status: 'candidate',
            createdAt: '2026-09-07T00:00:00+00:00',
            action: 'add_memory',
            targetType: null,
            target: null,
            scope: [],
            sourceFindingIds: $sourceFindings,
            sourceTaskIds: ['TASK-1'],
            proposedChange: null,
            reason: 'Reason.',
            boundary: null,
            validation: [],
            proposedBy: 'test',
            approvedBy: null,
            approvedAt: null,
            supersedesProposalIds: $supersedes,
            conflictsWithProposalIds: $conflicts,
            correctsProposalId: $corrects,
        );
    }

    /**
     * @param list<string> $sourceFindings
     * @param list<string> $sourceProposals
     */
    private function note(string $id, array $sourceFindings, array $sourceProposals): LearningNoteProjection
    {
        return new LearningNoteProjection(
            id: $id,
            patternKey: 'pattern.test',
            status: LearningNoteStatus::ACTIVE,
            scope: [],
            tags: [],
            sourceFindings: $sourceFindings,
            sourceProposals: $sourceProposals,
            validationCase: new ValidationCase('Given.', 'When.', 'Then.'),
            content: new LearningNoteContent(
                title: 'Title',
                context: 'Context',
                guidance: 'Guidance',
                whyItWorks: 'Why.',
                whenToApply: 'When.',
                whenNotToApply: 'When not.',
                verification: 'Verify.',
            ),
            digest: 'sha256:test',
            evidenceState: LearningNoteEvidenceState::CURRENT,
        );
    }
}
