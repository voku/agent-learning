<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;

final class LearningNoteCodec
{
    public const string SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly Json $json = new Json(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    public function decodeFile(string $path): LearningNote
    {
        return $this->decode($this->json->decodeObjectFile($path), $path);
    }

    /** @param array<string, mixed> $data */
    public function decode(array $data, string $file = 'LearningNote'): LearningNote
    {
        $idValue = $data['id'] ?? null;
        $id = is_string($idValue) ? $idValue : null;
        $this->redactionGuard->assertSafeValue($data, $file, null, $id);

        $schemaVersion = $this->string($data, 'schema_version', $file, $id);
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new ValidationException($file, null, $id, 'unsupported LearningNote schema_version: ' . $schemaVersion);
        }
        $noteId = $this->string($data, 'id', $file, $id);
        if (preg_match(RecordIdGenerator::pattern('learning-note'), $noteId) !== 1) {
            throw new ValidationException($file, null, $noteId, 'LearningNote id must match learning-note.YYYY-MM-DD.<suffix>');
        }
        $patternKey = $this->string($data, 'pattern_key', $file, $noteId);
        if (preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $patternKey) !== 1) {
            throw new ValidationException($file, null, $noteId, 'pattern_key must use stable dot-separated lowercase segments');
        }
        $statusValue = $this->string($data, 'status', $file, $noteId);
        $status = LearningNoteStatus::tryFrom($statusValue);
        if ($status === null) {
            throw new ValidationException($file, null, $noteId, 'unsupported LearningNote status: ' . $statusValue);
        }

        $validationRecord = $data['validation_case'] ?? null;
        if (!is_array($validationRecord)) {
            throw new ValidationException($file, null, $noteId, 'LearningNote validation_case must be an object');
        }
        /** @var array<string, mixed> $validationRecord */
        $validationCase = ValidationCase::fromArray($validationRecord, 'validation_case', $file, null, $noteId);

        $contentRecord = $data['content'] ?? null;
        if (!is_array($contentRecord)) {
            throw new ValidationException($file, null, $noteId, 'LearningNote content must be an object');
        }
        /** @var array<string, mixed> $contentRecord */
        $content = LearningNoteContent::fromArray($contentRecord, $file, $noteId);

        $repositoryEvidenceByIdentity = [];
        $repositoryEvidenceRaw = $data['repository_evidence'] ?? [];
        if (!is_array($repositoryEvidenceRaw)) {
            throw new ValidationException($file, null, $noteId, 'LearningNote repository_evidence must be a list');
        }
        foreach ($repositoryEvidenceRaw as $item) {
            if (!is_array($item)) {
                throw new ValidationException($file, null, $noteId, 'LearningNote repository_evidence entries must be objects');
            }
            /** @var array<string, mixed> $item */
            $evidence = LearningNoteRepositoryEvidence::fromArray($item, $file, $noteId);
            $repositoryEvidenceByIdentity[$evidence->sourceRef . ':' . $evidence->contentSha256] = $evidence;
        }
        ksort($repositoryEvidenceByIdentity, SORT_STRING);
        $repositoryEvidence = array_values($repositoryEvidenceByIdentity);

        $createdAt = $this->timestamp($data, 'created_at', $file, $noteId);
        $updatedAt = $this->timestamp($data, 'updated_at', $file, $noteId);
        $retiredAt = $this->optionalTimestamp($data, 'retired_at', $file, $noteId);
        $retiredReason = $this->optionalString($data, 'retired_reason', $file, $noteId);
        if ($status === LearningNoteStatus::RETIRED && ($retiredAt === null || $retiredReason === null)) {
            throw new ValidationException($file, null, $noteId, 'retired LearningNote requires retired_at and retired_reason');
        }
        if ($status === LearningNoteStatus::ACTIVE && ($retiredAt !== null || $retiredReason !== null)) {
            throw new ValidationException($file, null, $noteId, 'active LearningNote cannot carry retirement fields');
        }

        return new LearningNote(
            id: $noteId,
            schemaVersion: $schemaVersion,
            patternKey: $patternKey,
            status: $status,
            scope: $this->stringList($data, 'scope', $file, $noteId, false),
            tags: $this->stringList($data, 'tags', $file, $noteId, true),
            sourceFindings: $this->stringList($data, 'source_findings', $file, $noteId, false),
            sourceProposals: $this->stringList($data, 'source_proposals', $file, $noteId, true),
            validationCase: $validationCase,
            repositoryEvidence: $repositoryEvidence,
            content: $content,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            retiredAt: $retiredAt,
            retiredReason: $retiredReason,
        );
    }

    public function encode(LearningNote $note): string
    {
        $canonical = $this->decode($note->toArray())->toArray();
        try {
            return json_encode(
                $canonical,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new ValidationException('LearningNote', null, $note->id, 'cannot encode LearningNote: ' . $exception->getMessage());
        }
    }

    public function digest(LearningNote $note): string
    {
        return hash('sha256', $this->encode($note));
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, string $file, ?string $recordId): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($file, null, $recordId, 'LearningNote requires non-empty string ' . $key);
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key, string $file, ?string $recordId): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        $value = $data[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($file, null, $recordId, 'LearningNote ' . $key . ' must be non-empty when present');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function stringList(array $data, string $key, string $file, ?string $recordId, bool $allowEmpty): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || (!$allowEmpty && $value === [])) {
            throw new ValidationException($file, null, $recordId, 'LearningNote ' . $key . ' must be ' . ($allowEmpty ? 'a list' : 'a non-empty list'));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ValidationException($file, null, $recordId, 'LearningNote ' . $key . ' entries must be non-empty strings');
            }
            $result[] = trim($item);
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function timestamp(array $data, string $key, string $file, ?string $recordId): string
    {
        $value = $this->string($data, $key, $file, $recordId);
        if (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value) === false) {
            throw new ValidationException($file, null, $recordId, 'malformed LearningNote timestamp field: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalTimestamp(array $data, string $key, string $file, ?string $recordId): ?string
    {
        $value = $this->optionalString($data, $key, $file, $recordId);
        if ($value !== null && DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value) === false) {
            throw new ValidationException($file, null, $recordId, 'malformed LearningNote timestamp field: ' . $key);
        }

        return $value;
    }
}
