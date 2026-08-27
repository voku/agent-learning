<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
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
                'dream' => $this->dreamCommand($tokens),
                'history-rebuild' => $this->historyRebuildCommand($tokens),
                'history-status' => $this->historyStatusCommand($tokens),
                'backlog' => $this->backlogCommand($tokens),
                'finding-create' => $this->findingCreateCommand($tokens),
                'finding-id' => $this->findingIdCommand($tokens),
                'finding-transition' => $this->findingTransitionCommand($tokens),
                'proposal-approve' => $this->proposalApproveCommand($tokens),
                'proposal-reject' => $this->proposalRejectCommand($tokens),
                'proposal-acknowledge' => $this->proposalAcknowledgeCommand($tokens),
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
        $guidanceOutcomeEventRepository = new GuidanceOutcomeEventRepository();
        $outcomeEvents = $guidanceOutcomeEventRepository->load($root, $outcomeHistory);
        $legacyOutcomeCount = $guidanceOutcomeEventRepository->countLegacyRecords($root, $outcomeHistory);
        if ($legacyOutcomeCount > 0) {
            $this->write(sprintf(
                "⚠️  %d record(s) in %s use an older outcome shape (not \"guidance-outcome.*\") and are excluded from every statistic and decision below. Their recorded usage signal is not lost -- it is still stored -- but it currently cannot inform promotion/staleness decisions.\n",
                $legacyOutcomeCount,
                $outcomeHistory,
            ));
        }
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
     * Deterministically consolidate immutable learning evidence into a small review queue.
     *
     * @param list<string> $tokens
     */
    private function dreamCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $format = $this->stringOption($parsed['options'], 'format') ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new ValidationException($root, null, null, 'dream --format must be text or json');
        }
        $reviewHorizonDays = $this->positiveIntOption($parsed['options'], 'review-horizon-days', 90);
        $projectRoot = (new LearningProjectPaths())->projectRootForLearningRoot(
            $root,
            $this->stringOption($parsed['options'], 'project-root'),
        );
        $validation = (new LearningRepositoryValidator($this->findingLifecycle))->validate(
            $root,
            $this->stringOption($parsed['options'], 'task-id-pattern'),
        );
        $writer = new GuidanceCandidateProposalWriter();
        $baseEvolution = (new GuidanceEvolutionEvaluator())->evaluate(
            $validation->findingsById,
            $validation->proposalsById,
            $validation->recallSelectionEvents,
            $validation->guidanceOutcomeEvents,
        );
        $replacement = (new ReplacementCandidatePolicy())->evaluate($validation->proposalsById, $validation->findingsById);
        $conflicts = (new GuidanceConflictPolicy())->evaluate($validation->findingsById, $validation->proposalsById);
        $suppressedKeys = $writer->suppressedDecisionKeys($root, array_merge($baseEvolution->decisions, $replacement, $conflicts));
        $projectionStartedAt = hrtime(true);
        $projection = (new HistoryProjectionBuilder())->build($root, $validation->findingsById, $validation->proposalsById);
        $projectionRuntimeMilliseconds = intdiv(hrtime(true) - $projectionStartedAt, 1_000_000);
        $result = (new DreamingEvaluator())->evaluate(
            $validation->findingsById,
            $validation->proposalsById,
            $validation->recallSelectionEvents,
            $validation->guidanceOutcomeEvents,
            $suppressedKeys,
            $projectRoot,
            $reviewHorizonDays,
        );
        $report = $this->dreamReport(
            $result,
            $projection,
            $this->boolOption($parsed['options'], 'include-runtime') ? $projectionRuntimeMilliseconds : null,
        );
        $reportPath = $this->stringOption($parsed['options'], 'report');
        if ($reportPath !== null) {
            $this->writeDreamReport($reportPath, $report);
        }

        $written = [];
        if ($this->boolOption($parsed['options'], 'write-candidates') && !$this->boolOption($parsed['options'], 'dry-run')) {
            $written = $writer->write($root, $result->decisions, $validation->findingsById);
        }
        if ($format === 'json') {
            $this->write($report);
        } else {
            $this->write($this->dreamTextReport($result, $projection, $reportPath, $written, $this->boolOption($parsed['options'], 'dry-run')));
        }

        return 0;
    }

    /**
     * Explicitly write compact active and historical projections from immutable evidence.
     *
     * @param list<string> $tokens
     */
    private function historyRebuildCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $validation = (new LearningRepositoryValidator($this->findingLifecycle))->validate(
            $root,
            $this->stringOption($parsed['options'], 'task-id-pattern'),
        );
        $startedAt = hrtime(true);
        $projection = (new HistoryProjectionBuilder())->build($root, $validation->findingsById, $validation->proposalsById);
        $runtimeMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        if (!$this->boolOption($parsed['options'], 'dry-run')) {
            (new HistoryProjectionBuilder())->write($root, $projection);
        }

        $this->write(sprintf(
            "History projection %s: active=%d archived=%d source_files=%d source_bytes=%d projection_bytes=%d compression_ratio=%s rebuild_ms=%d source_digest=%s\n",
            $this->boolOption($parsed['options'], 'dry-run') ? 'previewed' : 'rebuilt',
            $projection->activeGuidanceRecordCount,
            $projection->archivedRecordCount,
            count($projection->sourceFiles),
            $projection->sourceBytes,
            $projection->projectionBytes(),
            $projection->compressionRatio() === null ? 'n/a' : number_format($projection->compressionRatio(), 3, '.', ''),
            $runtimeMilliseconds,
            $projection->inputDigest,
        ));

        return 0;
    }

    /**
     * Fail clearly if a compact history view no longer matches immutable evidence.
     *
     * @param list<string> $tokens
     */
    private function historyStatusCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $validation = (new LearningRepositoryValidator($this->findingLifecycle))->validate(
            $root,
            $this->stringOption($parsed['options'], 'task-id-pattern'),
        );
        $projection = (new HistoryProjectionBuilder())->assertFresh($root, $validation->findingsById, $validation->proposalsById);
        $this->write(sprintf(
            "History projection is fresh: active=%d archived=%d source_files=%d source_bytes=%d projection_bytes=%d compression_ratio=%s source_digest=%s\n",
            $projection->activeGuidanceRecordCount,
            $projection->archivedRecordCount,
            count($projection->sourceFiles),
            $projection->sourceBytes,
            $projection->projectionBytes(),
            $projection->compressionRatio() === null ? 'n/a' : number_format($projection->compressionRatio(), 3, '.', ''),
            $projection->inputDigest,
        ));

        return 0;
    }

    /**
     * Report validated findings that have not yet been consolidated into a proposal.
     *
     * This is the deterministic guard for the recurring "I only processed the
     * recent findings" failure: it exits non-zero while any validated finding is
     * still unconsolidated, so the learning loop cannot be declared done while a
     * backlog remains. Pass --allow-nonempty for a non-gating, informational listing.
     *
     * @param list<string> $tokens
     */
    private function backlogCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));

        $validated = (new FindingRepository())->loadValidated($root);
        ksort($validated);

        $this->write('Unconsolidated validated findings: ' . count($validated) . "\n");
        foreach ($validated as $finding) {
            $this->write(sprintf("- %s (task %s): %s\n", $finding->id, $finding->taskId, $finding->observation));
        }

        if ($validated === []) {
            $this->write("Backlog is clear.\n");

            return 0;
        }

        if ($this->boolOption($parsed['options'], 'allow-nonempty')) {
            return 0;
        }

        $this->writeError(
            'Learning backlog is not empty: ' . count($validated)
            . " validated finding(s) still need consolidation. Consolidate them, or pass --allow-nonempty for an informational listing.\n"
        );

        return 1;
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

        $proposalPath = $this->resolveProposalPathOrId($proposalPath, $root);
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

        $proposalPath = $this->resolveProposalPathOrId($proposalPath, $root);
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

        $proposalPath = $this->resolveProposalPathOrId($proposalPath, $root);
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
     * Create one schema-valid validated Finding through the package owner.
     *
     * @param list<string> $tokens
     */
    private function findingCreateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        if ($parsed['arguments'] !== []) {
            throw new ValidationException($root, null, null, 'finding-create takes no positional arguments');
        }

        $missingOptions = [];
        foreach ([
            'task' => '--task',
            'session' => '--session',
            'by' => '--by',
            'observation' => '--observation',
            'hypothesis' => '--hypothesis',
            'conclusion' => '--conclusion',
            'confidence' => '--confidence',
            'sensitivity' => '--sensitivity',
        ] as $name => $label) {
            if ($this->stringOption($parsed['options'], $name) === null) {
                $missingOptions[] = $label;
            }
        }
        if ($this->stringOptions($parsed['options'], 'evidence-json') === []) {
            $missingOptions[] = '--evidence-json';
        }
        if ($missingOptions !== []) {
            throw new ValidationException(
                $root,
                null,
                null,
                'finding-create missing required options: ' . implode(', ', $missingOptions),
            );
        }

        $taskId = $this->stringOption($parsed['options'], 'task');
        if ($taskId === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --task option');
        }
        $session = $this->stringOption($parsed['options'], 'session');
        if ($session === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --session option');
        }
        $actor = $this->stringOption($parsed['options'], 'by');
        if ($actor === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --by actor option');
        }
        $observation = $this->stringOption($parsed['options'], 'observation');
        if ($observation === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --observation option');
        }
        $hypothesis = $this->stringOption($parsed['options'], 'hypothesis');
        if ($hypothesis === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --hypothesis option');
        }
        $conclusion = $this->stringOption($parsed['options'], 'conclusion');
        if ($conclusion === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --conclusion option');
        }
        $confidence = $this->stringOption($parsed['options'], 'confidence');
        if ($confidence === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --confidence option');
        }
        $sensitivity = $this->stringOption($parsed['options'], 'sensitivity');
        if ($sensitivity === null) {
            throw new ValidationException($root, null, null, 'finding-create requires --sensitivity option');
        }

        $result = (new FindingCreator())->createValidated(
            root: $root,
            taskId: $taskId,
            session: $session,
            createdBy: $actor,
            scope: $this->uniqueStrings($this->stringOptions($parsed['options'], 'scope')),
            observation: $observation,
            evidence: $this->findingEvidence($parsed['options'], $root),
            hypothesis: $hypothesis,
            validatedConclusion: $conclusion,
            confidence: $confidence,
            sensitivity: $sensitivity,
            id: $this->stringOption($parsed['options'], 'id'),
            taskIdPattern: $this->stringOption($parsed['options'], 'task-id-pattern'),
        );

        $this->write(json_encode(
            ['id' => $result->finding->id, 'path' => $result->path],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");

        return 0;
    }

    /**
     * Allocate a fresh finding ID.
     *
     * Findings never had an allocator: every writer read the directory it could
     * see and picked the next number, which is unique only for a writer who can
     * see every other writer. Printing an ID is the smallest primitive that
     * removes the guess, so nothing has to hand-pick a suffix again.
     *
     * @param list<string> $tokens
     */
    private function findingIdCommand(array $tokens): int
    {
        // Deliberately no root lookup: allocating an ID needs no learning
        // repository, and requiring one would make the command unusable in the
        // moment it is most useful - before the first finding exists.
        $parsed = $this->parseOptions($tokens);
        if ($parsed['arguments'] !== []) {
            throw new InvalidArgumentException('finding-id takes no arguments');
        }

        $this->write((new RecordIdGenerator())->generate('finding') . "\n");

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
    private function proposalAcknowledgeCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalId = $parsed['arguments'][0] ?? null;
        $actor = $this->stringOption($parsed['options'], 'by');
        $reason = $this->stringOption($parsed['options'], 'reason');

        if ($proposalId === null || trim($proposalId) === '') {
            throw new ValidationException($root, null, null, 'proposal-acknowledge requires proposal ID argument');
        }
        if ($actor === null || trim($actor) === '') {
            throw new ValidationException($root, null, null, 'proposal-acknowledge requires --by actor option');
        }
        if ($reason === null || trim($reason) === '') {
            throw new ValidationException($root, null, null, 'proposal-acknowledge requires --reason option');
        }

        (new ProposalTransitionManager())->acknowledge($root, $proposalId, $actor, $reason);
        $this->write(sprintf("Acknowledged proposal: %s\n", $proposalId));

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
            . "  dream                Audit immutable evidence and render a deterministic guidance-maintenance review queue.\n"
            . "  history-rebuild      Explicitly write compact active-guidance and chronicle projections.\n"
            . "  history-status       Fail when compact history projections are missing, corrupt, or stale.\n"
            . "  backlog              List validated findings not yet consolidated; exits non-zero while any remain.\n"
            . "  finding-create       Create one validated Finding through the owner schema.\n"
            . "  finding-id           Allocate a collision-resistant finding ID.\n"
            . "  finding-transition   Transition a finding to a new state.\n"
            . "  proposal-approve     Approve a candidate proposal.\n"
            . "  proposal-reject      Reject a candidate proposal.\n"
            . "  proposal-acknowledge Formally close a candidate NO_DURABLE_LEARNING proposal without approving or rejecting it.\n"
            . "  proposal-mark-applied Mark an approved proposal as applied externally.\n"
            . "  proposal-retire      Retire an applied proposal once its target fully captures the change.\n\n"
            . "Options:\n"
            . "  --root PATH              Learning root or project root. Defaults to auto-discovery.\n"
            . "  --task-id-pattern REGEX  Override finding task id validation.\n"
            . "  --finding ID             Finding id selector for prepare. Repeatable.\n"
            . "  --id ID                  Optional explicit ID for finding-create.\n"
            . "  --task ID                Task id for finding-create or selector for prepare. Repeatable.\n"
            . "  --ticket ID              Alias for --task when selecting findings.\n"
            . "  --session ID             Source session for finding-create.\n"
            . "  --scope PATH             Finding scope or prepare selector. Repeatable.\n"
            . "  --observation TEXT       Observed fact for finding-create.\n"
            . "  --hypothesis TEXT        Inferred explanation for finding-create.\n"
            . "  --conclusion TEXT        Validated conclusion for finding-create.\n"
            . "  --confidence LEVEL       low, medium, or high for finding-create.\n"
            . "  --sensitivity VALUE      Explicit sensitivity for finding-create.\n"
            . "  --evidence-json JSON     Evidence object for finding-create. Repeatable.\n"
            . "  --guidance PATH          Path to an active guidance file. Repeatable.\n"
            . "  --since YYYY-MM-DD       Include findings created on or after this date.\n"
            . "  --until YYYY-MM-DD       Include findings created on or before this date.\n"
            . "  --allow-empty            Allow prepare to write a prompt with no selected findings.\n"
            . "  --allow-nonempty         Make backlog informational (exit 0) instead of gating on a non-empty backlog.\n"
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
            . "  --dry-run                For dream: render the review queue without writing candidate proposals.\n"
            . "  --report PATH            For dream: write the deterministic JSON report to PATH.\n"
            . "  --format text|json       For dream: select human or machine-readable output.\n"
            . "  --project-root PATH      For dream: resolve file-reference evidence against this project root.\n"
            . "  --review-horizon-days N  For dream: warn about candidate/validated findings older than N days (default 90).\n"
            . "  --include-runtime    For dream: include a volatile projection rebuild measurement in the JSON report.\n"
            . "  --overwrite              Allow constraint-activate to replace an existing manifest.\n"
            . "  --approve-candidate      Allow constraint-loop to approve a candidate proposal before applying.\n"
            . "  --manifest PATH          Manifest output path for constraint-loop or constraint-activate.\n"
            . "  --by ACTOR               Actor performing the operation.\n"
            . "  --reason REASON          Reason for proposal rejection, retirement, or acknowledgement.\n"
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
     */
    private function positiveIntOption(array $options, string $name, int $default): int
    {
        $value = $this->stringOption($options, $name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ValidationException('', null, null, '--' . $name . ' requires a positive integer');
        }

        return (int)$value;
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
     *
     * @return list<array<string, mixed>>
     */
    private function findingEvidence(array $options, string $root): array
    {
        $evidence = [];
        foreach ($this->stringOptions($options, 'evidence-json') as $index => $encoded) {
            try {
                $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ValidationException(
                    $root,
                    null,
                    null,
                    'finding-create --evidence-json #' . ($index + 1) . ' is malformed JSON: ' . $exception->getMessage(),
                );
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new ValidationException(
                    $root,
                    null,
                    null,
                    'finding-create --evidence-json #' . ($index + 1) . ' must be a JSON object',
                );
            }
            foreach (array_keys($decoded) as $key) {
                if (!is_string($key)) {
                    throw new ValidationException(
                        $root,
                        null,
                        null,
                        'finding-create --evidence-json #' . ($index + 1) . ' must use string object keys',
                    );
                }
            }

            /** @var array<string, mixed> $decoded */
            $evidence[] = $decoded;
        }

        return $evidence;
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

    private function dreamReport(DreamRunResult $result, HistoryProjection $projection, ?int $projectionRuntimeMilliseconds): string
    {
        $report = new \stdClass();
        $report->schema_version = '1.0';
        $report->report_type = 'agent-learning-dream';
        $report->evaluated_guidance_count = $result->evaluatedGuidanceCount;
        $report->warnings = array_map(static function (DreamWarning $warning): \stdClass {
            $item = new \stdClass();
            $item->code = $warning->code;
            $item->message = $warning->message;
            $item->evidence_ids = $warning->evidenceIds;
            $item->remediation = $warning->remediation;

            return $item;
        }, $result->warnings);
        $report->decisions = array_map(fn (EvolutionDecision $decision): \stdClass => $this->dreamDecision($decision), $result->decisions);
        $report->suppressed_decisions = array_map(fn (EvolutionDecision $decision): \stdClass => $this->dreamDecision($decision), $result->suppressedDecisions);
        $metrics = new \stdClass();
        $metrics->selected_guidance_count = $result->metrics->selectedGuidanceCount;
        $metrics->explicit_outcome_count = $result->metrics->explicitOutcomeCount;
        $metrics->outcome_completeness_rate = $result->metrics->outcomeCompletenessRate;
        $metrics->candidate_queue_count = $result->metrics->candidateQueueCount;
        $metrics->oldest_candidate_age_days = $result->metrics->oldestCandidateAgeDays;
        $metrics->stale_candidate_count = $result->metrics->staleCandidateCount;
        $metrics->stale_candidate_rate = $result->metrics->staleCandidateRate;
        $metrics->suppressed_decision_count = $result->metrics->suppressedDecisionCount;
        $metrics->duplicate_decision_count = $result->metrics->duplicateDecisionCount;
        $metrics->reviewable_decision_count = $result->metrics->reviewableDecisionCount;
        $metrics->median_finding_to_decision_hours = $result->metrics->medianFindingToDecisionHours;
        $metrics->active_guidance_by_tier = $result->metrics->activeGuidanceByTier;
        $metrics->outcome_signals = $result->metrics->outcomeSignals;
        $report->metrics = $metrics;
        $historyProjection = new \stdClass();
        $historyProjection->schema_version = '1.0';
        $historyProjection->source_digest = $projection->inputDigest;
        $historyProjection->active_guidance_record_count = $projection->activeGuidanceRecordCount;
        $historyProjection->archived_record_count = $projection->archivedRecordCount;
        $historyProjection->files_read = count($projection->sourceFiles);
        $historyProjection->bytes_read = $projection->sourceBytes;
        $historyProjection->projection_bytes = $projection->projectionBytes();
        $historyProjection->compression_ratio = $projection->compressionRatio();
        $historyProjection->rebuild_runtime_milliseconds = $projectionRuntimeMilliseconds;
        $report->history_projection = $historyProjection;
        $report->remaining_uncertainty = $result->remainingUncertainty;

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function dreamDecision(EvolutionDecision $decision): \stdClass
    {
        $item = new \stdClass();
        $item->decision_key = $decision->stableKey();
        $item->type = $decision->type->value;
        $item->guidance_id = $decision->guidanceId;
        $item->source_tier = $decision->sourceTier->value;
        $item->target_tier = $decision->targetTier?->value;
        $item->evidence_event_count = count($decision->evidenceEventIds);
        $item->evidence_event_ids = $this->boundedStrings($decision->evidenceEventIds);
        $item->independent_task_count = count($decision->independentTaskIds);
        $item->independent_task_ids = $this->boundedStrings($decision->independentTaskIds);
        $item->reason = $decision->reason;
        $item->remaining_uncertainty = $decision->remainingUncertainty;
        $item->source_finding_count = count($decision->sourceFindings);
        $item->source_findings = $this->boundedStrings($decision->sourceFindings);
        $item->proposal_action = $decision->proposalAction?->value;
        $item->old_text = $decision->oldText;
        $item->new_text = $decision->newText;

        return $item;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function boundedStrings(array $values): array
    {
        sort($values);

        return array_slice($values, 0, 20);
    }

    private function writeDreamReport(string $path, string $report): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ValidationException($path, null, null, 'cannot create dream report directory');
        }
        if (file_put_contents($path, $report) === false) {
            throw new ValidationException($path, null, null, 'cannot write dream report');
        }
    }

    /**
     * @param list<string> $written
     */
    private function dreamTextReport(DreamRunResult $result, HistoryProjection $projection, ?string $reportPath, array $written, bool $dryRun): string
    {
        $lines = [
            'Dream maintenance review',
            'Evaluated guidance: ' . $result->evaluatedGuidanceCount,
            'Warnings: ' . count($result->warnings),
            'Review decisions: ' . count($result->decisions),
            'Suppressed unchanged decisions: ' . count($result->suppressedDecisions),
            // Printed because guidance selected but never judged is the state
            // every promotion and staleness gate silently waits on. It was
            // already computed and only reachable through --format json, so a
            // reader of the default output could not tell a repository with real
            // usefulness evidence from one with none.
            'Outcome completeness: ' . $result->metrics->explicitOutcomeCount . '/' . $result->metrics->selectedGuidanceCount
                . ' selected guidance judged'
                . ($result->metrics->selectedGuidanceCount === 0 ? '' : ' (' . round(($result->metrics->outcomeCompletenessRate ?? 0.0) * 100) . '%)'),
            'History projection: active=' . $projection->activeGuidanceRecordCount . ' archived=' . $projection->archivedRecordCount . ' files=' . count($projection->sourceFiles) . ' bytes=' . $projection->sourceBytes,
        ];
        foreach ($result->warnings as $warning) {
            $evidenceIds = array_slice($warning->evidenceIds, 0, 5);
            $suffix = count($warning->evidenceIds) > count($evidenceIds) ? ', …' : '';
            $lines[] = '- warning ' . $warning->code . ': ' . $warning->message . ' [' . implode(', ', $evidenceIds) . $suffix . ']';
        }
        foreach ($result->decisions as $decision) {
            $lines[] = '- ' . $decision->type->value . ' ' . $decision->guidanceId . ': ' . $decision->reason;
        }
        if ($reportPath !== null) {
            $lines[] = 'Report: ' . $reportPath;
        }
        if ($dryRun) {
            $lines[] = 'Dry run: no candidate proposals were written.';
        } elseif ($written !== []) {
            $lines[] = 'Candidate proposals written: ' . implode(', ', $written);
        }
        $lines[] = 'Uncertainty: ' . $result->remainingUncertainty;

        return implode("\n", $lines) . "\n";
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
