<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\GuidanceProjection;
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\Catalog\TaskLearningProjection;

/**
 * Stable read-only query boundary over Learning-owned state.
 *
 * The catalog delegates parsing, lifecycle validation, redaction and history
 * integrity to existing owner repositories. It performs no state transition and
 * never infers task lineage from filenames or content.
 */
final readonly class LearningCatalog
{
    public function __construct(
        private string $root,
        private LearningRepositoryValidator $validator = new LearningRepositoryValidator(),
        private GuidanceUsageProjector $usageProjector = new GuidanceUsageProjector(),
    ) {
    }

    public function overview(): LearningOverview
    {
        $state = $this->state();
        $findingCounts = array_fill_keys(array_map(static fn (FindingStatus $status): string => $status->value, FindingStatus::cases()), 0);
        $proposalCounts = array_fill_keys(array_map(static fn (ProposalStatus $status): string => $status->value, ProposalStatus::cases()), 0);
        $guidanceCounts = array_fill_keys(array_map(static fn (GuidanceType $type): string => $type->value, GuidanceType::cases()), 0);
        $findingAttention = [];
        $proposalAttention = [];
        $durable = [];
        $recentFindings = [];
        $recentProposals = [];

        foreach ($state->findingsById as $finding) {
            ++$findingCounts[$finding->status->value];
            $recentFindings[] = [$finding->createdAt, $finding->id];
            if (in_array($finding->status, [FindingStatus::CANDIDATE, FindingStatus::VALIDATED], true)) {
                $findingAttention[] = $finding->id;
            }
        }
        foreach ($state->proposalsById as $proposal) {
            ++$proposalCounts[$proposal->status->value];
            $recentProposals[] = [$proposal->createdAt, $proposal->id];
            if ($proposal->status === ProposalStatus::CANDIDATE) {
                $proposalAttention[] = $proposal->id;
            }
            if (!in_array($proposal->status, [ProposalStatus::APPROVED, ProposalStatus::APPLIED], true)) {
                continue;
            }
            $type = $proposal->targetType === null ? null : GuidanceType::tryFrom($proposal->targetType);
            if ($type === null) {
                continue;
            }
            ++$guidanceCounts[$type->value];
            $durable[] = [$proposal->createdAt, $proposal->id];
        }

        sort($findingAttention, SORT_STRING);
        sort($proposalAttention, SORT_STRING);
        usort($durable, self::recentFirst(...));
        usort($recentFindings, self::recentFirst(...));
        usort($recentProposals, self::recentFirst(...));

        return new LearningOverview(
            $findingCounts,
            $proposalCounts,
            $guidanceCounts,
            $findingAttention,
            $proposalAttention,
            self::idsOf($durable),
            self::idsOf($recentFindings),
            self::idsOf($recentProposals),
        );
    }

    public function finding(string $findingId): ?FindingProjection
    {
        $state = $this->state();
        $finding = $state->findingsById[$findingId] ?? null;
        if (!$finding instanceof Finding) {
            return null;
        }

        return $this->findingProjection($finding, $state->proposalsById);
    }

    public function proposal(string $proposalId): ?ProposalProjection
    {
        $state = $this->state();
        $proposal = $state->proposalsById[$proposalId] ?? null;
        if (!$proposal instanceof Proposal) {
            return null;
        }

        return $this->proposalProjection($proposal, $state->findingsById);
    }

    public function guidance(string $guidanceId): ?GuidanceProjection
    {
        $state = $this->state();
        $proposal = $state->proposalsById[$guidanceId] ?? null;
        if (!$proposal instanceof Proposal) {
            return null;
        }
        $usage = $this->usageProjector->project($state->recallSelectionEvents, $state->guidanceOutcomeEvents);

        return GuidanceProjection::fromProposal($proposal, $usage[$guidanceId] ?? null);
    }

    public function forTask(string $taskId): TaskLearningProjection
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new ValidationException($this->root, null, null, 'task id must not be empty');
        }
        $state = $this->state();
        $taskFindings = array_filter(
            $state->findingsById,
            static fn (Finding $finding): bool => $finding->taskId === $taskId,
        );
        ksort($taskFindings, SORT_STRING);
        $findingIds = array_fill_keys(array_keys($taskFindings), true);
        $taskProposals = array_filter(
            $state->proposalsById,
            static function (Proposal $proposal) use ($findingIds): bool {
                foreach ($proposal->sourceFindings as $findingId) {
                    if (isset($findingIds[$findingId])) {
                        return true;
                    }
                }

                return false;
            },
        );
        ksort($taskProposals, SORT_STRING);
        $usage = $this->usageProjector->project($state->recallSelectionEvents, $state->guidanceOutcomeEvents);

        $findings = [];
        foreach ($taskFindings as $finding) {
            $findings[] = $this->findingProjection($finding, $state->proposalsById);
        }
        $proposals = [];
        $guidance = [];
        foreach ($taskProposals as $proposal) {
            $proposals[] = $this->proposalProjection($proposal, $state->findingsById);
            $projected = GuidanceProjection::fromProposal($proposal, $usage[$proposal->id] ?? null);
            if ($projected !== null) {
                $guidance[] = $projected;
            }
        }
        $outcomeIds = [];
        foreach ($state->outcomes as $outcome) {
            if (($outcome['task_id'] ?? null) !== $taskId || !is_string($outcome['id'] ?? null)) {
                continue;
            }
            $outcomeIds[] = $outcome['id'];
        }
        sort($outcomeIds, SORT_STRING);

        return new TaskLearningProjection($taskId, $findings, $proposals, $guidance, $outcomeIds);
    }

    /**
     * @param array{0: string, 1: string} $left
     * @param array{0: string, 1: string} $right
     */
    private static function recentFirst(array $left, array $right): int
    {
        return [$right[0], $right[1]] <=> [$left[0], $left[1]];
    }

    /**
     * @param list<array{0: string, 1: string}> $items
     * @return list<string>
     */
    private static function idsOf(array $items): array
    {
        return array_map(
            static fn (array $item): string => $item[1],
            array_slice($items, 0, 20),
        );
    }

    private function state(): LearningRepositoryValidationResult
    {
        return $this->validator->validate($this->root);
    }

    /** @param array<string, Proposal> $proposalsById */
    private function findingProjection(Finding $finding, array $proposalsById): FindingProjection
    {
        $proposalIds = [];
        foreach ($proposalsById as $proposal) {
            if (in_array($finding->id, $proposal->sourceFindings, true)) {
                $proposalIds[] = $proposal->id;
            }
        }

        return FindingProjection::fromFinding($finding, $proposalIds);
    }

    /** @param array<string, Finding> $findingsById */
    private function proposalProjection(Proposal $proposal, array $findingsById): ProposalProjection
    {
        $taskIds = [];
        foreach ($proposal->sourceFindings as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if ($finding instanceof Finding) {
                $taskIds[] = $finding->taskId;
            }
        }
        $taskIds = array_values(array_unique($taskIds));

        return ProposalProjection::fromProposal($proposal, $taskIds);
    }
}
