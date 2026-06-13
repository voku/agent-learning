<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Validates consolidation results and returns the typed ConsolidationResult.
 */
final class ConsolidationResultValidator
{
    public function __construct(
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * Validate the parsed result array.
     *
     * @param array<string, mixed> $data
     * @param array<string, Finding> $findingsById
     * @return ConsolidationResult
     * @throws ValidationException
     */
    public function validate(array $data, array $findingsById): ConsolidationResult
    {
        // 1. Check redaction guard
        $this->redactionGuard->assertSafeValue($data, 'consolidation-result');

        // 2. Reject approval fields or status fields supplied by LLM
        $forbiddenKeys = ['id', 'status', 'proposed_by', 'approved_by', 'approved_at', 'created_at'];
        foreach ($forbiddenKeys as $key) {
            if (array_key_exists($key, $data)) {
                throw new ValidationException('', null, null, sprintf('forbidden field supplied in consolidation result: %s', $key));
            }
        }

        // 3. Validate action
        $actionValue = $data['action'] ?? null;
        if (!is_string($actionValue)) {
            throw new ValidationException('', null, null, 'missing or invalid action');
        }

        $action = Action::tryFrom($actionValue);
        if ($action === null) {
            throw new ValidationException('', null, null, 'unknown action: ' . $actionValue);
        }

        // 4. Validate fields matching the action type
        $allowedCommon = ['action', 'source_findings', 'reason', 'remaining_uncertainty'];
        $allowedNoDurable = [...$allowedCommon, 'existing_guidance_id'];
        $allowedDurable = [
            ...$allowedCommon,
            'target_type',
            'target',
            'scope',
            'old',
            'new',
            'boundary',
            'validation',
        ];

        $allowedKeys = ($action === Action::NO_DURABLE_LEARNING) ? $allowedNoDurable : $allowedDurable;
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new ValidationException('', null, null, sprintf('unknown field for action %s: %s', $action->value, $key));
            }
        }

        // 5. Validate source findings
        $sourceFindings = $data['source_findings'] ?? null;
        if (!is_array($sourceFindings) || $sourceFindings === []) {
            throw new ValidationException('', null, null, 'missing or empty source_findings');
        }

        $findingScopes = [];
        foreach ($sourceFindings as $index => $findingId) {
            if (!is_string($findingId) || trim($findingId) === '') {
                throw new ValidationException('', null, null, sprintf('invalid finding ID at index %d', $index));
            }
            if (!isset($findingsById[$findingId])) {
                throw new ValidationException('', null, null, sprintf('invalid reference: source finding %s does not exist', $findingId));
            }
            $findingScopes = array_merge($findingScopes, $findingsById[$findingId]->scope);
        }

        /** @var list<string> $sourceFindingsList */
        $sourceFindingsList = array_values($sourceFindings);

        // 6. Validate reason
        $reason = $data['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            throw new ValidationException('', null, null, 'missing or empty reason');
        }

        // 7. Validate remaining uncertainty
        $remainingUncertainty = $data['remaining_uncertainty'] ?? [];
        if (!is_array($remainingUncertainty)) {
            throw new ValidationException('', null, null, 'remaining_uncertainty must be an array');
        }
        foreach ($remainingUncertainty as $item) {
            if (!is_string($item)) {
                throw new ValidationException('', null, null, 'remaining_uncertainty must contain only strings');
            }
        }

        /** @var list<string> $remainingUncertaintyList */
        $remainingUncertaintyList = array_values($remainingUncertainty);

        // Handle NO_DURABLE_LEARNING
        if ($action === Action::NO_DURABLE_LEARNING) {
            $existingGuidanceId = $data['existing_guidance_id'] ?? null;
            if ($existingGuidanceId !== null && (!is_string($existingGuidanceId) || trim($existingGuidanceId) === '')) {
                throw new ValidationException('', null, null, 'existing_guidance_id must be a non-empty string');
            }

            return new NoDurableLearningResult(
                $sourceFindingsList,
                $reason,
                $remainingUncertaintyList,
                $existingGuidanceId
            );
        }

        // Validate Durable Mutation fields
        $targetType = $data['target_type'] ?? null;
        if (!is_string($targetType) || GuidanceType::tryFrom(strtolower($targetType)) === null) {
            throw new ValidationException('', null, null, 'missing or unsupported target_type: ' . var_export($targetType, true));
        }

        $target = $data['target'] ?? null;
        if (!is_string($target) || trim($target) === '') {
            throw new ValidationException('', null, null, 'missing or empty target');
        }

        $scope = $data['scope'] ?? null;
        if (!is_array($scope) || $scope === []) {
            throw new ValidationException('', null, null, 'missing or empty scope');
        }
        foreach ($scope as $s) {
            if (!is_string($s) || trim($s) === '') {
                throw new ValidationException('', null, null, 'scope must contain only non-empty strings');
            }
        }

        /** @var list<string> $scopeList */
        $scopeList = array_values($scope);

        // Check broadened scope without justification
        if ($this->isScopeBroadened($scopeList, $findingScopes)) {
            // Check if there is a justification (reason is already checked to be non-empty string, but let's check length)
            if (strlen(trim($reason)) < 15) {
                throw new ValidationException('', null, null, 'broadened scope requires justification in reason');
            }
        }

        $old = $data['old'] ?? null;
        if ($old !== null && !is_string($old)) {
            throw new ValidationException('', null, null, 'old content must be a string');
        }

        $new = $data['new'] ?? null;
        if ($new !== null && !is_string($new)) {
            throw new ValidationException('', null, null, 'new content must be a string');
        }

        $boundary = $data['boundary'] ?? null;
        if ($boundary !== null && !is_string($boundary)) {
            throw new ValidationException('', null, null, 'boundary must be a string');
        }

        $validation = $data['validation'] ?? [];
        if (!is_array($validation)) {
            throw new ValidationException('', null, null, 'validation must be an array');
        }
        foreach ($validation as $v) {
            if (!is_string($v)) {
                throw new ValidationException('', null, null, 'validation must contain only strings');
            }
        }

        /** @var list<string> $validationList */
        $validationList = array_values($validation);

        return match ($action) {
            Action::ADD => new AddResult($sourceFindingsList, $reason, $targetType, $target, $scopeList, $old, $new, $boundary, $validationList, $remainingUncertaintyList),
            Action::DELETE => new DeleteResult($sourceFindingsList, $reason, $targetType, $target, $scopeList, $old, $new, $boundary, $validationList, $remainingUncertaintyList),
            Action::REPLACE => new ReplaceResult($sourceFindingsList, $reason, $targetType, $target, $scopeList, $old, $new, $boundary, $validationList, $remainingUncertaintyList),
            Action::REJECT => new RejectResult($sourceFindingsList, $reason, $targetType, $target, $scopeList, $old, $new, $boundary, $validationList, $remainingUncertaintyList),
        };
    }

    /**
     * Determine if proposal scopes are broader or disjoint compared to finding scopes.
     *
     * @param list<string> $proposalScopes
     * @param list<string> $findingScopes
     */
    private function isScopeBroadened(array $proposalScopes, array $findingScopes): bool
    {
        // Provenance: finding.2026-06-13.001 (disjoint scope checking)
        foreach ($proposalScopes as $ps) {
            $isCovered = false;
            foreach ($findingScopes as $fs) {
                if ($fs === '/') {
                    $isCovered = true;
                    break;
                }
                if ($ps === $fs) {
                    $isCovered = true;
                    break;
                }
                if (str_starts_with($ps, rtrim($fs, '/') . '/')) {
                    $isCovered = true;
                    break;
                }
            }
            if (!$isCovered) {
                return true;
            }
        }
        return false;
    }
}
