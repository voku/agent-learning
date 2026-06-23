<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class Cli
{
    public function __construct(
        private readonly PathResolver $pathResolver = new PathResolver(),
        private readonly FindingLifecycle $findingLifecycle = new FindingLifecycle(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $tokens = $argv;
        array_shift($tokens);
        $command = array_shift($tokens) ?? 'help';

        try {
            return match ($command) {
                'validate' => $this->validateCommand($tokens),
                'prepare' => $this->prepareCommand($tokens),
                'proposal-validate' => $this->proposalValidateCommand($tokens),
                'proposal-import' => $this->proposalImportCommand($tokens),
                'constraint-export' => $this->constraintExportCommand($tokens),
                'constraint-activate' => $this->constraintActivateCommand($tokens),
                'constraint-loop' => $this->constraintLoopCommand($tokens),
                'guidance-evaluate' => $this->guidanceEvaluateCommand($tokens),
                'finding-transition' => $this->findingTransitionCommand($tokens),
                'proposal-approve' => $this->proposalApproveCommand($tokens),
                'proposal-reject' => $this->proposalRejectCommand($tokens),
                'proposal-mark-applied' => $this->proposalMarkAppliedCommand($tokens),
                'proposal-retire' => $this->proposalRetireCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $throwable) {
            $this->writeError($throwable->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function guidanceEvaluateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $selectionHistory = $this->resolveHistoryPath($root, $this->stringOption($parsed['options'], 'selection-history') ?? 'history/recall-selections.jsonl');
        $outcomeHistory = $this->resolveHistoryPath($root, $this->stringOption($parsed['options'], 'outcome-history') ?? 'history/outcomes.jsonl');

        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);
        (new OutcomeRepository())->loadAll($root, $proposalsById);

        $selectionEvents = (new RecallSelectionEventRepository())->load($root, $selectionHistory);
        $outcomeEvents = (new GuidanceOutcomeEventRepository())->load($root, $outcomeHistory);
        $result = (new GuidanceEvolutionEvaluator())->evaluate($findingsById, $proposalsById, $selectionEvents, $outcomeEvents);

        $this->write("Guidance usage summaries:\n");
        foreach ($result->summaries as $summary) {
            $this->write(sprintf(
                "- %s (%s): eligible=%d selected=%d applied=%d helpful=%d irrelevant=%d harmful=%d not_used=%d unknown=%d tasks=%d\n",
                $summary->guidanceId,
                $summary->guidanceType->value,
                $summary->eligibleCount,
                $summary->selectedCount,
                $summary->appliedCount,
                $summary->helpfulCount,
                $summary->irrelevantCount,
                $summary->harmfulCount,
                $summary->notUsedCount,
                $summary->unknownCount,
                $summary->distinctTaskCount,
            ));
        }

        $this->write("Candidate decisions:\n");
        foreach ($result->decisions as $decision) {
            $this->write(sprintf(
                "- %s %s: %s -> %s; reason=%s; uncertainty=%s\n",
                $decision->type->value,
                $decision->guidanceId,
                $decision->sourceTier->value,
                $decision->targetTier instanceof GuidanceType ? $decision->targetTier->value : 'review',
                $decision->reason,
                $decision->remainingUncertainty,
            ));
        }

        if ($this->boolOption($parsed['options'], 'write-candidates')) {
            $proposalIds = (new GuidanceCandidateProposalWriter())->write($root, $result->decisions, $findingsById);
            $this->write('Candidate proposals written: ' . count($proposalIds) . "\n");
            foreach ($proposalIds as $proposalId) {
                $this->write('- ' . $proposalId . "\n");
            }
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function validateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $taskIdPattern = $this->stringOption($parsed['options'], 'task-id-pattern');

        $result = (new LearningRepositoryValidator($this->findingLifecycle))->validate($root, $taskIdPattern);

        $this->write(
            'Validated agent learning root: ' . $result->root . "\n"
            . 'Findings: ' . count($result->findingsById) . "\n"
            . 'Proposals: ' . count($result->proposalsById) . "\n"
            . 'Recall selections: ' . count($result->recallSelectionEvents) . "\n"
            . 'Guidance outcomes: ' . count($result->guidanceOutcomeEvents) . "\n"
        );

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function constraintLoopCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposal = $this->stringOption($parsed['options'], 'proposal') ?? $parsed['arguments'][0] ?? null;
        if ($proposal === null || trim($proposal) === '') {
            throw new ValidationException($root, null, null, 'constraint-loop requires --proposal or a proposal ID/path argument');
        }

        $actor = $this->stringOption($parsed['options'], 'by');
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'constraint-loop requires --by actor option');
        }

        $commit = $this->stringOption($parsed['options'], 'commit');
        if ($commit === null || trim($commit) === '') {
            throw new ValidationException($root, null, null, 'constraint-loop requires --commit option');
        }

        $validation = $this->stringOption($parsed['options'], 'validation');
        if ($validation === null || trim($validation) === '') {
            throw new ValidationException($root, null, null, 'constraint-loop requires --validation file option');
        }

        $proposalPath = $this->resolveProposalPathOrId($proposal, $root);
        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        $result = (new ConstraintLoopRunner())->run(
            $root,
            $proposalPath,
            $actor,
            $commit,
            $validation,
            $this->stringOption($parsed['options'], 'output-dir') ?? $this->stringOption($parsed['options'], 'output'),
            $this->stringOption($parsed['options'], 'manifest') ?? $this->stringOption($parsed['options'], 'manifest-path'),
            $findingsById,
            $this->boolOption($parsed['options'], 'approve-candidate'),
            $this->boolOption($parsed['options'], 'overwrite'),
            $this->stringOption($parsed['options'], 'project-root'),
            $this->stringOption($parsed['options'], 'constraint-generation-dir'),
            $this->stringOption($parsed['options'], 'active-constraints-dir'),
        );

        if ($result->approvedCandidate) {
            $this->write('Approved proposal: ' . $result->proposalId . "\n");
        }
        $this->write('Exported constraint generation package: ' . $result->generationPackageDir . "\n");
        if ($result->markedApplied) {
            $this->write('Marked proposal applied: ' . $result->proposalId . "\n");
        }
        $this->write('Activated constraint manifest: ' . $result->manifestPath . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function prepareCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $selection = $this->findingSelection($parsed['options'], $parsed['arguments'], $root);
        if (!$selection->hasSelectors()) {
            throw new ValidationException($root, null, null, 'prepare requires --finding, --task, --ticket, --scope, --since, or a task id argument');
        }

        $findings = $this->selectFindings(
            $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern')),
            $selection,
        );
        if ($findings === [] && $this->boolOption($parsed['options'], 'allow-empty') === false) {
            throw new ValidationException($root, null, null, 'prepare selection matched no validated findings');
        }

        // Load active guidance files
        $guidancePaths = $this->stringOptions($parsed['options'], 'guidance');
        $activeGuidance = (new ActiveGuidanceRepository())->loadAll($root, $guidancePaths);

        // Load and filter rejected proposals
        $allRejected = (new RejectedGuidanceRepository())->loadAll($root);
        $findingIds = array_map(static fn(Finding $f) => $f->id, $findings);
        $rejectedGuidance = (new RejectedGuidanceSelector())->select($allRejected, $selection->scopes, $findingIds);

        // Query relevant outcomes
        $allOutcomes = (new OutcomeRepository())->loadAll($root);
        $activeGuidanceIds = array_map(static fn(ActiveGuidance $g) => $g->id, $activeGuidance);
        $rejectedProposalIds = array_map(static fn(RejectedGuidance $rg) => $rg->proposal->id, $rejectedGuidance);
        
        $relevantOutcomes = [];
        foreach ($allOutcomes as $outcome) {
            $guidanceUsed = $outcome['guidance_used'] ?? [];
            $appliedProposals = $outcome['applied_proposals'] ?? [];
            if (array_intersect($guidanceUsed, $activeGuidanceIds) !== [] || array_intersect($appliedProposals, $rejectedProposalIds) !== []) {
                $relevantOutcomes[] = $outcome;
            }
        }

        $input = new ConsolidationInput($selection, $findings, $activeGuidance, $rejectedGuidance, $relevantOutcomes);

        $prompt = $this->appendConsolidationAddendum(
            (new ConsolidationPromptBuilder())->build($input),
            $root,
        );
        $output = $this->stringOption($parsed['options'], 'output') ?? $root . '/consolidation-input.md';
        $written = file_put_contents($output, $prompt);
        if ($written === false) {
            throw new ValidationException($output, null, null, 'cannot write consolidation input');
        }

        $this->write('Selected findings: ' . count($findings) . "\n");
        foreach ($findings as $finding) {
            $this->write('- ' . $finding->id . ' (' . $finding->taskId . ")\n");
        }
        $this->write('Wrote consolidation input: ' . $output . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalValidateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalPath = $this->stringOption($parsed['options'], 'proposal') ?? $parsed['arguments'][0] ?? null;
        if ($proposalPath === null || trim($proposalPath) === '') {
            throw new ValidationException($root, null, null, 'proposal-validate requires --proposal or a proposal path argument');
        }

        $proposalPath = $this->resolveProposalPath($proposalPath, $root);
        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
        (new ProposalLifecycle())->assertPathMatchesStatus($proposal, $proposalPath, $root);
        $this->write('Validated proposal: ' . $proposal->id . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalImportCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $input = $this->stringOption($parsed['options'], 'input');
        if ($input === null) {
            throw new ValidationException($root, null, null, 'proposal-import requires --input path');
        }

        $proposalId = (new ProposalImporter())->import($root, $input);
        $this->write("Imported proposal: " . $proposalId . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function constraintExportCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalPath = $this->stringOption($parsed['options'], 'proposal') ?? $parsed['arguments'][0] ?? null;
        $outputDir = $this->stringOption($parsed['options'], 'output-dir') ?? $this->stringOption($parsed['options'], 'output');
        if ($proposalPath === null || trim($proposalPath) === '') {
            throw new ValidationException($root, null, null, 'constraint-export requires --proposal or a proposal path argument');
        }

        $proposalPath = $this->resolveProposalPath($proposalPath, $root);
        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        if ($outputDir === null || trim($outputDir) === '') {
            $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
            $outputDir = (new LearningProjectPaths())->constraintGenerationDirectory(
                $root,
                $this->stringOption($parsed['options'], 'constraint-generation-dir'),
            ) . '/' . $proposal->id;
        }
        (new ConstraintGenerationPackageExporter())->export(
            $root,
            $proposalPath,
            $outputDir,
            $findingsById,
            $this->stringOption($parsed['options'], 'project-root'),
        );
        $this->write('Exported constraint generation package: ' . $outputDir . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function constraintActivateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalPath = $this->stringOption($parsed['options'], 'proposal') ?? $parsed['arguments'][0] ?? null;
        $outputPath = $this->stringOption($parsed['options'], 'output') ?? $this->stringOption($parsed['options'], 'output-file');
        if ($proposalPath === null || trim($proposalPath) === '') {
            throw new ValidationException($root, null, null, 'constraint-activate requires --proposal or a proposal path argument');
        }

        $proposalPath = $this->resolveProposalPath($proposalPath, $root);
        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        $manifestPath = (new ConstraintManifestActivator())->activate(
            $root,
            $proposalPath,
            $outputPath,
            $findingsById,
            $this->boolOption($parsed['options'], 'overwrite'),
            $this->stringOption($parsed['options'], 'project-root'),
            $this->stringOption($parsed['options'], 'active-constraints-dir'),
        );
        $this->write('Activated constraint manifest: ' . $manifestPath . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function findingTransitionCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $findingId = $parsed['arguments'][0] ?? null;
        $statusVal = $parsed['arguments'][1] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');

        if ($findingId === null || trim($findingId) === '') {
            throw new ValidationException($root, null, null, 'finding-transition requires finding ID argument');
        }
        if ($statusVal === null || trim($statusVal) === '') {
            throw new ValidationException($root, null, null, 'finding-transition requires target status argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'finding-transition requires --by actor option');
        }

        $status = FindingStatus::tryFrom($statusVal);
        if ($status === null) {
            throw new ValidationException($root, null, null, 'unsupported status: ' . $statusVal);
        }

        (new FindingTransitionManager())->transition($root, $findingId, $status, $actor);
        $this->write(sprintf("Transitioned finding %s to status %s\n", $findingId, $status->value));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalApproveCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalId = $parsed['arguments'][0] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');

        if ($proposalId === null || trim($proposalId) === '') {
            throw new ValidationException($root, null, null, 'proposal-approve requires proposal ID argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'proposal-approve requires --by actor option');
        }

        (new ProposalTransitionManager())->approve($root, $proposalId, $actor);
        $this->write(sprintf("Approved proposal: %s\n", $proposalId));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalRejectCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalId = $parsed['arguments'][0] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');
        $reason = $this->stringOption($parsed['options'], 'reason');

        if ($proposalId === null || trim($proposalId) === '') {
            throw new ValidationException($root, null, null, 'proposal-reject requires proposal ID argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'proposal-reject requires --by actor option');
        }
        if ($reason === null || trim($reason) === '') {
            throw new ValidationException($root, null, null, 'proposal-reject requires --reason option');
        }

        (new ProposalTransitionManager())->reject($root, $proposalId, $actor, $reason);
        $this->write(sprintf("Rejected proposal: %s\n", $proposalId));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalMarkAppliedCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalId = $parsed['arguments'][0] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');
        $commit = $this->stringOption($parsed['options'], 'commit');
        $validation = $this->stringOption($parsed['options'], 'validation');

        if ($proposalId === null || trim($proposalId) === '') {
            throw new ValidationException($root, null, null, 'proposal-mark-applied requires proposal ID argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'proposal-mark-applied requires --by actor option');
        }
        if ($commit === null || trim($commit) === '') {
            throw new ValidationException($root, null, null, 'proposal-mark-applied requires --commit option');
        }
        if ($validation === null || trim($validation) === '') {
            throw new ValidationException($root, null, null, 'proposal-mark-applied requires --validation file option');
        }

        (new ProposalTransitionManager())->apply($root, $proposalId, $actor, $commit, $validation);
        $this->write(sprintf("Marked proposal applied: %s\n", $proposalId));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalRetireCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalId = $parsed['arguments'][0] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');
        $reason = $this->stringOption($parsed['options'], 'reason');

        if ($proposalId === null || trim($proposalId) === '') {
            throw new ValidationException($root, null, null, 'proposal-retire requires proposal ID argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'proposal-retire requires --by actor option');
        }
        if ($reason === null || trim($reason) === '') {
            throw new ValidationException($root, null, null, 'proposal-retire requires --reason option');
        }

        (new ProposalTransitionManager())->retire($root, $proposalId, $actor, $reason);
        $this->write(sprintf("Retired proposal: %s\n", $proposalId));

        return 0;
    }

    private function helpCommand(): int
    {
        $this->write(
            "Usage: agent-learning <command> [options]\n\n"
            . "Commands:\n"
            . "  validate             Validate findings, proposals, and decision history.\n"
            . "  prepare              Build consolidation input for selected validated findings.\n"
            . "  proposal-validate    Validate one proposal against known findings.\n"
            . "  proposal-import      Import a consolidation result file as a candidate proposal.\n"
            . "  constraint-export    Export generation package files for a constraint proposal.\n"
            . "  constraint-activate  Write an active constraint manifest from an approved/applied proposal.\n"
            . "  constraint-loop      Export, apply, and activate a generated constraint proposal.\n"
            . "  guidance-evaluate    Project recall usage events and create reviewable candidate proposals.\n"
            . "  finding-transition   Transition a finding to a new state.\n"
            . "  proposal-approve     Approve a candidate proposal.\n"
            . "  proposal-reject      Reject a candidate proposal.\n"
            . "  proposal-mark-applied Mark an approved proposal as applied externally.\n"
            . "  proposal-retire      Retire an applied proposal once its target fully captures the change.\n\n"
            . "Options:\n"
            . "  --root PATH              Learning root or project root. Defaults to auto-discovery.\n"
            . "  --task-id-pattern REGEX  Override finding task id validation.\n"
            . "  --finding ID             Finding id selector for prepare. Repeatable.\n"
            . "  --task ID                Task id selector for prepare. Repeatable.\n"
            . "  --ticket ID              Alias for --task.\n"
            . "  --scope PATH             Scope selector for prepare. Repeatable.\n"
            . "  --guidance PATH          Path to an active guidance file. Repeatable.\n"
            . "  --since YYYY-MM-DD       Include findings created on or after this date.\n"
            . "  --until YYYY-MM-DD       Include findings created on or before this date.\n"
            . "  --allow-empty            Allow prepare to write a prompt with no selected findings.\n"
            . "  --proposal PATH          Proposal path for proposal-validate.\n"
            . "  --input PATH             Input file for proposal-import.\n"
            . "  --output PATH            Output file for prepare.\n"
            . "  --output-dir PATH        Output directory for constraint-export or constraint-loop.\n"
            . "  --project-root PATH      Project root used for constraint file checks and examples.\n"
            . "  --constraint-generation-dir PATH Base directory for generated constraint packages.\n"
            . "  --active-constraints-dir PATH Directory for active constraint manifests.\n"
            . "  --selection-history PATH Recall selection JSONL path for guidance-evaluate.\n"
            . "  --outcome-history PATH Guidance outcome JSONL path for guidance-evaluate.\n"
            . "  --write-candidates       Write only candidate proposal files from eligible decisions.\n"
            . "  --overwrite              Allow constraint-activate to replace an existing manifest.\n"
            . "  --approve-candidate      Allow constraint-loop to approve a candidate proposal before applying.\n"
            . "  --manifest PATH          Manifest output path for constraint-loop or constraint-activate.\n"
            . "  --by ACTOR               Actor performing the operation.\n"
            . "  --reason REASON          Reason for proposal rejection or retirement.\n"
            . "  --commit COMMIT          Commit hash or pull request reference.\n"
            . "  --validation PATH        Path to validation evidence JSON file.\n"
        );

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        $this->writeError('Unknown command: ' . $command . "\n");
        $this->helpCommand();

        return 1;
    }

    /**
     * @return array<string, Finding>
     */
    private function validateFindings(string $root, ?string $taskIdPattern): array
    {
        return (new LearningRepositoryValidator($this->findingLifecycle))->validateFindings($root, $taskIdPattern);
    }

    private function resolveProposalPath(string $proposalPath, string $root): string
    {
        if (is_file($proposalPath)) {
            return $proposalPath;
        }

        foreach (['candidate', 'approved', 'rejected', 'applied'] as $statusDirectory) {
            $candidate = $root . '/proposals/' . $statusDirectory . '/' . $proposalPath;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ValidationException($proposalPath, null, null, 'proposal file does not exist');
    }

    private function resolveProposalPathOrId(string $proposal, string $root): string
    {
        if (is_file($proposal) || str_ends_with($proposal, '.json')) {
            return $this->resolveProposalPath($proposal, $root);
        }

        return (new ProposalTransitionManager())->resolveProposalPath($proposal, $root);
    }

    private function resolveHistoryPath(string $root, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $root . '/' . ltrim($path, '/');
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{options: array<string, bool|string|list<string>>, arguments: list<string>}
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        $arguments = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }

            $option = substr($token, 2);
            $equalsPosition = strpos($option, '=');
            if ($equalsPosition !== false) {
                $this->addOption($options, substr($option, 0, $equalsPosition), substr($option, $equalsPosition + 1));
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $this->addOption($options, $option, $next);
                $index++;
                continue;
            }

            $this->addOption($options, $option, true);
        }

        return ['options' => $options, 'arguments' => $arguments];
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (is_array($value)) {
            $value = $value[count($value) - 1] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     *
     * @return list<string>
     */
    private function stringOptions(array $options, string $name): array
    {
        $value = $options[$name] ?? null;
        if ($value === null || $value === true) {
            return [];
        }
        if (is_string($value)) {
            return trim($value) === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (trim($item) !== '') {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function boolOption(array $options, string $name): bool
    {
        return ($options[$name] ?? false) === true;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     * @param bool|string                             $value
     */
    private function addOption(array &$options, string $name, bool|string $value): void
    {
        $existing = $options[$name] ?? null;
        if ($existing === null) {
            $options[$name] = $value;

            return;
        }

        $values = [];
        if (is_array($existing)) {
            $values = $existing;
        } elseif (is_string($existing)) {
            $values[] = $existing;
        }

        if (is_string($value)) {
            $values[] = $value;
            $options[$name] = $values;

            return;
        }

        $options[$name] = true;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     * @param list<string>                            $arguments
     */
    private function findingSelection(array $options, array $arguments, string $root): FindingSelection
    {
        $taskIds = $this->uniqueStrings([
            ...$this->stringOptions($options, 'task'),
            ...$this->stringOptions($options, 'ticket'),
        ]);
        if ($taskIds === [] && isset($arguments[0]) && trim($arguments[0]) !== '') {
            $taskIds[] = $arguments[0];
        }

        return new FindingSelection(
            $this->uniqueStrings($this->stringOptions($options, 'finding')),
            $taskIds,
            $this->uniqueStrings($this->stringOptions($options, 'scope')),
            $this->dateOption($options, 'since', $root),
            $this->dateOption($options, 'until', $root),
        );
    }

    /**
     * @param list<string> $values
     *
     * @return list<non-empty-string>
     */
    private function uniqueStrings(array $values): array
    {
        $seen = [];
        $unique = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $unique[] = $value;
        }

        return $unique;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function dateOption(array $options, string $name, string $root): ?DateTimeImmutable
    {
        $value = $this->stringOption($options, $name);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable) {
            throw new ValidationException($root, null, null, 'malformed date option --' . $name . ': ' . $value);
        }

        return $date;
    }

    /**
     * @param array<string, Finding> $findingsById
     *
     * @return list<Finding>
     */
    private function selectFindings(array $findingsById, FindingSelection $selection): array
    {
        ksort($findingsById);
        $selected = [];
        foreach ($findingsById as $finding) {
            if ($finding->status !== FindingStatus::VALIDATED || $finding->validationStatus !== 'validated') {
                continue;
            }
            if (!$this->matchesSelection($finding, $selection)) {
                continue;
            }
            $selected[$finding->id] = $finding;
        }

        return array_values($selected);
    }

    private function matchesSelection(Finding $finding, FindingSelection $selection): bool
    {
        $hasIdentitySelector = $selection->findingIds !== [] || $selection->taskIds !== [] || $selection->scopes !== [];
        $matchesIdentitySelector = !$hasIdentitySelector
            || in_array($finding->id, $selection->findingIds, true)
            || in_array($finding->taskId, $selection->taskIds, true)
            || $this->scopeMatches($finding->scope, $selection->scopes);
        if (!$matchesIdentitySelector) {
            return false;
        }

        $createdAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $finding->createdAt);
        if (!$createdAt instanceof DateTimeImmutable) {
            return false;
        }
        if ($selection->since instanceof DateTimeImmutable && $createdAt < $selection->since) {
            return false;
        }
        if ($selection->until instanceof DateTimeImmutable && $createdAt > $selection->until->setTime(23, 59, 59)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $findingScopes
     * @param list<string> $selectedScopes
     */
    private function scopeMatches(array $findingScopes, array $selectedScopes): bool
    {
        foreach ($selectedScopes as $selectedScope) {
            foreach ($findingScopes as $findingScope) {
                if ($findingScope === $selectedScope || str_starts_with($findingScope, rtrim($selectedScope, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function appendConsolidationAddendum(string $prompt, string $root): string
    {
        $templatePath = $root . '/templates/consolidation-prompt.md';
        if (!is_file($templatePath) || filesize($templatePath) === 0) {
            return $prompt;
        }

        $addendum = file_get_contents($templatePath);
        if ($addendum === false) {
            throw new ValidationException($templatePath, null, null, 'cannot read consolidation prompt addendum');
        }

        return rtrim($prompt) . "\n\n---\n\n" . trim($addendum) . "\n";
    }

    private function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    private function writeError(string $message): void
    {
        fwrite(STDERR, $message);
    }
}
