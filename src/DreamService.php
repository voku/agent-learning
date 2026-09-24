<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * The typed entry point for a Dream run.
 *
 * `agent-learning dream` and embedding owners (agent-loop, and through it
 * agent-ui) use this same composition, so a host never has to rebuild the
 * validator → evolution → suppression → evaluation sequence from CLI prose or
 * parse the CLI's report to learn what Dream decided.
 */
final class DreamService
{
    public function __construct(
        private readonly FindingLifecycle $findingLifecycle = new FindingLifecycle(),
        private readonly GuidanceCandidateProposalWriter $writer = new GuidanceCandidateProposalWriter(),
    ) {
    }

    public function run(DreamRequest $request): DreamOutcome
    {
        $root = $request->learningRoot;
        $projectRoot = (new LearningProjectPaths())->projectRootForLearningRoot($root, $request->projectRoot);
        $validation = (new LearningRepositoryValidator($this->findingLifecycle))->validate($root, $request->taskIdPattern);

        $baseEvolution = (new GuidanceEvolutionEvaluator())->evaluate(
            $validation->findingsById,
            $validation->proposalsById,
            $validation->recallSelectionEvents,
            $validation->guidanceOutcomeEvents,
        );
        $replacement = (new ReplacementCandidatePolicy())->evaluate($validation->proposalsById, $validation->findingsById);
        $conflicts = (new GuidanceConflictPolicy())->evaluate($validation->findingsById, $validation->proposalsById);
        $suppressedKeys = $this->writer->suppressedDecisionKeys($root, array_merge($baseEvolution->decisions, $replacement, $conflicts));

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
            $request->reviewHorizonDays,
        );

        $written = $request->writeCandidates
            ? $this->writer->write($root, $result->decisions, $validation->findingsById)
            : [];

        return new DreamOutcome(
            $result,
            $projection,
            $projectionRuntimeMilliseconds,
            $written,
            $request->writeCandidates,
        );
    }
}
