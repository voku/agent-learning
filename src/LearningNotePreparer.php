<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class LearningNotePreparer
{
    public function __construct(
        private readonly FindingRepository $findingRepository = new FindingRepository(),
        private readonly ProposalRepository $proposalRepository = new ProposalRepository(),
        private readonly LearningNoteRepository $noteRepository = new LearningNoteRepository(),
        private readonly LearningNoteCodec $codec = new LearningNoteCodec(),
        private readonly LearningNoteStatusInspector $statusInspector = new LearningNoteStatusInspector(),
    ) {
    }

    /** @param list<string> $findingIds */
    public function prepare(string $root, array $findingIds): LearningNotePreparation
    {
        if ($findingIds === []) {
            throw new ValidationException($root, null, null, 'LearningNote preparation requires at least one finding ID');
        }

        $allFindings = $this->findingRepository->loadAll($root);
        $findings = [];
        $patternKey = null;
        $validationCase = null;
        $scope = [];
        $tags = [];
        $repositoryEvidence = [];

        foreach (array_values(array_unique($findingIds)) as $findingId) {
            $finding = $allFindings[$findingId] ?? null;
            if (!$finding instanceof Finding) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source finding does not exist');
            }
            if (!in_array($finding->status, [FindingStatus::VALIDATED, FindingStatus::CONSOLIDATED], true)) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source finding must be validated or consolidated');
            }
            if ($finding->classification !== LearningClassification::ADD_LEARNING_NOTE) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source finding must be classified ADD_LEARNING_NOTE');
            }
            if ($finding->patternKey === null || !$finding->validationCase instanceof ValidationCase) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source finding requires pattern_key and validation_case');
            }
            if ($patternKey !== null && $patternKey !== $finding->patternKey) {
                throw new ValidationException($root, null, $findingId, 'LearningNote preparation cannot combine different pattern_key values');
            }
            if ($validationCase !== null && $validationCase->toArray() !== $finding->validationCase->toArray()) {
                throw new ValidationException($root, null, $findingId, 'LearningNote source findings disagree on validation_case');
            }

            $patternKey = $finding->patternKey;
            $validationCase = $finding->validationCase;
            $findings[] = $finding;
            $scope = array_merge($scope, $finding->scope);
            $rawTags = $finding->raw['tags'] ?? [];
            if (is_array($rawTags)) {
                foreach ($rawTags as $tag) {
                    if (is_string($tag) && trim($tag) !== '') {
                        $tags[] = trim($tag);
                    }
                }
            }
            foreach ($finding->evidence as $evidence) {
                foreach ($this->repositoryEvidence($evidence) as $item) {
                    $repositoryEvidence[$item->sourceRef . ':' . $item->contentSha256] = $item;
                }
            }
        }

        $relatedProposalIds = [];
        foreach ($this->proposalRepository->loadAll($root, $allFindings) as $proposal) {
            if ($proposal->patternKey === $patternKey || array_intersect($proposal->sourceFindings, $findingIds) !== []) {
                $relatedProposalIds[] = $proposal->id;
            }
        }
        sort($relatedProposalIds, SORT_STRING);
        $scope = array_values(array_unique($scope));
        sort($scope, SORT_STRING);
        $tags = array_values(array_unique($tags));
        sort($tags, SORT_STRING);
        ksort($repositoryEvidence, SORT_STRING);

        $existing = $this->noteRepository->findActiveByPatternKey($root, $patternKey);
        $existingProjection = $existing === null ? null : new LearningNoteProjection(
            id: $existing->id,
            patternKey: $existing->patternKey,
            status: $existing->status,
            evidenceState: $this->statusInspector->inspect($root, $existing)->state,
            scope: $existing->scope,
            tags: $existing->tags,
            sourceFindings: $existing->sourceFindings,
            content: $existing->content,
            sourceDigest: $this->codec->digest($existing),
        );

        return new LearningNotePreparation(
            patternKey: $patternKey,
            validationCase: $validationCase,
            findings: $findings,
            scope: $scope,
            tags: $tags,
            relatedProposalIds: $relatedProposalIds,
            repositoryEvidence: array_values($repositoryEvidence),
            existingNote: $existingProjection,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     * @return list<LearningNoteRepositoryEvidence>
     */
    private function repositoryEvidence(array $evidence): array
    {
        $candidates = [
            [$evidence['source_ref'] ?? null, $evidence['content_sha256'] ?? ($evidence['source_sha256'] ?? null)],
            [$evidence['target_source_ref'] ?? null, $evidence['target_content_hash'] ?? null],
        ];
        $result = [];
        foreach ($candidates as [$sourceRef, $hash]) {
            if (!is_string($sourceRef) || trim($sourceRef) === '' || !is_string($hash)) {
                continue;
            }
            $normalizedHash = strtolower(trim($hash));
            if (str_starts_with($normalizedHash, 'sha256:')) {
                $normalizedHash = substr($normalizedHash, 7);
            }
            if (preg_match('/^[a-f0-9]{64}$/', $normalizedHash) !== 1) {
                continue;
            }
            $result[] = new LearningNoteRepositoryEvidence(
                str_replace('\\', '/', trim($sourceRef)),
                $normalizedHash,
            );
        }

        return $result;
    }
}
