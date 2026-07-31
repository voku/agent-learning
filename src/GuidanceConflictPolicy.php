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
}
