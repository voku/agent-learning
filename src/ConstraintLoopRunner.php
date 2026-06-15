<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintLoopRunner
{
    /**
     * @param array<string, Finding> $findingsById
     */
    public function run(
        string $root,
        string $proposalPath,
        string $actor,
        string $commit,
        string $validationFilePath,
        ?string $outputDir,
        ?string $manifestPath,
        array $findingsById,
        bool $approveCandidate = false,
        bool $overwriteManifest = false,
        ?string $projectRoot = null,
        ?string $constraintGenerationDir = null,
        ?string $activeConstraintsDir = null,
    ): ConstraintLoopResult {
        $transitionManager = new ProposalTransitionManager();
        $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
        if ($proposal->targetType !== GuidanceType::CONSTRAINT->value || $proposal->constraint === null) {
            throw new ValidationException($proposalPath, null, $proposal->id, 'constraint loop requires a constraint proposal');
        }

        $approvedCandidate = false;
        if ($proposal->status === ProposalStatus::CANDIDATE) {
            if (!$approveCandidate) {
                throw new ValidationException($proposalPath, null, $proposal->id, 'constraint loop requires an approved/applied proposal or --approve-candidate');
            }
            $transitionManager->approve($root, $proposal->id, $actor);
            $proposalPath = $transitionManager->resolveProposalPath($proposal->id, $root);
            $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
            $approvedCandidate = true;
        }

        if ($proposal->status !== ProposalStatus::APPROVED && $proposal->status !== ProposalStatus::APPLIED) {
            throw new ValidationException($proposalPath, null, $proposal->id, 'constraint loop requires an approved or applied proposal');
        }

        $generationPackageDir = $outputDir;
        if ($generationPackageDir === null || trim($generationPackageDir) === '') {
            $generationPackageDir = (new LearningProjectPaths())->constraintGenerationDirectory($root, $constraintGenerationDir) . '/' . $proposal->id;
        }

        (new ConstraintGenerationPackageExporter())->export($root, $proposalPath, $generationPackageDir, $findingsById, $projectRoot);

        $markedApplied = false;
        if ($proposal->status === ProposalStatus::APPROVED) {
            $transitionManager->apply($root, $proposal->id, $actor, $commit, $validationFilePath);
            $proposalPath = $transitionManager->resolveProposalPath($proposal->id, $root);
            $markedApplied = true;
        }

        $activatedManifestPath = (new ConstraintManifestActivator())->activate(
            $root,
            $proposalPath,
            $manifestPath,
            $findingsById,
            $overwriteManifest,
            $projectRoot,
            $activeConstraintsDir,
        );

        return new ConstraintLoopResult(
            $proposal->id,
            $generationPackageDir,
            $activatedManifestPath,
            $approvedCandidate,
            $markedApplied,
        );
    }
}
