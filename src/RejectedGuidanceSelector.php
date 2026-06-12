<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Filters and selects rejected guidance based on criteria.
 */
final class RejectedGuidanceSelector
{
    /**
     * Select relevant rejected guidance records.
     *
     * @param list<RejectedGuidance> $rejectedGuidances
     * @param list<string>           $scopes
     * @param list<string>           $findingIds
     * @param string|null            $target
     * @param list<string>           $explicitProposalIds
     * @return list<RejectedGuidance>
     */
    public function select(
        array $rejectedGuidances,
        array $scopes = [],
        array $findingIds = [],
        ?string $target = null,
        array $explicitProposalIds = []
    ): array {
        $selected = [];
        foreach ($rejectedGuidances as $guidance) {
            $proposal = $guidance->proposal;

            // 1. Explicit proposal ID match
            if ($explicitProposalIds !== [] && in_array($proposal->id, $explicitProposalIds, true)) {
                $selected[] = $guidance;
                continue;
            }

            // 2. Matching target
            if ($target !== null && $proposal->target === $target) {
                $selected[] = $guidance;
                continue;
            }

            // 3. Overlapping scope
            if ($scopes !== [] && $this->scopesOverlap($proposal->scope, $scopes)) {
                $selected[] = $guidance;
                continue;
            }

            // 4. Referenced findings overlap
            if ($findingIds !== [] && array_intersect($proposal->sourceFindings, $findingIds) !== []) {
                $selected[] = $guidance;
                continue;
            }
        }

        // Sort deterministically by ID
        usort($selected, static fn(RejectedGuidance $a, RejectedGuidance $b) => strcmp($a->id, $b->id));

        return $selected;
    }

    /**
     * Check if proposal scopes overlap with query/selected scopes.
     *
     * @param list<string> $proposalScopes
     * @param list<string> $queryScopes
     */
    private function scopesOverlap(array $proposalScopes, array $queryScopes): bool
    {
        foreach ($queryScopes as $queryScope) {
            foreach ($proposalScopes as $propScope) {
                if ($propScope === '/' || $queryScope === '/') {
                    return true;
                }
                if ($propScope === $queryScope) {
                    return true;
                }
                if (str_starts_with($propScope, rtrim($queryScope, '/') . '/')) {
                    return true;
                }
                if (str_starts_with($queryScope, rtrim($propScope, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
