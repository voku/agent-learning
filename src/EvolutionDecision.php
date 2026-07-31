<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class EvolutionDecision
{
    /**
     * @param list<string> $evidenceEventIds
     * @param list<string> $independentTaskIds
     * @param list<string> $proposedScope
     * @param list<string> $validationRequirements
     * @param list<string> $sourceFindings
     * @param array<string, mixed> $proposalExtras
     */
    public function __construct(
        public EvolutionDecisionType $type,
        public string $guidanceId,
        public GuidanceType $sourceTier,
        public ?GuidanceType $targetTier,
        public array $evidenceEventIds,
        public array $independentTaskIds,
        public string $reason,
        public string $remainingUncertainty,
        public array $proposedScope,
        public array $validationRequirements,
        public array $sourceFindings = [],
        public ?Action $proposalAction = null,
        public ?string $oldText = null,
        public ?string $newText = null,
        public array $proposalExtras = [],
    ) {
    }

    public function stableKey(): string
    {
        $proposalExtras = $this->proposalExtras;
        ksort($proposalExtras);
        $payload = [
            'type' => $this->type->value,
            'guidance_id' => $this->guidanceId,
            'source_tier' => $this->sourceTier->value,
            'target_tier' => $this->targetTier?->value,
            'evidence_event_ids' => $this->sorted($this->evidenceEventIds),
            'independent_task_ids' => $this->sorted($this->independentTaskIds),
            'proposed_scope' => $this->sorted($this->proposedScope),
            'validation_requirements' => $this->sorted($this->validationRequirements),
            'source_findings' => $this->sorted($this->sourceFindings),
            'proposal_action' => $this->proposalAction?->value,
            'old_text' => $this->oldText,
            'new_text' => $this->newText,
            'proposal_extras' => $proposalExtras,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

}
