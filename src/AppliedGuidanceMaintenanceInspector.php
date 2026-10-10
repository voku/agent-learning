<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Compares two independently owner-validated project snapshots.
 *
 * Does not parse guidance Markdown or decide whether a change merits a Finding.
 * Consumers must bind their own exact-diff human review separately.
 */
final readonly class AppliedGuidanceMaintenanceInspector
{
    public function __construct(
        private LearningRepositoryValidator $validator = new LearningRepositoryValidator(),
        private LearningRootResolver $rootResolver = new LearningRootResolver(),
    ) {
    }

    public function inspect(string $beforeRoot, string $afterRoot, string $targetSourceRef): AppliedGuidanceMaintenanceProof
    {
        $target = $this->canonicalTarget($targetSourceRef);
        $before = $this->validator->validate($beforeRoot)->proposalsById;
        $after = $this->validator->validate($afterRoot)->proposalsById;
        $beforeHash = $this->targetHash($beforeRoot, $target);
        $afterHash = $this->targetHash($afterRoot, $target);
        if ($beforeHash === $afterHash) {
            throw new ValidationException($target, null, null, 'target content did not change');
        }

        ksort($before);
        ksort($after);
        if (array_keys($before) !== array_keys($after)) {
            throw new ValidationException($target, null, null, 'proposal identities changed across maintenance snapshots');
        }

        $reanchors = [];
        foreach ($before as $id => $old) {
            $new = $after[$id];
            $wasTarget = $this->appliedOnTarget($old, $target);
            $isTarget = $this->appliedOnTarget($new, $target);
            if ($wasTarget !== $isTarget) {
                throw new ValidationException($target, null, $id, 'applied proposal target/status changed');
            }
            if (!$wasTarget) {
                if ($old->raw !== $new->raw) {
                    throw new ValidationException($target, null, $id, 'unrelated proposal changed');
                }
                continue;
            }

            $reanchors[] = $this->verifiedReanchor($old, $new, $target, $beforeHash, $afterHash);
        }

        if ($reanchors === []) {
            throw new ValidationException($target, null, null, 'no applied guidance proofs on target');
        }

        return new AppliedGuidanceMaintenanceProof($target, $beforeHash, $afterHash, $reanchors);
    }

    /** @return array{proposal_id: non-empty-string, reanchored_by: non-empty-string, reanchored_at: non-empty-string, reason: non-empty-string} */
    private function verifiedReanchor(Proposal $old, Proposal $new, string $target, string $beforeHash, string $afterHash): array
    {
        $oldProof = $old->raw['applied_validation'] ?? null;
        $newProof = $new->raw['applied_validation'] ?? null;
        if (!is_array($oldProof) || !is_array($newProof)
            || ($oldProof['target_content_hash'] ?? null) !== $beforeHash
            || ($newProof['target_content_hash'] ?? null) !== $afterHash
            || $this->canonicalTarget((string) ($oldProof['target_source_ref'] ?? '')) !== $target
            || $this->canonicalTarget((string) ($newProof['target_source_ref'] ?? '')) !== $target
        ) {
            throw new ValidationException($target, null, $old->id, 'missing or stale applied target proof');
        }

        $actor = $newProof['reanchored_by'] ?? null;
        $at = $newProof['reanchored_at'] ?? null;
        $reason = $newProof['reanchor_reason'] ?? null;
        if (!is_string($actor) || trim($actor) === ''
            || !is_string($at) || trim($at) === ''
            || !is_string($reason) || trim($reason) === ''
            || ($oldProof['reanchored_at'] ?? null) === $at
        ) {
            throw new ValidationException($target, null, $old->id, 'missing new reanchor provenance');
        }

        $oldRecord = $old->raw;
        $newRecord = $new->raw;
        foreach (['target_content_hash', 'reanchored_by', 'reanchored_at', 'reanchor_reason'] as $field) {
            unset($oldProof[$field], $newProof[$field]);
        }
        $oldRecord['applied_validation'] = $oldProof;
        $newRecord['applied_validation'] = $newProof;
        if ($oldRecord !== $newRecord) {
            throw new ValidationException($target, null, $old->id, 'approval, guidance, or application evidence changed');
        }

        return [
            'proposal_id' => $old->id,
            'reanchored_by' => trim($actor),
            'reanchored_at' => trim($at),
            'reason' => trim($reason),
        ];
    }

    private function appliedOnTarget(Proposal $proposal, string $target): bool
    {
        if ($proposal->status !== ProposalStatus::APPLIED
            || !in_array($proposal->targetType, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)
        ) {
            return false;
        }

        $proof = $proposal->raw['applied_validation'] ?? null;

        return is_array($proof)
            && is_string($proof['target_source_ref'] ?? null)
            && $this->canonicalTarget($proof['target_source_ref']) === $target;
    }

    private function targetHash(string $learningRoot, string $target): string
    {
        $projectRoot = $this->rootResolver->resolve($learningRoot)->projectRoot;
        $path = realpath($projectRoot . '/' . $target);
        $root = realpath($projectRoot);
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new ValidationException($target, null, null, 'target does not resolve to a project file');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new ValidationException($target, null, null, 'cannot hash target');
        }

        return $hash;
    }

    /** @return non-empty-string */
    private function canonicalTarget(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        while (str_starts_with($target, './')) {
            $target = substr($target, 2);
        }
        if ($target === '' || str_starts_with($target, '/') || preg_match('/^[A-Za-z]:\//', $target) === 1
            || in_array('..', explode('/', $target), true)
        ) {
            throw new ValidationException($target, null, null, 'target must be a repository-relative path');
        }

        return $target;
    }
}
