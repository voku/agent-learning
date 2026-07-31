<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ReplacementCandidatePolicy
{
    /**
     * @param array<string, Proposal> $proposalsById
     * @param array<string, Finding> $findingsById
     * @return list<EvolutionDecision>
     */
    public function evaluate(array $proposalsById, array $findingsById): array
    {
        $proposals = array_values($proposalsById);
        usort($proposals, static fn (Proposal $a, Proposal $b): int => $a->id <=> $b->id);
        $decisions = [];

        foreach ($proposals as $oldProposal) {
            if ($oldProposal->status !== ProposalStatus::APPLIED || $oldProposal->new === null || trim($oldProposal->new) === '') {
                continue;
            }
            foreach ($proposals as $successor) {
                if (
                    $successor->action !== Action::REPLACE
                    || !in_array($successor->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true)
                    || $successor->old === null
                    || $successor->new === null
                    || $successor->createdAt <= $oldProposal->createdAt
                    || $successor->targetType !== $oldProposal->targetType
                    || $successor->target !== $oldProposal->target
                    || $this->normalise($successor->old) !== $this->normalise($oldProposal->new)
                ) {
                    continue;
                }

                $tier = GuidanceType::tryFrom((string)$oldProposal->targetType) ?? GuidanceType::MEMORY;
                $sourceFindings = $successor->sourceFindings;
                sort($sourceFindings);
                $taskIds = [];
                foreach ($sourceFindings as $findingId) {
                    if (isset($findingsById[$findingId])) {
                        $taskIds[$findingsById[$findingId]->taskId] = true;
                    }
                }
                $independentTaskIds = array_keys($taskIds);
                sort($independentTaskIds);
                $scope = $successor->scope;
                sort($scope);
                $validation = $successor->validation;
                sort($validation);
                $decisions[] = new EvolutionDecision(
                    EvolutionDecisionType::REPLACEMENT_CANDIDATE,
                    $oldProposal->id,
                    $tier,
                    $tier,
                    ['proposal:' . $successor->id],
                    $independentTaskIds,
                    'An approved or applied REPLACE proposal explicitly supersedes the wording of applied guidance.',
                    'Human review must verify that the successor is the correct canonical wording and scope.',
                    $scope,
                    $validation,
                    $sourceFindings,
                    Action::REPLACE,
                    $oldProposal->new,
                    $successor->new,
                    [
                        'target' => $oldProposal->target,
                        'pattern_key' => $successor->patternKey,
                        'replaces_proposal_id' => $oldProposal->id,
                        'successor_proposal_id' => $successor->id,
                    ],
                );
                break;
            }
        }

        return $decisions;
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    }
}
