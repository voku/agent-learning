<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ReplacementCandidatePolicy
{
    /**
     * @param array<string, Proposal> $proposalsById
     * @param array<string, Finding> $findingsById
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @return list<EvolutionDecision>
     */
    public function evaluate(array $proposalsById, array $findingsById, array $outcomeEvents = []): array
    {
        $proposals = array_values($proposalsById);
        usort($proposals, static fn (Proposal $a, Proposal $b): int => $a->id <=> $b->id);
        $decisions = [];

        foreach ($proposals as $oldProposal) {
            if ($oldProposal->status !== ProposalStatus::APPLIED || $oldProposal->new === null || trim($oldProposal->new) === '') {
                continue;
            }
            foreach ($proposals as $successor) {
                if (!$this->isActiveReplace($successor) || !$this->sameTarget($oldProposal, $successor)) {
                    continue;
                }
                $sameWording = $successor->old !== null
                    && $this->normalise($successor->old) === $this->normalise($oldProposal->new);
                $explicitSuccessor = ($successor->raw['supersedes_proposal_id'] ?? null) === $oldProposal->id;
                $harmfulCorrected = $this->hasHarmfulOutcome($oldProposal->id, $outcomeEvents)
                    && ($successor->raw['corrects_proposal_id'] ?? null) === $oldProposal->id;
                $narrowerScope = $sameWording && $this->strictSubset($successor->scope, $oldProposal->scope);
                $newerValidatedLineage = $this->hasNewerValidatedLineage($oldProposal, $successor, $findingsById);
                if (!$sameWording && !$explicitSuccessor && !$harmfulCorrected && !$newerValidatedLineage) {
                    continue;
                }

                $reason = match (true) {
                    $harmfulCorrected => 'Harmful outcome evidence is tied to a concrete corrected successor proposal.',
                    $newerValidatedLineage => 'A newer validated finding explicitly supersedes the source finding and supports incompatible replacement wording.',
                    $narrowerScope => 'An approved or applied REPLACE proposal explicitly replaces active wording with a narrower supported scope.',
                    default => 'An approved or applied REPLACE proposal explicitly supersedes the wording of applied guidance.',
                };
                $decisions[] = $this->decision($oldProposal, $successor, $findingsById, $reason, $harmfulCorrected ? $outcomeEvents : []);
            }
        }

        return $decisions;
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    }

    private function isActiveReplace(Proposal $proposal): bool
    {
        return $proposal->action === Action::REPLACE
            && in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true)
            && $proposal->new !== null
            && trim($proposal->new) !== '';
    }

    private function sameTarget(Proposal $left, Proposal $right): bool
    {
        return $right->createdAt > $left->createdAt
            && $right->targetType === $left->targetType
            && $right->target === $left->target;
    }

    /** @param array<string, Finding> $findingsById */
    private function hasNewerValidatedLineage(Proposal $oldProposal, Proposal $successor, array $findingsById): bool
    {
        if ($oldProposal->patternKey === null || $oldProposal->patternKey !== $successor->patternKey || $oldProposal->new === $successor->new) {
            return false;
        }
        foreach ($successor->sourceFindings as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if (!$finding instanceof Finding || $finding->status !== FindingStatus::VALIDATED || $finding->createdAt <= $oldProposal->createdAt) {
                continue;
            }
            $supersedes = $finding->raw['supersedes_findings'] ?? [];
            if (is_array($supersedes) && array_intersect($oldProposal->sourceFindings, $supersedes) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<GuidanceOutcomeEvent> $outcomeEvents */
    private function hasHarmfulOutcome(string $proposalId, array $outcomeEvents): bool
    {
        foreach ($outcomeEvents as $event) {
            if ($event->guidanceId === $proposalId && $event->outcome === OutcomeValue::HARMFUL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $subset
     * @param list<string> $superset
     */
    private function strictSubset(array $subset, array $superset): bool
    {
        return $subset !== [] && count($subset) < count($superset) && array_diff($subset, $superset) === [];
    }

    /**
     * @param array<string, Finding> $findingsById
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     */
    private function decision(Proposal $oldProposal, Proposal $successor, array $findingsById, string $reason, array $outcomeEvents): EvolutionDecision
    {
        $tier = GuidanceType::tryFrom((string)$oldProposal->targetType) ?? GuidanceType::MEMORY;
        $sourceFindings = $successor->sourceFindings;
        sort($sourceFindings);
        $taskIds = [];
        foreach ($sourceFindings as $findingId) {
            if (isset($findingsById[$findingId])) {
                $taskIds[$findingsById[$findingId]->taskId] = true;
            }
        }
        $evidenceIds = ['proposal:' . $successor->id];
        foreach ($outcomeEvents as $outcomeEvent) {
            if ($outcomeEvent->guidanceId === $oldProposal->id && $outcomeEvent->outcome === OutcomeValue::HARMFUL) {
                $evidenceIds[] = $outcomeEvent->id;
            }
        }
        sort($evidenceIds);
        $independentTaskIds = array_keys($taskIds);
        sort($independentTaskIds);
        $scope = $successor->scope;
        sort($scope);
        $validation = $successor->validation;
        sort($validation);

        return new EvolutionDecision(
            EvolutionDecisionType::REPLACEMENT_CANDIDATE,
            $oldProposal->id,
            $tier,
            $tier,
            $evidenceIds,
            $independentTaskIds,
            $reason,
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
    }
}
