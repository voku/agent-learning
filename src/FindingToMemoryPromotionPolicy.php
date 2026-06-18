<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class FindingToMemoryPromotionPolicy
{
    public function __construct(
        private readonly PromotionThresholds $thresholds = new PromotionThresholds(),
    ) {
    }

    /**
     * @param array<string, Finding> $findingsById
     * @return list<EvolutionDecision>
     */
    public function evaluate(array $findingsById): array
    {
        $groups = [];
        foreach ($findingsById as $finding) {
            $key = $finding->patternKey ?? implode('|', $finding->scope);
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $finding;
        }
        ksort($groups);

        $decisions = [];
        foreach ($groups as $key => $findings) {
            $taskIds = [];
            $sourceFindings = [];
            $scope = [];
            $criticalJustification = false;
            foreach ($findings as $finding) {
                $taskIds[$finding->taskId] = true;
                $sourceFindings[] = $finding->id;
                foreach ($finding->scope as $scopeItem) {
                    $scope[$scopeItem] = true;
                }
                $criticalJustification = $criticalJustification || is_string($finding->raw['critical_incident_justification'] ?? null);
            }
            $distinctTasks = array_keys($taskIds);
            sort($distinctTasks);
            $sourceFindings = array_values(array_unique($sourceFindings));
            sort($sourceFindings);
            $scopeList = array_keys($scope);
            sort($scopeList);

            if (
                count($sourceFindings) < $this->thresholds->findingToMemoryValidatedFindings
                ||
                (count($distinctTasks) < 2 && !$criticalJustification)
                ||
                $scopeList === []
            ) {
                continue;
            }

            $memoryText = $findings[0]->validatedConclusion ?? $findings[0]->observation;
            $decisions[] = new EvolutionDecision(
                EvolutionDecisionType::PROMOTION_CANDIDATE,
                'finding-group.' . $key,
                GuidanceType::MEMORY,
                GuidanceType::MEMORY,
                [],
                $distinctTasks,
                'Validated findings recur across independent tasks and have explicit repository scope.',
                'A reviewer must confirm the wording is durable memory rather than task-specific advice.',
                $scopeList,
                ['review source findings and confirm memory wording'],
                $sourceFindings,
                Action::ADD,
                null,
                'Remember this recurring repository pattern: ' . $memoryText,
            );
        }

        return $decisions;
    }
}
