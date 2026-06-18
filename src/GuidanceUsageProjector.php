<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class GuidanceUsageProjector
{
    /**
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @return array<string, GuidanceUsageSummary>
     */
    public function project(array $selectionEvents, array $outcomeEvents): array
    {
        $builders = [];
        $selectionByCompilationGuidance = [];

        foreach ($selectionEvents as $event) {
            $key = $event->compilationId . "\0" . $event->guidanceId;
            $selectionByCompilationGuidance[$key] = $event;
            $builder = $builders[$event->guidanceId] ?? $this->newBuilder($event->guidanceId, $event->guidanceType);
            $builder['guidance_type'] = $event->guidanceType;
            $builder['evidence_event_ids'][$event->id] = true;
            if ($event->eligible) {
                $builder['eligible_count']++;
                $builder['last_eligible_at'] = $this->later($builder['last_eligible_at'], $event->recordedAt);
            }
            if ($event->selected) {
                $builder['selected_count']++;
                $builder['last_selected_at'] = $this->later($builder['last_selected_at'], $event->recordedAt);
                $builder['task_ids'][$event->taskId] = true;
            }
            $builders[$event->guidanceId] = $builder;
        }

        foreach ($outcomeEvents as $event) {
            $key = $event->compilationId . "\0" . $event->guidanceId;
            $selection = $selectionByCompilationGuidance[$key] ?? null;
            if (!$selection instanceof RecallSelectionEvent) {
                throw new ValidationException('history/outcomes.jsonl', null, $event->id, 'guidance outcome has no corresponding recall selection');
            }
            if ($selection->taskId !== $event->taskId) {
                throw new ValidationException('history/outcomes.jsonl', null, $event->id, 'guidance outcome task_id does not match recall selection');
            }

            $builder = $builders[$event->guidanceId] ?? $this->newBuilder($event->guidanceId, $selection->guidanceType);
            $builder['evidence_event_ids'][$event->id] = true;
            $builder['task_ids'][$event->taskId] = true;
            if ($event->applied) {
                $builder['applied_count']++;
            }
            match ($event->outcome) {
                OutcomeValue::HELPFUL => $builder['helpful_count']++,
                OutcomeValue::IRRELEVANT => $builder['irrelevant_count']++,
                OutcomeValue::HARMFUL => $builder['harmful_count']++,
                OutcomeValue::NOT_USED => $builder['not_used_count']++,
                OutcomeValue::UNKNOWN => $builder['unknown_count']++,
            };
            if ($event->outcome === OutcomeValue::HELPFUL) {
                $builder['last_helpful_at'] = $this->later($builder['last_helpful_at'], $event->recordedAt);
            }
            $builders[$event->guidanceId] = $builder;
        }

        ksort($builders);
        $summaries = [];
        foreach ($builders as $guidanceId => $builder) {
            $taskIds = array_keys($builder['task_ids']);
            sort($taskIds);
            $eventIds = array_keys($builder['evidence_event_ids']);
            sort($eventIds);
            $summaries[$guidanceId] = new GuidanceUsageSummary(
                $guidanceId,
                $builder['guidance_type'],
                $builder['eligible_count'],
                $builder['selected_count'],
                $builder['applied_count'],
                $builder['helpful_count'],
                $builder['irrelevant_count'],
                $builder['harmful_count'],
                $builder['not_used_count'],
                $builder['unknown_count'],
                0,
                0,
                count($taskIds),
                $builder['last_eligible_at'],
                $builder['last_selected_at'],
                $builder['last_helpful_at'],
                $taskIds,
                $eventIds,
            );
        }

        return $summaries;
    }

    /**
     * @return array{
     *     guidance_type: GuidanceType,
     *     eligible_count: int,
     *     selected_count: int,
     *     applied_count: int,
     *     helpful_count: int,
     *     irrelevant_count: int,
     *     harmful_count: int,
     *     not_used_count: int,
     *     unknown_count: int,
     *     task_ids: array<string, true>,
     *     evidence_event_ids: array<string, true>,
     *     last_eligible_at: string|null,
     *     last_selected_at: string|null,
     *     last_helpful_at: string|null
     * }
     */
    private function newBuilder(string $guidanceId, GuidanceType $guidanceType): array
    {
        return [
            'guidance_type' => $guidanceType,
            'eligible_count' => 0,
            'selected_count' => 0,
            'applied_count' => 0,
            'helpful_count' => 0,
            'irrelevant_count' => 0,
            'harmful_count' => 0,
            'not_used_count' => 0,
            'unknown_count' => 0,
            'task_ids' => [],
            'evidence_event_ids' => [],
            'last_eligible_at' => null,
            'last_selected_at' => null,
            'last_helpful_at' => null,
        ];
    }

    private function later(?string $current, string $candidate): string
    {
        if ($current === null || strcmp($candidate, $current) > 0) {
            return $candidate;
        }

        return $current;
    }
}
