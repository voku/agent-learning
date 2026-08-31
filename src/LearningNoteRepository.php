<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use Throwable;

final class LearningNoteRepository
{
    /** @return array<string, LearningNote> */
    public function loadAll(string $root): array
    {
        $notes = [];
        foreach (LearningNoteStatus::cases() as $status) {
            foreach ($this->jsonFiles($root . '/notes/' . $status->value) as $path) {
                $note = $this->read($path);
                if ($note->status !== $status) {
                    throw new ValidationException($path, null, $note->id, 'learning note status does not match storage directory');
                }
                if (isset($notes[$note->id])) {
                    throw new ValidationException($path, null, $note->id, 'duplicate LearningNote ID');
                }
                $notes[$note->id] = $note;
            }
        }
        ksort($notes, SORT_STRING);

        return $notes;
    }

    /** @return array<string, LearningNote> */
    public function loadActive(string $root): array
    {
        return array_filter(
            $this->loadAll($root),
            static fn (LearningNote $note): bool => $note->status === LearningNoteStatus::ACTIVE,
        );
    }

    public function find(string $root, string $id): ?LearningNote
    {
        return $this->loadAll($root)[$id] ?? null;
    }

    public function findActiveByPatternKey(string $root, string $patternKey): ?LearningNote
    {
        $match = null;
        foreach ($this->loadActive($root) as $note) {
            if ($note->patternKey !== $patternKey) {
                continue;
            }
            if ($match !== null) {
                throw new ValidationException($root, null, $note->id, 'duplicate active LearningNote pattern_key: ' . $patternKey);
            }
            $match = $note;
        }

        return $match;
    }

    public function publish(string $root, LearningNote $note): string
    {
        $directory = $root . '/notes/' . $note->status->value;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ValidationException($directory, null, $note->id, 'cannot create LearningNote directory');
        }

        $path = $directory . '/' . $note->id . '.json';
        $encoded = json_encode(
            $note->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->writeAtomically($directory, $path, $encoded, $note->id);

        return $path;
    }

    public function removeActive(string $root, string $id): void
    {
        $path = $root . '/notes/' . LearningNoteStatus::ACTIVE->value . '/' . $id . '.json';
        if (is_file($path) && !unlink($path)) {
            throw new ValidationException($path, null, $id, 'cannot remove active LearningNote after retirement');
        }
    }

    private function read(string $path): LearningNote
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ValidationException($path, null, null, 'cannot read LearningNote');
        }
        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException($path, null, null, 'invalid LearningNote JSON: ' . $exception->getMessage());
        }
        if (!is_array($record)) {
            throw new ValidationException($path, null, null, 'LearningNote must be a JSON object');
        }
        /** @var array<string, mixed> $record */
        return $this->parse($record, $path);
    }

    /** @param array<string, mixed> $record */
    private function parse(array $record, string $path): LearningNote
    {
        if (($record['schema_version'] ?? null) !== '1.0') {
            throw new ValidationException($path, null, null, 'unsupported LearningNote schema_version');
        }
        $id = $this->requiredString($record, 'id', $path);
        if (preg_match('/^learning-note\.[A-Za-z0-9._-]+$/', $id) !== 1) {
            throw new ValidationException($path, null, $id, 'LearningNote id must start with learning-note. and contain only stable identifier characters');
        }
        $patternKey = $this->requiredString($record, 'pattern_key', $path, $id);
        $statusValue = $this->requiredString($record, 'status', $path, $id);
        $status = LearningNoteStatus::tryFrom($statusValue)
            ?? throw new ValidationException($path, null, $id, 'unsupported LearningNote status: ' . $statusValue);
        $validationCaseRaw = $record['validation_case'] ?? null;
        if (!is_array($validationCaseRaw)) {
            throw new ValidationException($path, null, $id, 'LearningNote validation_case must be an object');
        }
        /** @var array<string, mixed> $validationCaseRaw */
        $contentRaw = $record['content'] ?? null;
        if (!is_array($contentRaw)) {
            throw new ValidationException($path, null, $id, 'LearningNote content must be an object');
        }
        /** @var array<string, mixed> $contentRaw */
        $repositoryEvidence = [];
        $evidenceRaw = $record['repository_evidence'] ?? [];
        if (!is_array($evidenceRaw)) {
            throw new ValidationException($path, null, $id, 'LearningNote repository_evidence must be an array');
        }
        foreach ($evidenceRaw as $item) {
            if (!is_array($item)) {
                throw new ValidationException($path, null, $id, 'LearningNote repository_evidence entries must be objects');
            }
            /** @var array<string, mixed> $item */
            $repositoryEvidence[] = LearningNoteRepositoryEvidence::fromArray($item, $path, $id);
        }

        $createdAt = $this->dateString($record, 'created_at', $path, $id);
        $updatedAt = $this->dateString($record, 'updated_at', $path, $id);
        $retiredReason = $this->optionalString($record, 'retired_reason', $path, $id);
        if ($status === LearningNoteStatus::RETIRED && $retiredReason === null) {
            throw new ValidationException($path, null, $id, 'retired LearningNote requires retired_reason');
        }
        if ($status === LearningNoteStatus::ACTIVE && $retiredReason !== null) {
            throw new ValidationException($path, null, $id, 'active LearningNote must not have retired_reason');
        }

        return new LearningNote(
            id: $id,
            patternKey: $patternKey,
            status: $status,
            scope: $this->stringList($record, 'scope', $path, $id, true),
            tags: $this->stringList($record, 'tags', $path, $id, false),
            sourceFindings: $this->stringList($record, 'source_findings', $path, $id, true),
            sourceProposals: $this->stringList($record, 'source_proposals', $path, $id, false),
            validationCase: ValidationCase::fromArray($validationCaseRaw, 'validation_case', $path, null, $id),
            repositoryEvidence: $repositoryEvidence,
            content: LearningNoteContent::fromArray($contentRaw, $path, $id),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            retiredReason: $retiredReason,
        );
    }

    /** @return list<string> */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $paths = glob($directory . '/*.json');
        if ($paths === false) {
            throw new ValidationException($directory, null, null, 'cannot enumerate LearningNotes');
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $field, string $path, ?string $id = null): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' must be a non-empty string');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $record */
    private function optionalString(array $record, string $field, string $path, ?string $id = null): ?string
    {
        if (!array_key_exists($field, $record) || $record[$field] === null) {
            return null;
        }
        if (!is_string($record[$field]) || trim($record[$field]) === '') {
            throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' must be a non-empty string when present');
        }

        return trim($record[$field]);
    }

    /**
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private function stringList(array $record, string $field, string $path, string $id, bool $required): array
    {
        $value = $record[$field] ?? [];
        if (!is_array($value)) {
            throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' must be an array');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' entries must be non-empty strings');
            }
            $result[] = trim($item);
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        if ($required && $result === []) {
            throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' must not be empty');
        }

        return $result;
    }

    /** @param array<string, mixed> $record */
    private function dateString(array $record, string $field, string $path, string $id): string
    {
        $value = $this->requiredString($record, $field, $path, $id);
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new ValidationException($path, null, $id, 'LearningNote ' . $field . ' must be an ISO-8601 date-time');
        }

        return $value;
    }

    private function writeAtomically(string $directory, string $path, string $content, string $id): void
    {
        $temporary = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new ValidationException($temporary, null, $id, 'cannot write temporary LearningNote');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new ValidationException($path, null, $id, 'cannot atomically publish LearningNote');
        }
    }
}
