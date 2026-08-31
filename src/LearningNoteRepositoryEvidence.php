<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteRepositoryEvidence
{
    public string $sourceRef;

    public function __construct(
        string $sourceRef,
        public string $sha256,
    ) {
        $sourceRef = str_replace('\\', '/', trim($sourceRef));
        if ($sourceRef === '') {
            throw new ValidationException('learning-note', null, null, 'repository evidence source_ref must be non-empty');
        }
        if (
            str_starts_with($sourceRef, '/')
            || preg_match('/^[A-Za-z]:\//', $sourceRef) === 1
            || in_array('..', explode('/', $sourceRef), true)
        ) {
            throw new ValidationException('learning-note', null, null, 'repository evidence source_ref must stay project-relative');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new ValidationException('learning-note', null, null, 'repository evidence sha256 must be a lowercase SHA-256 digest');
        }
        $this->sourceRef = $sourceRef;
    }

    /** @param array<string, mixed> $record */
    public static function fromArray(array $record, string $file, ?string $recordId = null): self
    {
        $sourceRef = $record['source_ref'] ?? null;
        $sha256 = $record['sha256'] ?? null;
        if (!is_string($sourceRef) || trim($sourceRef) === '') {
            throw new ValidationException($file, null, $recordId, 'repository_evidence.source_ref must be a non-empty string');
        }
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new ValidationException($file, null, $recordId, 'repository_evidence.sha256 must be a lowercase SHA-256 digest');
        }

        try {
            return new self($sourceRef, $sha256);
        } catch (ValidationException $exception) {
            throw new ValidationException($file, null, $recordId, $exception->getMessage());
        }
    }

    /** @return array{source_ref: string, sha256: string} */
    public function toArray(): array
    {
        return [
            'source_ref' => $this->sourceRef,
            'sha256' => $this->sha256,
        ];
    }
}
