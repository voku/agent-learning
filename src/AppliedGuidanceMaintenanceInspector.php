<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RuntimeException;

/**
 * Read-only evidence of changed physical proofs, not a semantic or human-approval verdict.
 *
 * Both independent Learning roots are validated before their applied proposal
 * records are compared. In particular, the owner verifies the reviewed wording
 * and the real physical target hashes.
 */
final readonly class AppliedGuidanceMaintenanceInspector
{
    public function __construct(
        private LearningRepositoryValidator $validator = new LearningRepositoryValidator(),
        private LearningRootResolver $roots = new LearningRootResolver(),
    ) {
    }

    public function inspect(string $beforeRoot, string $afterRoot, string $targetSourceRef): AppliedGuidanceMaintenanceEvidence
    {
        $before = $this->validator->validate($beforeRoot);
        $after = $this->validator->validate($afterRoot);
        $oldTarget = $this->targetFile($beforeRoot, $targetSourceRef);
        $newTarget = $this->targetFile($afterRoot, $targetSourceRef);
        $oldHash = hash_file('sha256', $oldTarget);
        $newHash = hash_file('sha256', $newTarget);
        if (!is_string($oldHash) || !is_string($newHash) || hash_equals($oldHash, $newHash)) {
            throw new RuntimeException('Applied guidance maintenance requires two different readable target revisions.');
        }

        $beforeProposals = $before->proposalsById;
        $afterProposals = $after->proposalsById;
        ksort($beforeProposals);
        ksort($afterProposals);
        if (array_keys($beforeProposals) !== array_keys($afterProposals)) {
            throw new RuntimeException('Proposal identities changed across maintenance snapshots.');
        }

        $ids = [];
        $reanchors = [];
        foreach ($beforeProposals as $id => $old) {
            $new = $afterProposals[$id];
            $oldProof = $old->raw['applied_validation'] ?? null;
            $newProof = $new->raw['applied_validation'] ?? null;
            $oldRef = is_array($oldProof) ? ($oldProof['target_source_ref'] ?? null) : null;
            $newRef = is_array($newProof) ? ($newProof['target_source_ref'] ?? null) : null;

            $oldOnTarget = $old->status === ProposalStatus::APPLIED
                && in_array($old->targetType, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)
                && is_string($oldRef)
                && $this->targetFile($beforeRoot, $oldRef) === $oldTarget;
            $newOnTarget = $new->status === ProposalStatus::APPLIED
                && in_array($new->targetType, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)
                && is_string($newRef)
                && $this->targetFile($afterRoot, $newRef) === $newTarget;

            if ($oldOnTarget !== $newOnTarget) {
                throw new RuntimeException('Applied guidance target membership changed for ' . $id);
            }
            if (!$oldOnTarget) {
                if ($old->raw !== $new->raw) {
                    throw new RuntimeException('Unrelated proposal changed during maintenance: ' . $id);
                }
                continue;
            }

            if ($oldProof === null || $newProof === null
                || ($oldProof['target_content_hash'] ?? null) !== $oldHash
                || ($newProof['target_content_hash'] ?? null) !== $newHash) {
                throw new RuntimeException('Applied target proof mismatch for ' . $id);
            }

            $actor = $newProof['reanchored_by'] ?? null;
            $at = $newProof['reanchored_at'] ?? null;
            $reason = $newProof['reanchor_reason'] ?? null;
            if (!is_string($actor) || trim($actor) === ''
                || !is_string($at) || trim($at) === ''
                || !is_string($reason) || trim($reason) === ''
                || $at === ($oldProof['reanchored_at'] ?? null)) {
                throw new RuntimeException('Missing new reanchor provenance for ' . $id);
            }

            $oldRaw = $old->raw;
            $newRaw = $new->raw;
            $oldRaw['applied_validation'] = $this->withoutReanchorFields($oldProof);
            $newRaw['applied_validation'] = $this->withoutReanchorFields($newProof);
            if ($oldRaw !== $newRaw) {
                throw new RuntimeException('Reviewed proposal or application evidence changed for ' . $id);
            }

            if ($id === '') {
                throw new RuntimeException('Applied proposal identity must not be empty.');
            }
            $ids[] = $id;
            $reanchors[$id] = ['actor' => $actor, 'at' => $at, 'reason' => $reason];
        }

        if ($ids === [] || $reanchors === []) {
            throw new RuntimeException('No applied guidance proof names the target in both snapshots.');
        }

        return new AppliedGuidanceMaintenanceEvidence(
            $targetSourceRef,
            $oldHash,
            $newHash,
            $ids,
            $reanchors,
        );
    }

    /**
     * @param array<string, mixed> $proof
     * @return array<string, mixed>
     */
    private function withoutReanchorFields(array $proof): array
    {
        unset($proof['target_content_hash'], $proof['reanchored_by'], $proof['reanchored_at'], $proof['reanchor_reason']);

        return $proof;
    }

    private function targetFile(string $root, string $sourceRef): string
    {
        $sourceRef = str_replace('\\', '/', trim($sourceRef));
        if ($sourceRef === '' || str_starts_with($sourceRef, '/')
            || preg_match('/^[A-Za-z]:\//', $sourceRef) === 1
            || in_array('..', explode('/', $sourceRef), true)) {
            throw new RuntimeException('Invalid applied guidance source reference: ' . $sourceRef);
        }

        $project = realpath($this->roots->resolve($root)->projectRoot);
        $candidate = $project === false ? false : realpath($project . '/' . $sourceRef);
        if ($project === false || $candidate === false || !is_file($candidate)
            || !str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $project), '/') . '/')) {
            throw new RuntimeException('Applied guidance source does not resolve inside the project: ' . $sourceRef);
        }

        return $candidate;
    }
}
