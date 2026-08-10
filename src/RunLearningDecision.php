<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class RunLearningDecision
{
    /**
     * @param list<non-empty-string> $findingIds
     */
    public function __construct(
        public string $runId,
        public RunLearningDecisionStatus $decision,
        public string $decidedBy,
        public string $decidedAt,
        public string $reason,
        public array $findingIds,
        public ?string $followUpRef,
        public string $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'run_learning_decision',
            'run_id' => $this->runId,
            'decision' => $this->decision->value,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'reason' => $this->reason,
            'finding_ids' => $this->findingIds,
            'follow_up_ref' => $this->followUpRef,
        ];
    }
}
