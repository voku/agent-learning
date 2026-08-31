<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final readonly class LearningNotePublisher
{
    public function __construct(
        private LearningNotePreparer $preparer = new LearningNotePreparer(),
        private LearningNoteRepository $repository = new LearningNoteRepository(),
        private LearningNoteCodec $codec = new LearningNoteCodec(),
        private RecordIdGenerator $idGenerator = new RecordIdGenerator(),
        private RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param list<string>                         $sourceFindingIds
     * @param list<string>                         $sourceProposalIds
     * @param list<string>                         $scope
     * @param list<string>                         $tags
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
     */
    public function publish(
        string $root,
        array $sourceFindingIds,
        LearningNoteContent $content,
        array $sourceProposalIds = [],
        array $scope = [],
        array $tags = [],
        array $repositoryEvidence = [],
        ?string $id = null,
    ): LearningNotePublicationResult {
        $prepared = $this->preparer->prepare($root, $sourceFindingIds);
        $existing = $this->repository->findActiveByPatternKey($root, $prepared->patternKey);
        if ($existing !== null && $id !== null && $id !== $existing->id) {
            throw new ValidationException($root, null, $id, 'active LearningNote pattern_key is already owned by ' . $existing->id);
        }
        if ($existing === null && $id !== null && $this->repository->find($root, $id) !== null) {
            throw new ValidationException($root, null, $id, 'LearningNote id already exists');
        }

        $allowedProposalIds = $prepared->relatedProposalIds;
        foreach ($sourceProposalIds as $proposalId) {
            if (!in_array($proposalId, $allowedProposalIds, true)) {
                throw new ValidationException($root, null, $proposalId, 'LearningNote source proposal is not related to the selected findings/pattern');
            }
        }

        $now = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $noteId = $existing?->id ?? $id ?? $this->idGenerator->generate('learning-note');
        $createdAt = $existing?->createdAt ?? $now;
        $mergedFindings = $this->sortedUnique(array_merge($existing?->sourceFindings ?? [], $sourceFindingIds));
        $mergedProposals = $this->sortedUnique(array_merge($existing?->sourceProposals ?? [], $sourceProposalIds));
        $mergedScope = $this->sortedUnique(array_merge($prepared->scope, $existing?->scope ?? [], $scope));
        $mergedTags = $this->sortedUnique(array_merge($prepared->tags, $existing?->tags ?? [], $tags));
        $mergedEvidence = $this->mergeEvidence(
            $prepared->repositoryEvidence,
            $existing?->repositoryEvidence ?? [],
            $repositoryEvidence,
        );

        $note = new LearningNote(
            id: $noteId,
            schemaVersion: LearningNoteCodec::SCHEMA_VERSION,
            patternKey: $prepared->patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: $mergedScope,
            tags: $mergedTags,
            sourceFindings: $mergedFindings,
            sourceProposals: $mergedProposals,
            validationCase: $prepared->validationCase,
            repositoryEvidence: $mergedEvidence,
            content: $content,
            createdAt: $createdAt,
            updatedAt: $now,
        );
        $this->redactionGuard->assertSafeValue($note->toArray(), $root, null, $note->id);
        $encoded = $this->codec->encode($note);
        $directory = $root . '/notes/active';
        $path = $directory . '/' . $note->id . '.json';
        $this->writeAtomically($directory, $path, $encoded, $note->id);

        return new LearningNotePublicationResult($note, $path, $this->codec->digest($note));
    }

    public function retire(string $root, string $id, string $reason): LearningNotePublicationResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException($root, null, $id, 'LearningNote retirement requires a reason');
        }
        $note = $this->repository->find($root, $id);
        if (!$note instanceof LearningNote || $note->status !== LearningNoteStatus::ACTIVE) {
            throw new ValidationException($root, null, $id, 'active LearningNote not found');
        }
        $now = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $retired = new LearningNote(
            id: $note->id,
            schemaVersion: $note->schemaVersion,
            patternKey: $note->patternKey,
            status: LearningNoteStatus::RETIRED,
            scope: $note->scope,
            tags: $note->tags,
            sourceFindings: $note->sourceFindings,
            sourceProposals: $note->sourceProposals,
            validationCase: $note->validationCase,
            repositoryEvidence: $note->repositoryEvidence,
            content: $note->content,
            createdAt: $note->createdAt,
            updatedAt: $now,
            retiredAt: $now,
            retiredReason: $reason,
        );
        $this->redactionGuard->assertSafeValue($retired->toArray(), $root, null, $retired->id);

        $activePath = $root . '/notes/active/' . $id . '.json';
        $retiredDirectory = $root . '/notes/retired';
        $retiredPath = $retiredDirectory . '/' . $id . '.json';
        if (!is_file($activePath) || is_link($activePath)) {
            throw new ValidationException($activePath, null, $id, 'LearningNote active file is missing or unsafe');
        }
        if ($this->filesystemEntryExists($retiredPath)) {
            throw new ValidationException($retiredPath, null, $id, 'retired LearningNote file already exists');
        }
        if (!is_dir($retiredDirectory) && !mkdir($retiredDirectory, 0777, true) && !is_dir($retiredDirectory)) {
            throw new ValidationException($retiredDirectory, null, $id, 'cannot create notes/retired directory');
        }

        $backup = $root . '/notes/.retire.' . $id . '.' . bin2hex(random_bytes(8));
        if (!rename($activePath, $backup)) {
            throw new ValidationException($activePath, null, $id, 'cannot stage LearningNote retirement');
        }
        try {
            $this->writeAtomically($retiredDirectory, $retiredPath, $this->codec->encode($retired), $id);
        } catch (Throwable $throwable) {
            if (!rename($backup, $activePath)) {
                throw new ValidationException($activePath, null, $id, 'LearningNote retirement failed and active state could not be restored: ' . $throwable->getMessage());
            }
            throw $throwable;
        }
        if (!unlink($backup)) {
            throw new ValidationException($backup, null, $id, 'LearningNote retired but staged active file could not be removed');
        }

        return new LearningNotePublicationResult($retired, $retiredPath, $this->codec->digest($retired));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $result[] = $value;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param list<LearningNoteRepositoryEvidence> ...$groups
     * @return list<LearningNoteRepositoryEvidence>
     */
    private function mergeEvidence(array ...$groups): array
    {
        $items = [];
        foreach ($groups as $group) {
            foreach ($group as $item) {
                $items[$item->sourceRef . ':' . $item->contentSha256] = $item;
            }
        }
        ksort($items, SORT_STRING);

        return array_values($items);
    }

    private function writeAtomically(string $directory, string $path, string $content, string $id): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ValidationException($directory, null, $id, 'cannot create LearningNote directory');
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ValidationException($path, null, $id, 'LearningNote target path is unsafe');
        }
        $temporaryPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
        $handle = fopen($temporaryPath, 'xb');
        if ($handle === false) {
            throw new ValidationException($temporaryPath, null, $id, 'cannot create temporary LearningNote file');
        }
        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new ValidationException($temporaryPath, null, $id, 'cannot write temporary LearningNote file');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new ValidationException($temporaryPath, null, $id, 'cannot flush temporary LearningNote file');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            $this->removeTemporaryFile($temporaryPath, $id, $throwable);
            throw $throwable;
        }
        if (!fclose($handle)) {
            $exception = new ValidationException($temporaryPath, null, $id, 'cannot close temporary LearningNote file');
            $this->removeTemporaryFile($temporaryPath, $id, $exception);
            throw $exception;
        }
        if (!rename($temporaryPath, $path)) {
            $exception = new ValidationException($path, null, $id, 'cannot atomically publish LearningNote');
            $this->removeTemporaryFile($temporaryPath, $id, $exception);
            throw $exception;
        }
    }

    private function removeTemporaryFile(string $path, string $id, ?Throwable $cause = null): void
    {
        if (!is_file($path)) {
            return;
        }
        if (unlink($path)) {
            return;
        }
        $message = 'cannot remove temporary LearningNote file';
        if ($cause !== null) {
            $message .= ' after failure: ' . $cause->getMessage();
        }
        throw new ValidationException($path, null, $id, $message);
    }

    /** @phpstan-impure */
    private function filesystemEntryExists(string $path): bool
    {
        clearstatcache(true, $path);

        return is_link($path) || file_exists($path);
    }
}
