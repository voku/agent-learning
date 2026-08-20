<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConstraintGenerationPackageExporter
{
    /**
     * @param array<string, Finding> $findingsById
     */
    public function export(string $root, string $proposalPath, string $outputDir, array $findingsById, ?string $projectRoot = null): void
    {
        $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
        if ($proposal->targetType !== GuidanceType::CONSTRAINT->value || $proposal->constraint === null) {
            throw new ValidationException($proposalPath, null, $proposal->id, 'generation package export requires a constraint proposal');
        }

        (new ConstraintPromotionValidator())->validate($proposal, $proposalPath, null, $findingsById);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new ValidationException($outputDir, null, $proposal->id, 'cannot create generation package directory');
        }

        $resolvedProjectRoot = (new LearningProjectPaths())->projectRootForLearningRoot($root, $projectRoot);
        $adoptExisting = $this->canAdoptExistingConstraint($resolvedProjectRoot, $proposal->constraint);

        $sourceFindings = [];
        foreach ($proposal->sourceFindings as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if ($finding === null) {
                throw new ValidationException($proposalPath, null, $proposal->id, 'source finding does not exist: ' . $findingId);
            }
            $sourceFindings[] = $finding->raw;
        }

        $this->writeJson($outputDir . '/specification.json', [
            'schema_version' => '1.0',
            'mode' => $adoptExisting ? 'adopt_existing' : 'generate',
            'proposal_id' => $proposal->id,
            'constraint' => $proposal->constraint->toArray(),
        ]);
        $this->writeJson($outputDir . '/source-findings.json', [
            'schema_version' => '1.0',
            'findings' => $sourceFindings,
        ]);
        $this->writeJson($outputDir . '/source-proposals.json', [
            'schema_version' => '1.0',
            'proposal' => $proposal->raw,
        ]);
        $this->writeJson($outputDir . '/examples.json', [
            'schema_version' => '1.0',
            'examples' => $this->loadExamples($root, $proposal->constraint->exampleRulePaths, $projectRoot),
        ]);
        $this->writeJson($outputDir . '/validation-plan.json', [
            'schema_version' => '1.0',
            'mode' => $adoptExisting ? 'adopt_existing' : 'generate',
            'commands' => $proposal->constraint->validationCommands,
            'expected_fixtures' => $adoptExisting ? [] : ['valid.php', 'invalid.php', 'boundary.php', 'false-positive.php'],
        ]);
        $this->writeText(
            $outputDir . '/generation-prompt.md',
            $adoptExisting ? $this->buildAdoptionPrompt($proposal) : $this->buildGenerationPrompt($proposal),
        );
    }

    private function canAdoptExistingConstraint(string $projectRoot, ConstraintSpecification $constraint): bool
    {
        if (!is_file($projectRoot . '/' . ltrim($constraint->targetRulePath, '/'))) {
            return false;
        }

        foreach ($constraint->registrationFiles as $registrationFile) {
            if (!is_file($projectRoot . '/' . ltrim($registrationFile, '/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $examplePaths
     * @return list<array{path: string, content: string}>
     */
    private function loadExamples(string $root, array $examplePaths, ?string $configuredProjectRoot): array
    {
        $examples = [];
        $projectRoot = (new LearningProjectPaths())->projectRootForLearningRoot($root, $configuredProjectRoot);
        foreach ($examplePaths as $path) {
            $absolute = is_file($path) ? $path : $projectRoot . '/' . ltrim($path, '/');
            if (!is_file($absolute)) {
                $examples[] = ['path' => $path, 'content' => ''];
                continue;
            }
            $content = file_get_contents($absolute);
            $examples[] = ['path' => $path, 'content' => $content === false ? '' : $content];
        }

        return $examples;
    }

    private function buildAdoptionPrompt(Proposal $proposal): string
    {
        $constraint = $proposal->constraint;
        \assert($constraint instanceof ConstraintSpecification);

        return sprintf(
            "# Constraint Adoption Prompt\n\n"
            . "The repository already contains the configured %s enforcement for `%s`.\n\n"
            . "Existing target path: `%s`\n\n"
            . "Existing registration files:\n%s\n\n"
            . "Do not generate a duplicate rule or synthetic PHP fixtures. Validate the existing enforcement against the approved constraint semantics and historical bad/good states using:\n%s\n\n"
            . "If that validation passes, use the existing constraint activation path to record the reviewed lineage manifest. Do not activate the constraint without human approval.\n",
            $constraint->engine->value,
            $constraint->ruleId,
            $constraint->targetRulePath,
            $this->bulletList($constraint->registrationFiles),
            $this->bulletList($constraint->validationCommands),
        );
    }

    private function buildGenerationPrompt(Proposal $proposal): string
    {
        $constraint = $proposal->constraint;
        \assert($constraint instanceof ConstraintSpecification);

        return sprintf(
            "# Constraint Generation Prompt\n\n"
            . "Generate a repository-local %s rule for `%s`.\n\n"
            . "Rule class: `%s`\n\n"
            . "Target path: `%s`\n\n"
            . "Violation: %s\n\n"
            . "Scope:\n%s\n\n"
            . "Allowed boundaries:\n%s\n\n"
            . "Validation commands:\n%s\n\n"
            . "The candidate must include valid, invalid, boundary, and false-positive fixtures. Do not activate the rule without human approval.\n",
            $constraint->engine->value,
            $constraint->ruleId,
            $constraint->ruleClassName,
            $constraint->targetRulePath,
            $constraint->violation,
            $this->bulletList($constraint->scope),
            $this->bulletList($constraint->allowedBoundaries),
            $this->bulletList($constraint->validationCommands),
        );
    }

    /**
     * @param list<string> $items
     */
    private function bulletList(array $items): string
    {
        if ($items === []) {
            return "- none";
        }

        return implode("\n", array_map(static fn(string $item): string => '- ' . $item, $items));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->writeText($path, $json . "\n");
    }

    private function writeText(string $path, string $content): void
    {
        $written = file_put_contents($path, $content);
        if ($written === false) {
            throw new ValidationException($path, null, null, 'cannot write generation package file');
        }
    }
}
