<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintManifestActivator
{
    /**
     * @param array<string, Finding> $findingsById
     */
    public function activate(
        string $root,
        string $proposalPath,
        ?string $outputPath,
        array $findingsById,
        bool $overwrite = false,
        ?string $projectRoot = null,
        ?string $activeConstraintsDir = null,
    ): string {
        $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
        if ($proposal->targetType !== GuidanceType::CONSTRAINT->value || $proposal->constraint === null) {
            throw new ValidationException($proposalPath, null, $proposal->id, 'constraint activation requires a constraint proposal');
        }

        if ($proposal->status !== ProposalStatus::APPROVED && $proposal->status !== ProposalStatus::APPLIED) {
            throw new ValidationException($proposalPath, null, $proposal->id, 'constraint activation requires an approved or applied proposal');
        }

        (new ConstraintPromotionValidator())->validate($proposal, $proposalPath, null, $findingsById);

        $paths = new LearningProjectPaths();
        $resolvedProjectRoot = $paths->projectRootForLearningRoot($root, $projectRoot);
        $this->assertProjectFileExists($resolvedProjectRoot, $proposal->constraint->targetRulePath, $proposalPath, $proposal->id, 'target rule file');
        foreach ($proposal->constraint->registrationFiles as $registrationFile) {
            $this->assertProjectFileExists($resolvedProjectRoot, $registrationFile, $proposalPath, $proposal->id, 'registration file');
        }

        $manifestPath = $outputPath;
        if ($manifestPath === null || trim($manifestPath) === '') {
            $manifestPath = $paths->activeConstraintsDirectory($root, $activeConstraintsDir) . '/constraint.' . $proposal->constraint->ruleId . '.json';
        }

        if (is_file($manifestPath) && !$overwrite) {
            throw new ValidationException($manifestPath, null, $proposal->id, 'constraint manifest already exists; pass --overwrite to replace it');
        }

        if (!is_dir(dirname($manifestPath)) && !mkdir(dirname($manifestPath), 0777, true) && !is_dir(dirname($manifestPath))) {
            throw new ValidationException($manifestPath, null, $proposal->id, 'cannot create constraint manifest directory');
        }

        $manifest = [
            'schema_version' => '1.0',
            'id' => 'constraint.' . $proposal->constraint->ruleId,
            'engine' => $proposal->constraint->engine->value,
            'rule_identifier' => $proposal->constraint->ruleId,
            'scope' => $proposal->constraint->scope,
            'validation_commands' => $proposal->constraint->validationCommands,
            'source_proposal' => $proposal->id,
            'status' => 'active',
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $written = file_put_contents($manifestPath, $json . "\n");
        if ($written === false) {
            throw new ValidationException($manifestPath, null, $proposal->id, 'cannot write constraint manifest');
        }

        return $manifestPath;
    }

    private function assertProjectFileExists(
        string $projectRoot,
        string $relativePath,
        string $proposalPath,
        string $proposalId,
        string $label,
    ): void {
        $path = $projectRoot . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            throw new ValidationException($proposalPath, null, $proposalId, $label . ' does not exist: ' . $relativePath);
        }
    }
}
