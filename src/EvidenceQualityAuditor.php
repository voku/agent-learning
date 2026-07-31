<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;

final class EvidenceQualityAuditor
{
    /**
     * @param array<string, Finding> $findingsById
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @return list<DreamWarning>
     */
    public function audit(
        array $findingsById,
        array $selectionEvents,
        array $outcomeEvents,
        ?string $projectRoot,
        int $reviewHorizonDays,
        DateTimeImmutable $now,
    ): array {
        $warnings = [];
        $outcomesBySelection = [];
        foreach ($outcomeEvents as $event) {
            $outcomesBySelection[$event->compilationId . "\0" . $event->guidanceId] = $event;
        }

        $missingOutcomeIds = [];
        foreach ($selectionEvents as $event) {
            if (!$event->selected) {
                continue;
            }
            if (!isset($outcomesBySelection[$event->compilationId . "\0" . $event->guidanceId])) {
                $missingOutcomeIds[] = $event->id;
            }
        }
        if ($missingOutcomeIds !== []) {
            sort($missingOutcomeIds);
            $warnings[] = new DreamWarning(
                'outcome_missing',
                'Selected guidance has no explicit outcome record.',
                $this->bounded($missingOutcomeIds),
                'Record helpful, harmful, irrelevant, not_used, or unknown for each selected guidance item.',
            );
        }

        $unknownIds = [];
        foreach ($outcomeEvents as $event) {
            if ($event->outcome === OutcomeValue::UNKNOWN) {
                $unknownIds[] = $event->id;
            }
        }
        if ($unknownIds !== []) {
            sort($unknownIds);
            $warnings[] = new DreamWarning(
                'outcome_unknown',
                'Explicit unknown outcomes are tracked separately from harmful or negative outcomes.',
                $this->bounded($unknownIds),
                'Replace unknown outcomes with a specific signal when the task can be reviewed safely.',
            );
        }

        $agedFindingIds = [];
        $danglingReferenceIds = [];
        foreach ($findingsById as $finding) {
            if ($finding->status === FindingStatus::CANDIDATE || $finding->status === FindingStatus::VALIDATED) {
                $createdAt = new DateTimeImmutable($finding->createdAt);
                if ($createdAt->modify('+' . $reviewHorizonDays . ' days') < $now) {
                    $agedFindingIds[] = $finding->id;
                }
            }

            if ($projectRoot === null) {
                continue;
            }
            foreach ($finding->evidence as $index => $evidence) {
                if (($evidence['type'] ?? null) !== 'file_reference' || !is_string($evidence['path'] ?? null)) {
                    continue;
                }
                $path = $evidence['path'];
                $fullPath = str_starts_with($path, '/') ? $path : rtrim($projectRoot, '/') . '/' . ltrim($path, '/');
                if (!is_file($fullPath)) {
                    $danglingReferenceIds[] = $finding->id . ':evidence:' . $index;
                }
            }
        }
        if ($agedFindingIds !== []) {
            sort($agedFindingIds);
            $warnings[] = new DreamWarning(
                'finding_review_horizon_exceeded',
                'Candidate or validated findings have exceeded the configured review horizon.',
                $this->bounded($agedFindingIds),
                'Review the finding: validate, invalidate, supersede, consolidate, archive, or retain it with an explicit reason.',
            );
        }
        if ($danglingReferenceIds !== []) {
            sort($danglingReferenceIds);
            $warnings[] = new DreamWarning(
                'evidence_reference_unresolvable',
                'File-reference evidence no longer resolves under the supplied project root.',
                $this->bounded($danglingReferenceIds),
                'Update the evidence reference or record why the source is intentionally unavailable.',
            );
        }

        usort($warnings, static fn (DreamWarning $a, DreamWarning $b): int => $a->code <=> $b->code);

        return $warnings;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function bounded(array $ids): array
    {
        return array_slice($ids, 0, 20);
    }
}
