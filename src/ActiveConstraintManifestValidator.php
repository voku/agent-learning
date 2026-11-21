<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use JsonException;
use RuntimeException;

/**
 * Fails closed when an active constraint manifest no longer describes enforceable guidance:
 * its source proposal is unknown or no longer approved/applied, it disagrees with that
 * proposal's rule identity, or a declared rule/registration file is missing under the
 * project root. Recall selects every manifest in the active directory as a hard constraint,
 * so a stale one would tell agents to run validation for a rule that no longer exists.
 *
 * The check runs in `validate` only, not inside every transition, so the repair route
 * (`proposal-retire`) stays available while a stale manifest exists.
 */
final readonly class ActiveConstraintManifestValidator
{
    public function __construct(private LearningRootResolver $rootResolver = new LearningRootResolver())
    {
    }

    /**
     * @param array<string, Proposal> $proposalsById
     * @return int number of active constraint manifests checked
     * @throws ValidationException
     */
    public function validate(string $root, array $proposalsById, ?string $projectRootOverride = null): int
    {
        try {
            $config = $this->rootResolver->resolveWithOverrides(new CliOverrides(root: $root, projectRoot: $projectRootOverride));
        } catch (RuntimeException $exception) {
            throw new ValidationException($root, null, null, 'cannot resolve project root to check active constraint manifests: ' . $exception->getMessage());
        }
        $directory = $config->activeConstraintsDirectory;
        if (!is_dir($directory)) {
            return 0;
        }

        $files = glob($directory . '/*.json') ?: [];
        sort($files);
        foreach ($files as $manifestPath) {
            $this->validateManifest($manifestPath, $proposalsById, rtrim($config->projectRoot, '/\\'));
        }

        return count($files);
    }

    /**
     * @param array<string, Proposal> $proposalsById
     */
    private function validateManifest(string $manifestPath, array $proposalsById, string $projectRoot): void
    {
        $content = file_get_contents($manifestPath);
        try {
            $manifest = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new ValidationException($manifestPath, null, null, 'malformed active constraint manifest JSON: ' . $exception->getMessage());
        }
        if (!is_array($manifest)) {
            throw new ValidationException($manifestPath, null, null, 'active constraint manifest must be a JSON object');
        }

        $sourceProposal = $manifest['source_proposal'] ?? null;
        if (!is_string($sourceProposal) || trim($sourceProposal) === '') {
            throw new ValidationException($manifestPath, null, null, 'active constraint manifest has no source_proposal');
        }
        $proposal = $proposalsById[$sourceProposal] ?? null;
        if ($proposal === null) {
            throw new ValidationException($manifestPath, null, $sourceProposal, 'active constraint manifest references unknown source proposal: ' . $sourceProposal);
        }
        if ($proposal->status === ProposalStatus::RETIRED) {
            throw new ValidationException(
                $manifestPath,
                null,
                $sourceProposal,
                'active constraint manifest names retired source proposal ' . $sourceProposal
                . '; rerun proposal-retire ' . $sourceProposal . ' --by ACTOR --reason TEXT to remove the stale manifest',
            );
        }
        if ($proposal->status !== ProposalStatus::APPROVED && $proposal->status !== ProposalStatus::APPLIED) {
            throw new ValidationException(
                $manifestPath,
                null,
                $sourceProposal,
                'active constraint manifest names source proposal ' . $sourceProposal . ' with status ' . $proposal->status->value . '; only approved or applied constraints may stay active',
            );
        }
        if ($proposal->constraint === null) {
            throw new ValidationException($manifestPath, null, $sourceProposal, 'active constraint manifest source proposal is not a constraint proposal: ' . $sourceProposal);
        }
        if (($manifest['rule_identifier'] ?? null) !== $proposal->constraint->ruleId) {
            throw new ValidationException($manifestPath, null, $sourceProposal, 'active constraint manifest rule_identifier does not match source proposal rule_id: ' . $proposal->constraint->ruleId);
        }

        $declared = ['target rule file' => [$proposal->constraint->targetRulePath], 'registration file' => $proposal->constraint->registrationFiles];
        foreach ($declared as $label => $paths) {
            foreach ($paths as $relativePath) {
                if (!is_file($projectRoot . '/' . ltrim($relativePath, '/'))) {
                    throw new ValidationException(
                        $manifestPath,
                        null,
                        $sourceProposal,
                        'active constraint ' . $label . ' does not exist under project root ' . $projectRoot . ': ' . $relativePath
                        . '; restore it, or retire the constraint with proposal-retire ' . $sourceProposal . ' --by ACTOR --reason TEXT',
                    );
                }
            }
        }
    }
}
