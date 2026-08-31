<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteCatalog
{
    public function __construct(
        private LearningNoteRepository $repository = new LearningNoteRepository(),
        private LearningNoteCodec $codec = new LearningNoteCodec(),
        private LearningNoteStatusInspector $statusInspector = new LearningNoteStatusInspector(),
    ) {
    }

    /** @return list<LearningNoteProjection> */
    public function all(string $root, ?string $projectRoot = null): array
    {
        return array_map(
            fn (LearningNote $note): LearningNoteProjection => $this->project($root, $note, $projectRoot),
            array_values($this->repository->loadAll($root)),
        );
    }

    /** @return list<LearningNoteProjection> */
    public function active(string $root, ?string $projectRoot = null): array
    {
        return array_map(
            fn (LearningNote $note): LearningNoteProjection => $this->project($root, $note, $projectRoot),
            $this->repository->active($root),
        );
    }

    public function find(string $root, string $id, ?string $projectRoot = null): ?LearningNoteProjection
    {
        $note = $this->repository->find($root, $id);

        return $note === null ? null : $this->project($root, $note, $projectRoot);
    }

    public function findActiveByPatternKey(string $root, string $patternKey, ?string $projectRoot = null): ?LearningNoteProjection
    {
        $note = $this->repository->findActiveByPatternKey($root, $patternKey);

        return $note === null ? null : $this->project($root, $note, $projectRoot);
    }

    private function project(string $root, LearningNote $note, ?string $projectRoot): LearningNoteProjection
    {
        return new LearningNoteProjection(
            id: $note->id,
            patternKey: $note->patternKey,
            status: $note->status,
            evidenceState: $this->statusInspector->inspect($root, $note, $projectRoot)->state,
            scope: $note->scope,
            tags: $note->tags,
            sourceFindings: $note->sourceFindings,
            content: $note->content,
            sourceDigest: $this->codec->digest($note),
        );
    }
}
