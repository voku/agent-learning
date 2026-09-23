<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;

final class EvidenceQualityAuditor
{
    /**
     * @param array<string, Finding> $findingsById
     * @param array<string, Proposal> $proposalsById
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     * @return list<DreamWarning>
     */
    public function audit(
        array $findingsById,
        array $proposalsById,
        array $selectionEvents,
        array $outcomeEvents,
        ?string $projectRoot,
        int $reviewHorizonDays,
        DateTimeImmutable $now,
    ): array {
        $warnings = [];
        // Selected-but-unjudged guidance is neutral, not missing evidence.
        // Asking for a judgement on every selected item made sessions invent
        // `not_used`/`irrelevant` rows (82% of all outcomes in one real
        // consumer) that no retirement ever relied on. Coverage stays visible
        // through the judged/selected metric instead of as a warning.
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

        $incompleteLineageIds = [];
        foreach ($proposalsById as $proposal) {
            if (!in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED, ProposalStatus::RETIRED], true)) {
                continue;
            }
            if ($proposal->sourceFindings === [] || $proposal->approvedBy === null || $proposal->approvedAt === null) {
                $incompleteLineageIds[] = $proposal->id;
                continue;
            }
            foreach ($proposal->sourceFindings as $findingId) {
                if (!isset($findingsById[$findingId])) {
                    $incompleteLineageIds[] = $proposal->id . ':source:' . $findingId;
                }
            }
        }
        if ($incompleteLineageIds !== []) {
            sort($incompleteLineageIds);
            $warnings[] = new DreamWarning(
                'guidance_lineage_incomplete',
                'Applied, approved, or retired guidance has incomplete source-finding or decision lineage.',
                $this->bounded($incompleteLineageIds),
                'Restore the source finding and recorded reviewer decision before changing active guidance.',
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
