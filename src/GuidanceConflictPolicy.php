<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class GuidanceConflictPolicy
{
    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @return list<EvolutionDecision>
     */
    public function evaluate(array $findingsById, array $proposalsById): array
    {
        $decisions = $this->findingConflicts($findingsById);
        foreach ($this->proposalConflicts($proposalsById, $findingsById) as $decision) {
            $decisions[] = $decision;
        }
        foreach ($this->lineageConflicts($findingsById, $proposalsById) as $decision) {
            $decisions[] = $decision;
        }
        foreach ($this->duplicateGuidanceConflicts($proposalsById, $findingsById) as $decision) {
            $decisions[] = $decision;
        }
        usort($decisions, static fn (EvolutionDecision $a, EvolutionDecision $b): int => [$a->guidanceId, $a->stableKey()] <=> [$b->guidanceId, $b->stableKey()]);

        return $decisions;
    }

    /**
     * @param array<string, Finding> $findingsById
     * @return list<EvolutionDecision>
     */
    private function findingConflicts(array $findingsById): array
    {
        $byPattern = [];
        foreach ($findingsById as $finding) {
            if (
                !in_array($finding->status, [FindingStatus::VALIDATED, FindingStatus::CONSOLIDATED], true)
                || $finding->patternKey === null
                || $finding->validatedConclusion === null
            ) {
                continue;
            }
            $byPattern[$finding->patternKey][] = $finding;
        }
        ksort($byPattern);
        $decisions = [];
        foreach ($byPattern as $patternKey => $findings) {
            usort($findings, static fn (Finding $a, Finding $b): int => $a->id <=> $b->id);
            for ($first = 0, $count = count($findings); $first < $count; $first++) {
                for ($second = $first + 1; $second < $count; $second++) {
                    if (!$this->explicitlyConflicts($findings[$first], $findings[$second])) {
                        continue;
                    }
                    $sourceFindings = [$findings[$first]->id, $findings[$second]->id];
                    $taskIds = [$findings[$first]->taskId, $findings[$second]->taskId];
                    sort($taskIds);
                    $scope = array_values(array_unique(array_merge($findings[$first]->scope, $findings[$second]->scope)));
                    sort($scope);
                    $decisions[] = new EvolutionDecision(
                        EvolutionDecisionType::CONFLICT,
                        'conflict.finding.' . $patternKey,
                        GuidanceType::MEMORY,
                        null,
                        ['finding:' . $findings[$first]->id, 'finding:' . $findings[$second]->id],
                        $taskIds,
                    'Validated findings with the same pattern key explicitly declare incompatible conclusions.',
                        'The evidence establishes a conflict, not which conclusion should become canonical.',
                        $scope,
                        ['manual review required'],
                        $sourceFindings,
                        Action::NO_DURABLE_LEARNING,
                        proposalExtras: ['pattern_key' => $patternKey],
                    );
                }
            }
        }

        return $decisions;
    }

    /**
     * @param array<string, Proposal> $proposalsById
     * @param array<string, Finding> $findingsById
     * @return list<EvolutionDecision>
     */
    private function proposalConflicts(array $proposalsById, array $findingsById): array
    {
        $proposals = array_values(array_filter($proposalsById, static fn (Proposal $proposal): bool => in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true) && $proposal->patternKey !== null && $proposal->new !== null));
        usort($proposals, static fn (Proposal $a, Proposal $b): int => $a->id <=> $b->id);
        $decisions = [];
        for ($first = 0, $count = count($proposals); $first < $count; $first++) {
            for ($second = $first + 1; $second < $count; $second++) {
                $left = $proposals[$first];
                $right = $proposals[$second];
                if (
                    $left->patternKey !== $right->patternKey
                    || $left->targetType !== $right->targetType
                    || $left->target !== $right->target
                    || !$this->scopeOverlaps($left->scope, $right->scope)
                    || !$this->explicitlyConflicts($left, $right)
                ) {
                    continue;
                }
                $sourceFindings = array_values(array_unique(array_merge($left->sourceFindings, $right->sourceFindings)));
                sort($sourceFindings);
                $taskIds = [];
                foreach ($sourceFindings as $findingId) {
                    if (isset($findingsById[$findingId])) {
                        $taskIds[$findingsById[$findingId]->taskId] = true;
                    }
                }
                $taskIds = array_keys($taskIds);
                sort($taskIds);
                $scope = array_values(array_unique(array_merge($left->scope, $right->scope)));
                sort($scope);
                $tier = GuidanceType::tryFrom((string)$left->targetType) ?? GuidanceType::MEMORY;
                $decisions[] = new EvolutionDecision(
                    EvolutionDecisionType::CONFLICT,
                    'conflict.proposal.' . $left->id . '.' . $right->id,
                    $tier,
                    null,
                    ['proposal:' . $left->id, 'proposal:' . $right->id],
                    $taskIds,
                    'Active guidance with overlapping scope and target explicitly declares incompatible wording.',
                    'A maintainer must choose canonical wording, scope narrowing, replacement, or no action.',
                    $scope,
                    ['manual review required'],
                    $sourceFindings,
                    Action::NO_DURABLE_LEARNING,
                    proposalExtras: ['pattern_key' => $left->patternKey],
                );
            }
        }

        return $decisions;
    }

    /**
     * A later invalidated or superseded finding can contradict active guidance only when
     * it names that proposal. This deliberately avoids inferring contradiction from prose.
     *
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @return list<EvolutionDecision>
     */
    private function lineageConflicts(array $findingsById, array $proposalsById): array
    {
        $decisions = [];
        foreach ($findingsById as $finding) {
            if (!in_array($finding->status, [FindingStatus::INVALIDATED, FindingStatus::SUPERSEDED], true)) {
                continue;
            }
            $proposalId = $finding->raw['contradicts_proposal_id'] ?? null;
            $proposal = is_string($proposalId) ? ($proposalsById[$proposalId] ?? null) : null;
            if (!$proposal instanceof Proposal || !in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true)) {
                continue;
            }
            $tier = GuidanceType::tryFrom((string)$proposal->targetType) ?? GuidanceType::MEMORY;
            $scope = $proposal->scope;
            sort($scope);
            $decisions[] = new EvolutionDecision(
                EvolutionDecisionType::CONFLICT,
                'conflict.lineage.' . $proposal->id . '.' . $finding->id,
                $tier,
                null,
                ['finding:' . $finding->id, 'proposal:' . $proposal->id],
                [$finding->taskId],
                'A later invalidated or superseded finding explicitly contradicts active guidance.',
                'The lineage establishes review urgency, not whether to replace, retire, narrow, or retain the guidance.',
                $scope,
                ['manual review required'],
                array_values(array_unique(array_merge([$finding->id], $proposal->sourceFindings))),
                Action::NO_DURABLE_LEARNING,
                proposalExtras: ['pattern_key' => $proposal->patternKey, 'contradicted_proposal_id' => $proposal->id],
            );
        }

        return $decisions;
    }

    /**
     * Exact wording, overlapping scope, and shared evidence are a bounded duplicate
     * identity. Different tiers are reported because a human must choose the canonical
     * home; the policy never moves or retires either guidance item.
     *
     * @param array<string, Proposal> $proposalsById
     * @param array<string, Finding> $findingsById
     * @return list<EvolutionDecision>
     */
    private function duplicateGuidanceConflicts(array $proposalsById, array $findingsById): array
    {
        $proposals = array_values(array_filter($proposalsById, static fn (Proposal $proposal): bool => in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true) && $proposal->new !== null && trim($proposal->new) !== ''));
        usort($proposals, static fn (Proposal $a, Proposal $b): int => $a->id <=> $b->id);
        $decisions = [];
        for ($first = 0, $count = count($proposals); $first < $count; $first++) {
            for ($second = $first + 1; $second < $count; $second++) {
                $left = $proposals[$first];
                $right = $proposals[$second];
                if (
                    $left->targetType === $right->targetType
                    || $this->normalise((string)$left->new) !== $this->normalise((string)$right->new)
                    || !$this->scopeOverlaps($left->scope, $right->scope)
                    || array_intersect($left->sourceFindings, $right->sourceFindings) === []
                ) {
                    continue;
                }
                $sourceFindings = array_values(array_unique(array_merge($left->sourceFindings, $right->sourceFindings)));
                sort($sourceFindings);
                $taskIds = $this->taskIds($sourceFindings, $findingsById);
                $scope = array_values(array_unique(array_merge($left->scope, $right->scope)));
                sort($scope);
                $sourceTier = GuidanceType::tryFrom((string)$left->targetType) ?? GuidanceType::MEMORY;
                $targetTier = GuidanceType::tryFrom((string)$right->targetType) ?? GuidanceType::MEMORY;
                $decisions[] = new EvolutionDecision(
                    EvolutionDecisionType::CONFLICT,
                    'conflict.duplicate.' . $left->id . '.' . $right->id,
                    $sourceTier,
                    null,
                    ['proposal:' . $left->id, 'proposal:' . $right->id],
                    $taskIds,
                    'Active guidance has exact normalized wording, overlapping scope, and shared source findings in different ownership tiers.',
                    'A maintainer must choose a canonical tier or document why both copies are intentionally active.',
                    $scope,
                    ['manual review required'],
                    $sourceFindings,
                    Action::NO_DURABLE_LEARNING,
                    proposalExtras: [
                        'pattern_key' => $left->patternKey ?? $right->patternKey,
                        'duplicate_of_proposal_id' => $right->id,
                        'other_tier' => $targetTier->value,
                    ],
                );
            }
        }

        return $decisions;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function scopeOverlaps(array $left, array $right): bool
    {
        return array_intersect($left, $right) !== [];
    }

    private function explicitlyConflicts(Finding|Proposal $left, Finding|Proposal $right): bool
    {
        foreach ([$left, $right] as $candidate) {
            $declared = $candidate->raw['conflicts_with'] ?? null;
            if (!is_array($declared) || !in_array($candidate === $left ? $right->id : $left->id, $declared, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<string> $findingIds
     * @param array<string, Finding> $findingsById
     * @return list<string>
     */
    private function taskIds(array $findingIds, array $findingsById): array
    {
        $taskIds = [];
        foreach ($findingIds as $findingId) {
            if (isset($findingsById[$findingId])) {
                $taskIds[$findingsById[$findingId]->taskId] = true;
            }
        }
        /** @var list<string> $result */
        $result = array_keys($taskIds);
        sort($result);

        return $result;
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    }
}
