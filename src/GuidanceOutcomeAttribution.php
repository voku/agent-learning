<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Decision-time evidence behind a guidance outcome judgement.
 *
 * "helpful" alone cannot separate "I read this and it changed my choice" from
 * "it matches what I did anyway". This records the two facts that do:
 * whether the guidance was read before the credited decision, and which other
 * sources already prescribed that decision. It is still a self-report and
 * never proves usefulness on its own; it only makes the candidate set for a
 * causal audit queryable instead of reconstructed from transcripts.
 */
final readonly class GuidanceOutcomeAttribution
{
    /**
     * @param list<GuidanceOutcomeAttributionSource> $alsoPrescribedBy
     */
    public function __construct(
        public bool $seenBeforeDecision,
        public array $alsoPrescribedBy,
    ) {
    }

    /**
     * True only when the guidance was read before the decision and no other
     * source already prescribed it: the sole shape that can support a causal
     * behavioral-value claim after an independent audit.
     */
    public function isIndependentlyAttributable(): bool
    {
        return $this->seenBeforeDecision && $this->alsoPrescribedBy === [];
    }

    /**
     * @return array{seen_before_decision: bool, also_prescribed_by: list<string>}
     */
    public function toArray(): array
    {
        return [
            'seen_before_decision' => $this->seenBeforeDecision,
            'also_prescribed_by' => array_map(
                static fn (GuidanceOutcomeAttributionSource $source): string => $source->value,
                $this->alsoPrescribedBy,
            ),
        ];
    }
}
