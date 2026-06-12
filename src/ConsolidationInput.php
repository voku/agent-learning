<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Domain object encapsulating all inputs to a consolidation run.
 */
final readonly class ConsolidationInput
{
    /**
     * @param FindingSelection           $selection
     * @param list<Finding>              $findings
     * @param list<ActiveGuidance>        $activeGuidance
     * @param list<RejectedGuidance>      $rejectedGuidance
     * @param list<array<string, mixed>> $outcomes
     */
    public function __construct(
        public FindingSelection $selection,
        public array $findings,
        public array $activeGuidance,
        public array $rejectedGuidance,
        public array $outcomes = [],
    ) {
    }

    /**
     * Convert the input to a raw array format while validating size and quantity limits.
     *
     * @param int $maxFindings
     * @param int $maxGuidanceItems
     * @param int $maxRejectedItems
     * @param int $maxBytesPerRecord
     * @param int $maxTotalInputBytes
     * @return array{selection: array<string, mixed>, findings: list<array<string, mixed>>, active_guidance: list<array<string, mixed>>, rejected_guidance: list<array<string, mixed>>, outcomes?: list<array<string, mixed>>}
     * @throws ValidationException
     */
    public function toArray(
        int $maxFindings = 50,
        int $maxGuidanceItems = 100,
        int $maxRejectedItems = 100,
        int $maxBytesPerRecord = 1048576, // 1MB
        int $maxTotalInputBytes = 5242880 // 5MB
    ): array {
        if (count($this->findings) > $maxFindings) {
            throw new ValidationException('', null, null, sprintf('findings limit exceeded: %d (max: %d)', count($this->findings), $maxFindings));
        }
        if (count($this->activeGuidance) > $maxGuidanceItems) {
            throw new ValidationException('', null, null, sprintf('active guidance limit exceeded: %d (max: %d)', count($this->activeGuidance), $maxGuidanceItems));
        }
        if (count($this->rejectedGuidance) > $maxRejectedItems) {
            throw new ValidationException('', null, null, sprintf('rejected guidance limit exceeded: %d (max: %d)', count($this->rejectedGuidance), $maxRejectedItems));
        }

        $findingsData = [];
        foreach ($this->findings as $finding) {
            $data = $finding->raw;
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > $maxBytesPerRecord) {
                throw new ValidationException('', null, $finding->id, sprintf('finding record %s size %d bytes exceeds limit %d bytes', $finding->id, strlen($encoded), $maxBytesPerRecord));
            }
            $findingsData[] = $data;
        }

        $activeGuidanceData = [];
        foreach ($this->activeGuidance as $guidance) {
            $data = [
                'id' => $guidance->id,
                'type' => $guidance->type->value,
                'source' => $guidance->source,
                'scope' => $guidance->scope,
                'content' => $guidance->content,
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > $maxBytesPerRecord) {
                throw new ValidationException('', null, $guidance->id, sprintf('active guidance record %s size %d bytes exceeds limit %d bytes', $guidance->id, strlen($encoded), $maxBytesPerRecord));
            }
            $activeGuidanceData[] = $data;
        }

        $rejectedGuidanceData = [];
        foreach ($this->rejectedGuidance as $rg) {
            $data = [
                'rejection_id' => $rg->id,
                'rejection_reason' => $rg->rejectionReason,
                'proposal' => $rg->proposal->raw,
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > $maxBytesPerRecord) {
                throw new ValidationException('', null, $rg->id, sprintf('rejected guidance record %s size %d bytes exceeds limit %d bytes', $rg->id, strlen($encoded), $maxBytesPerRecord));
            }
            $rejectedGuidanceData[] = $data;
        }

        $selectionData = [
            'label' => $this->selection->label(),
            'finding_ids' => $this->selection->findingIds,
            'task_ids' => $this->selection->taskIds,
            'scopes' => $this->selection->scopes,
        ];

        $result = [
            'selection' => $selectionData,
            'findings' => $findingsData,
            'active_guidance' => $activeGuidanceData,
            'rejected_guidance' => $rejectedGuidanceData,
        ];

        if ($this->outcomes !== []) {
            $result['outcomes'] = $this->outcomes;
        }

        $totalEncoded = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($totalEncoded) > $maxTotalInputBytes) {
            throw new ValidationException('', null, null, sprintf('total consolidation input size %d bytes exceeds limit %d bytes', strlen($totalEncoded), $maxTotalInputBytes));
        }

        return $result;
    }
}
