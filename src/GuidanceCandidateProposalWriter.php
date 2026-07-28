<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final class GuidanceCandidateProposalWriter
{
    public function __construct(
        private readonly ProposalIdGenerator $idGenerator = new ProposalIdGenerator(),
        private readonly ProposalValidator $proposalValidator = new ProposalValidator(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param list<EvolutionDecision> $decisions
     * @param array<string, Finding> $findingsById
     * @return list<string>
     */
    public function write(string $root, array $decisions, array $findingsById): array
    {
        $written = [];
        foreach ($decisions as $decision) {
            if (!$this->isWritable($decision)) {
                continue;
            }
            $existing = $this->findExistingCandidate($root, $decision);
            if ($existing !== null) {
                $written[] = $existing;
                continue;
            }

            $proposalId = $this->idGenerator->generate($root);
            $record = $this->proposalRecord($proposalId, $decision);
            $this->redactionGuard->assertSafeValue($record, $root . '/proposals/candidate/' . $proposalId . '.json', null, $proposalId);
            $proposal = (new ProposalParser())->parseRecord($record, $proposalId . '.json');
            $this->proposalValidator->validate($proposal, $proposalId . '.json', null, $findingsById);

            $candidateDir = $root . '/proposals/candidate';
            if (!is_dir($candidateDir) && !mkdir($candidateDir, 0777, true) && !is_dir($candidateDir)) {
                throw new ValidationException($candidateDir, null, null, 'cannot create proposals/candidate directory');
            }
            $path = $candidateDir . '/' . $proposalId . '.json';
            $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $encoded . "\n") === false) {
                throw new ValidationException($path, null, $proposalId, 'cannot write candidate proposal');
            }
            $written[] = $proposalId;
        }

        return $written;
    }

    private function isWritable(EvolutionDecision $decision): bool
    {
        return in_array($decision->type, [
            EvolutionDecisionType::PROMOTION_CANDIDATE,
            EvolutionDecisionType::STALE_CANDIDATE,
            EvolutionDecisionType::REPLACEMENT_CANDIDATE,
        ], true)
            && $decision->proposalAction instanceof Action
            && $decision->sourceFindings !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalRecord(string $proposalId, EvolutionDecision $decision): array
    {
        $action = $decision->proposalAction ?? Action::ADD;
        $targetTier = $decision->targetTier ?? $decision->sourceTier;
        $record = [
            'schema_version' => '1.0',
            'id' => $proposalId,
            'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'action' => $action->value,
            'target_type' => $targetTier->value,
            'target' => $this->targetName($decision),
            'scope' => $decision->proposedScope,
            'source_findings' => $decision->sourceFindings,
            'reason' => $decision->reason,
            'boundary' => 'Candidate generated from immutable recall-selection and guidance-outcome event projection; human review is required.',
            'validation' => $decision->validationRequirements === [] ? ['manual review required'] : $decision->validationRequirements,
            'status' => ProposalStatus::CANDIDATE->value,
            'proposed_by' => 'guidance-evaluate',
            'evolution_decision' => [
                'decision_type' => $decision->type->value,
                'guidance_id' => $decision->guidanceId,
                'source_tier' => $decision->sourceTier->value,
                'target_tier' => $decision->targetTier?->value,
                'evidence_event_ids' => $decision->evidenceEventIds,
                'independent_task_ids' => $decision->independentTaskIds,
                'remaining_uncertainty' => $decision->remainingUncertainty,
            ],
        ];
        if ($decision->oldText !== null) {
            $record['old'] = $decision->oldText;
        }
        if ($decision->newText !== null) {
            $record['new'] = $decision->newText;
        }
        foreach ($decision->proposalExtras as $key => $value) {
            $record[$key] = $value;
        }

        return $record;
    }

    private function targetName(EvolutionDecision $decision): string
    {
        if ($decision->targetTier instanceof GuidanceType) {
            return $decision->targetTier->value . '.' . $this->suffix($decision->guidanceId);
        }

        return $decision->guidanceId;
    }

    private function suffix(string $guidanceId): string
    {
        $parts = explode('.', $guidanceId);
        $suffix = end($parts);
        if ($suffix === '') {
            return $guidanceId;
        }

        return $suffix;
    }

    /**
     * Directories whose contents suppress regeneration of the same evolution decision.
     *
     * `candidate` keeps the writer idempotent within the review queue. `rejected` and
     * `acknowledged` are terminal human decisions: without them, declining a candidate only
     * removes it from `candidate/`, so the very next `guidance-evaluate --write-candidates`
     * run recreates an identical proposal under a fresh ID and the reviewer's "no" is
     * discarded on every run.
     *
     * @var list<string>
     */
    private const array DECIDED_DIRECTORIES = ['candidate', 'rejected', 'acknowledged'];

    private function findExistingCandidate(string $root, EvolutionDecision $decision): ?string
    {
        foreach (self::DECIDED_DIRECTORIES as $directory) {
            $dir = $root . '/proposals/' . $directory;
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*.json');
            if ($files === false) {
                continue;
            }
            sort($files);
            foreach ($files as $file) {
                $data = (new Json())->decodeObjectFile($file);
                $evolution = $data['evolution_decision'] ?? null;
                if (!is_array($evolution)) {
                    continue;
                }
                if (
                    ($evolution['guidance_id'] ?? null) === $decision->guidanceId
                    &&
                    ($evolution['decision_type'] ?? null) === $decision->type->value
                    &&
                    ($evolution['source_tier'] ?? null) === $decision->sourceTier->value
                    &&
                    ($evolution['target_tier'] ?? null) === $decision->targetTier?->value
                ) {
                    return pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return null;
    }
}
