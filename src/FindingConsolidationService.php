<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Moves validated findings to "consolidated" once every proposal that cites them
 * has reached a terminal decision (applied, rejected, acknowledged, retired).
 *
 * A finding with no proposal, or with one still waiting for a human, stays
 * validated: it is real backlog. This only records bookkeeping that the
 * proposal transition already implied, so the backlog counts open work.
 */
final readonly class FindingConsolidationService
{
    private const array TERMINAL = [
        ProposalStatus::APPLIED,
        ProposalStatus::REJECTED,
        ProposalStatus::ACKNOWLEDGED,
        ProposalStatus::RETIRED,
    ];

    public function __construct(private FindingTransitionManager $transitions = new FindingTransitionManager())
    {
    }

    /**
     * Validated finding IDs whose proposals are all terminal, optionally narrowed to $onlyIds.
     *
     * @param list<string>|null $onlyIds
     *
     * @return list<string>
     */
    public function decidedFindingIds(string $root, ?array $onlyIds = null): array
    {
        $catalog = new LearningCatalog($root);
        $statusesByFinding = [];
        foreach ($catalog->proposals() as $proposal) {
            foreach ($proposal->sourceFindingIds as $findingId) {
                $statusesByFinding[$findingId][] = $proposal->status;
            }
        }

        $decided = [];
        foreach ($catalog->findings(FindingStatus::VALIDATED->value) as $finding) {
            if ($onlyIds !== null && !in_array($finding->id, $onlyIds, true)) {
                continue;
            }
            $statuses = $statusesByFinding[$finding->id] ?? [];
            if ($statuses === [] || array_filter($statuses, static fn (string $status): bool => !in_array(ProposalStatus::from($status), self::TERMINAL, true)) !== []) {
                continue;
            }
            $decided[] = $finding->id;
        }
        sort($decided, SORT_STRING);

        return $decided;
    }

    /**
     * @param list<string>|null $onlyIds
     *
     * @return list<string> the consolidated finding IDs
     */
    public function consolidate(string $root, string $actor, ?array $onlyIds = null): array
    {
        $ids = $this->decidedFindingIds($root, $onlyIds);
        foreach ($ids as $findingId) {
            $this->transitions->transition($root, $findingId, FindingStatus::CONSOLIDATED, $actor);
        }

        return $ids;
    }
}
