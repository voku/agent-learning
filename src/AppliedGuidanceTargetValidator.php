<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RuntimeException;

final readonly class AppliedGuidanceTargetValidator
{
    public function __construct(private LearningRootResolver $rootResolver = new LearningRootResolver())
    {
    }

    public function validate(Proposal $proposal, string $root, string $proposalFile): void
    {
        if (
            $proposal->status !== ProposalStatus::APPLIED
            || $proposal->targetType === GuidanceType::CONSTRAINT->value
        ) {
            return;
        }

        if (!in_array($proposal->targetType, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied guidance has unsupported target_type: ' . (string) $proposal->targetType);
        }

        $validation = $proposal->raw['applied_validation'] ?? null;
        if (!is_array($validation)) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied memory/skill proposal requires applied_validation evidence');
        }

        $sourceRef = $validation['target_source_ref'] ?? null;
        if (!is_string($sourceRef) || trim($sourceRef) === '') {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied memory/skill proposal requires target_source_ref');
        }
        $sourceRef = str_replace('\\', '/', trim($sourceRef));
        if (
            str_starts_with($sourceRef, '/')
            || preg_match('/^[A-Za-z]:\//', $sourceRef) === 1
            || in_array('..', explode('/', $sourceRef), true)
        ) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'target_source_ref must stay inside the configured project root');
        }

        $expectedHash = $validation['target_content_hash'] ?? null;
        if (!is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', strtolower(trim($expectedHash))) !== 1) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied memory/skill proposal requires target_content_hash as sha256 hex');
        }
        $expectedHash = strtolower(trim($expectedHash));

        try {
            $projectRoot = $this->rootResolver->resolve($root)->projectRoot;
        } catch (RuntimeException $exception) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'cannot resolve project root for applied guidance: ' . $exception->getMessage());
        }

        $targetPath = rtrim($projectRoot, '/\\') . '/' . ltrim($sourceRef, '/');
        $realProjectRoot = realpath($projectRoot);
        $realTargetPath = realpath($targetPath);
        if ($realProjectRoot === false || $realTargetPath === false || !is_file($realTargetPath)) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied guidance target does not exist: ' . $sourceRef);
        }

        $projectPrefix = rtrim(str_replace('\\', '/', $realProjectRoot), '/') . '/';
        $normalizedTargetPath = str_replace('\\', '/', $realTargetPath);
        if (!str_starts_with($normalizedTargetPath, $projectPrefix)) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied guidance target resolves outside configured project root: ' . $sourceRef);
        }

        $actualHash = hash_file('sha256', $realTargetPath);
        if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'applied guidance target_content_hash does not match target file: ' . $sourceRef);
        }

        $content = file_get_contents($realTargetPath);
        if ($content === false) {
            throw new ValidationException($proposalFile, null, $proposal->id, 'cannot read applied guidance target: ' . $sourceRef);
        }

        if ($proposal->action === Action::ADD) {
            $this->assertContains($proposal, $proposalFile, $sourceRef, $content, $proposal->new, 'added guidance wording is not present');

            return;
        }

        if ($proposal->action === Action::REPLACE) {
            $this->assertContains($proposal, $proposalFile, $sourceRef, $content, $proposal->new, 'replacement guidance wording is not present');
            if ($proposal->old !== null && str_contains($content, $proposal->old)) {
                throw new ValidationException($proposalFile, null, $proposal->id, 'replaced guidance wording is still present in target: ' . $sourceRef);
            }

            return;
        }

        if ($proposal->action === Action::DELETE) {
            if ($proposal->old !== null && str_contains($content, $proposal->old)) {
                throw new ValidationException($proposalFile, null, $proposal->id, 'deleted guidance wording is still present in target: ' . $sourceRef);
            }

            return;
        }

        throw new ValidationException($proposalFile, null, $proposal->id, 'unsupported applied guidance action: ' . $proposal->action->value);
    }

    private function assertContains(
        Proposal $proposal,
        string $proposalFile,
        string $sourceRef,
        string $content,
        ?string $wording,
        string $message,
    ): void {
        if ($wording === null || $wording === '' || !str_contains($content, $wording)) {
            throw new ValidationException($proposalFile, null, $proposal->id, $message . ' in target: ' . $sourceRef);
        }
    }
}
