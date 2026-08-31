<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DirectoryIterator;

final class LearningNoteRepository
{
    public function __construct(private readonly LearningNoteCodec $codec = new LearningNoteCodec())
    {
    }

    /** @return array<string, LearningNote> */
    public function loadAll(string $root): array
    {
        $notes = [];
        $activePatternOwners = [];
        foreach (LearningNoteStatus::cases() as $status) {
            $directory = $root . '/notes/' . $status->value;
            foreach ($this->jsonFiles($directory) as $path) {
                $note = $this->codec->decodeFile($path);
                if ($note->status !== $status) {
                    throw new ValidationException($path, null, $note->id, 'LearningNote status does not match storage directory');
                }
                if (isset($notes[$note->id])) {
                    throw new ValidationException($path, null, $note->id, 'duplicate LearningNote id');
                }
                if ($note->status === LearningNoteStatus::ACTIVE) {
                    $existing = $activePatternOwners[$note->patternKey] ?? null;
                    if ($existing !== null) {
                        throw new ValidationException(
                            $path,
                            null,
                            $note->id,
                            'duplicate active LearningNote pattern_key owned by ' . $existing,
                        );
                    }
                    $activePatternOwners[$note->patternKey] = $note->id;
                }
                $notes[$note->id] = $note;
            }
        }
        ksort($notes, SORT_STRING);

        return $notes;
    }

    /** @return list<LearningNote> */
    public function active(string $root): array
    {
        return array_values(array_filter(
            $this->loadAll($root),
            static fn (LearningNote $note): bool => $note->status === LearningNoteStatus::ACTIVE,
        ));
    }

    public function find(string $root, string $id): ?LearningNote
    {
        return $this->loadAll($root)[$id] ?? null;
    }

    public function findActiveByPatternKey(string $root, string $patternKey): ?LearningNote
    {
        foreach ($this->active($root) as $note) {
            if ($note->patternKey === $patternKey) {
                return $note;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        foreach (new DirectoryIterator($directory) as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'json' || $item->getSize() === 0) {
                continue;
            }
            $paths[] = $item->getPathname();
        }
        sort($paths, SORT_STRING);

        return $paths;
    }
}
