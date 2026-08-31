<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class LearningNoteService
{
    public function __construct(
        private FindingRepository $findingRepository = new FindingRepository(),
        private ProposalRepository $proposalRepository = new ProposalRepository(),
        private LearningNoteRepository $noteRepository = new LearningNoteRepository(),
        private RecordIdGenerator $idGenerator = new RecordIdGenerator(),
        private RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /** @param non-empty-list<string> $findingIds */
    public function prepare(string $root, array $findingIds, ?string $projectRoot = null): LearningNotePreparation
    {
        $findingsById = $this->findingRepository->loadAll($root);
        $selected = [];
        foreach (array_values(array_unique($findingIds)) as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if ($finding === null) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding does not exist');
            }
            if (!in_array($finding->status, [FindingStatus::VALIDATED, FindingStatus::CONSOLIDATED], true)) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding must be validated or consolidated');
            }
            if ($finding->classification !== LearningClassification::ADD_LEARNING_NOTE) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding must be classified ADD_LEARNING_NOTE');
            }
            if ($finding->patternKey === null || trim($finding->patternKey) === '') {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding requires pattern_key');
            }
            if ($finding->validationCase === null) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding requires validation_case');
            }
            if ($finding->validatedConclusion === null || trim($finding->validatedConclusion) === '') {
                throw new ValidationException($root, null, $findingId, 'LearningNote source Finding requires validated_conclusion');
            }
            $selected[] = $finding;
        }
        if ($selected === []) {
            throw new ValidationException($root, null, null, 'LearningNote prepare requires at least one Finding');
        }

        $patternKey = $selected[0]->patternKey;
        $validationCase = $selected[0]->validationCase;
        foreach ($selected as $finding) {
            if ($finding->patternKey !== $patternKey) {
                throw new ValidationException($root, null, $finding->id, 'LearningNote prepare cannot merge different pattern_key values');
            }
            if ($finding->validationCase?->toArray() !== $validationCase?->toArray()) {
                throw new ValidationException($root, null, $finding->id, 'LearningNote source Findings disagree on validation_case');
            }
        }
        if ($patternKey === null || $validationCase === null) {
            throw new ValidationException($root, null, null, 'LearningNote prepare lost required classification metadata');
        }

        $scope = [];
        $findingPayloads = [];
        foreach ($selected as $finding) {
            $scope = array_merge($scope, $finding->scope);
            $findingPayloads[] = [
                'id' => $finding->id,
                'task_id' => $finding->taskId,
                'scope' => $finding->scope,
                'observation' => $finding->observation,
                'hypothesis' => $finding->hypothesis,
                'validated_conclusion' => (string) $finding->validatedConclusion,
                'evidence' => $finding->evidence,
            ];
        }
        $scope = array_values(array_unique($scope));
        sort($scope, SORT_STRING);

        $projectRoot ??= (new LearningProjectPaths())->projectRootForLearningRoot($root);
        $existing = $this->noteRepository->findActiveByPatternKey($root, $patternKey);
        $existingProjection = $existing === null ? null : $this->project($existing, $projectRoot);
        $overlapCandidates = [];
        foreach ($this->noteRepository->loadActive($root) as $note) {
            if ($existing !== null && $note->id === $existing->id) {
                continue;
            }
            $scopeOverlap = array_values(array_intersect($scope, $note->scope));
            if ($scopeOverlap === []) {
                continue;
            }
            sort($scopeOverlap, SORT_STRING);
            $overlapCandidates[] = [
                'id' => $note->id,
                'pattern_key' => $note->patternKey,
                'scope_overlap' => $scopeOverlap,
                'tag_overlap' => [],
            ];
        }
        usort($overlapCandidates, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return new LearningNotePreparation(
            patternKey: $patternKey,
            validationCase: $validationCase,
            scope: $scope,
            findings: $findingPayloads,
            existingNote: $existingProjection,
            overlapCandidates: $overlapCandidates,
        );
    }

    public function publish(string $root, LearningNoteDraft $draft, ?string $projectRoot = null): LearningNoteProjection
    {
        $preparation = $this->prepare($root, $draft->sourceFindings, $projectRoot);
        $findingsById = $this->findingRepository->loadAll($root);
        $proposalsById = $this->proposalRepository->loadAll($root, $findingsById);
        foreach ($draft->sourceProposals as $proposalId) {
            if (!isset($proposalsById[$proposalId])) {
                throw new ValidationException($root, null, $proposalId, 'LearningNote source Proposal does not exist');
            }
        }

        $existing = $this->noteRepository->findActiveByPatternKey($root, $preparation->patternKey);
        if ($existing !== null && $draft->id !== null && $draft->id !== $existing->id) {
            throw new ValidationException($root, null, $draft->id, 'active pattern_key is already owned by LearningNote ' . $existing->id);
        }
        if ($existing === null && $draft->id !== null && $this->noteRepository->find($root, $draft->id) !== null) {
            throw new ValidationException($root, null, $draft->id, 'LearningNote ID already exists');
        }

        $id = $existing?->id ?? $draft->id ?? $this->idGenerator->generate('learning-note');
        $now = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $tags = array_values(array_unique(array_map('trim', $draft->tags)));
        $tags = array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));
        sort($tags, SORT_STRING);
        $sourceFindings = array_values(array_unique($draft->sourceFindings));
        sort($sourceFindings, SORT_STRING);
        $sourceProposals = array_values(array_unique($draft->sourceProposals));
        sort($sourceProposals, SORT_STRING);

        $note = new LearningNote(
            id: $id,
            patternKey: $preparation->patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: $preparation->scope,
            tags: $tags,
            sourceFindings: $sourceFindings,
            sourceProposals: $sourceProposals,
            validationCase: $preparation->validationCase,
            repositoryEvidence: $draft->repositoryEvidence,
            content: $draft->content,
            createdAt: $existing?->createdAt ?? $now,
            updatedAt: $now,
        );
        $this->redactionGuard->assertSafeValue($note->toArray(), 'LearningNote ' . $id, null, $id);
        $this->noteRepository->publish($root, $note);

        $projectRoot ??= (new LearningProjectPaths())->projectRootForLearningRoot($root);

        return $this->project($note, $projectRoot);
    }

    public function retire(string $root, string $id, string $reason): LearningNoteProjection
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException($root, null, $id, 'LearningNote retirement requires a reason');
        }
        $existing = $this->noteRepository->find($root, $id);
        if ($existing === null || $existing->status !== LearningNoteStatus::ACTIVE) {
            throw new ValidationException($root, null, $id, 'active LearningNote not found');
        }
        $retired = new LearningNote(
            id: $existing->id,
            patternKey: $existing->patternKey,
            status: LearningNoteStatus::RETIRED,
            scope: $existing->scope,
            tags: $existing->tags,
            sourceFindings: $existing->sourceFindings,
            sourceProposals: $existing->sourceProposals,
            validationCase: $existing->validationCase,
            repositoryEvidence: $existing->repositoryEvidence,
            content: $existing->content,
            createdAt: $existing->createdAt,
            updatedAt: (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            retiredReason: $reason,
        );
        $this->redactionGuard->assertSafeValue($retired->toArray(), 'LearningNote ' . $id, null, $id);
        $this->noteRepository->publish($root, $retired);
        $this->noteRepository->removeActive($root, $id);
        $projectRoot = (new LearningProjectPaths())->projectRootForLearningRoot($root);

        return $this->project($retired, $projectRoot);
    }

    /** @return list<LearningNoteProjection> */
    public function activeProjections(string $root, ?string $projectRoot = null): array
    {
        $projectRoot ??= (new LearningProjectPaths())->projectRootForLearningRoot($root);
        $result = [];
        foreach ($this->noteRepository->loadActive($root) as $note) {
            $result[] = $this->project($note, $projectRoot);
        }
        usort($result, static fn (LearningNoteProjection $left, LearningNoteProjection $right): int => $left->id <=> $right->id);

        return $result;
    }

    public function evidenceState(LearningNote $note, string $projectRoot): LearningNoteEvidenceState
    {
        if ($note->repositoryEvidence === []) {
            return LearningNoteEvidenceState::NO_HASHABLE_REPOSITORY_EVIDENCE;
        }
        $changed = false;
        foreach ($note->repositoryEvidence as $evidence) {
            $path = rtrim($projectRoot, '/\\') . '/' . $evidence->sourceRef;
            if (!is_file($path)) {
                return LearningNoteEvidenceState::SOURCE_MISSING;
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                return LearningNoteEvidenceState::SOURCE_MISSING;
            }
            if (!hash_equals($evidence->sha256, $hash)) {
                $changed = true;
            }
        }

        return $changed ? LearningNoteEvidenceState::REVIEW_NEEDED : LearningNoteEvidenceState::CURRENT;
    }

    private function project(LearningNote $note, string $projectRoot): LearningNoteProjection
    {
        return new LearningNoteProjection(
            id: $note->id,
            patternKey: $note->patternKey,
            status: $note->status,
            scope: $note->scope,
            tags: $note->tags,
            sourceFindings: $note->sourceFindings,
            title: $note->content->title,
            guidance: $note->content->guidance,
            digest: $note->digest(),
            evidenceState: $this->evidenceState($note, $projectRoot),
        );
    }
}
