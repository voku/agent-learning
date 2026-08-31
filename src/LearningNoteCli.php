<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use InvalidArgumentException;
use Throwable;

final readonly class LearningNoteCli
{
    public function __construct(
        private PathResolver $pathResolver = new PathResolver(),
        private LearningNoteService $service = new LearningNoteService(),
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = array_shift($argv) ?? 'help';

        try {
            return match ($command) {
                'prepare' => $this->prepare($argv),
                'publish' => $this->publish($argv),
                'status' => $this->status($argv),
                'retire' => $this->retire($argv),
                'help', '--help', '-h' => $this->help(),
                default => throw new InvalidArgumentException('Unknown LearningNote command: ' . $command),
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function prepare(array $tokens): int
    {
        [$options, $arguments] = $this->parseOptions($tokens);
        if ($arguments !== []) {
            throw new InvalidArgumentException('prepare takes no positional arguments');
        }
        $root = $this->pathResolver->resolve($this->single($options, 'root'));
        $findingIds = $options['finding'] ?? [];
        if ($findingIds === []) {
            throw new InvalidArgumentException('prepare requires at least one --finding ID');
        }
        $result = $this->service->prepare($root, $findingIds, $this->single($options, 'project-root'));
        $this->writeJson($result->toArray());

        return 0;
    }

    /** @param list<string> $tokens */
    private function publish(array $tokens): int
    {
        [$options, $arguments] = $this->parseOptions($tokens);
        if ($arguments !== []) {
            throw new InvalidArgumentException('publish takes no positional arguments');
        }
        $root = $this->pathResolver->resolve($this->single($options, 'root'));
        $input = $this->single($options, 'input');
        if ($input === null || !is_file($input)) {
            throw new InvalidArgumentException('publish requires --input PATH');
        }
        $raw = file_get_contents($input);
        if ($raw === false) {
            throw new InvalidArgumentException('cannot read LearningNote authoring input: ' . $input);
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('LearningNote authoring input must be a JSON object');
        }
        /** @var array<string, mixed> $decoded */
        $draft = $this->draft($decoded, $input);
        $projection = $this->service->publish($root, $draft, $this->single($options, 'project-root'));
        $this->writeJson($projection->toArray());

        return 0;
    }

    /** @param list<string> $tokens */
    private function status(array $tokens): int
    {
        [$options, $arguments] = $this->parseOptions($tokens);
        if ($arguments !== []) {
            throw new InvalidArgumentException('status takes no positional arguments');
        }
        $root = $this->pathResolver->resolve($this->single($options, 'root'));
        $notes = array_map(
            static fn (LearningNoteProjection $note): array => $note->toArray(),
            $this->service->activeProjections($root, $this->single($options, 'project-root')),
        );
        $this->writeJson(['schema_version' => '1.0', 'notes' => $notes]);

        return 0;
    }

    /** @param list<string> $tokens */
    private function retire(array $tokens): int
    {
        [$options, $arguments] = $this->parseOptions($tokens);
        $id = $arguments[0] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('retire requires a LearningNote ID');
        }
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('retire accepts exactly one LearningNote ID');
        }
        $reason = $this->single($options, 'reason');
        if ($reason === null) {
            throw new InvalidArgumentException('retire requires --reason TEXT');
        }
        $root = $this->pathResolver->resolve($this->single($options, 'root'));
        $projection = $this->service->retire($root, $id, $reason);
        $this->writeJson($projection->toArray());

        return 0;
    }

    private function help(): int
    {
        fwrite(STDOUT, "Usage: agent-learning-note <prepare|publish|status|retire> [options]\n\n");
        fwrite(STDOUT, "  prepare --root PATH --finding ID [--finding ID] [--project-root PATH]\n");
        fwrite(STDOUT, "  publish --root PATH --input candidate.json [--project-root PATH]\n");
        fwrite(STDOUT, "  status --root PATH [--project-root PATH]\n");
        fwrite(STDOUT, "  retire --root PATH LEARNING_NOTE_ID --reason TEXT\n");

        return 0;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function draft(array $record, string $file): LearningNoteDraft
    {
        $sourceFindings = $this->stringList($record, 'source_findings', $file, true);
        $sourceProposals = $this->stringList($record, 'source_proposals', $file, false);
        $tags = $this->stringList($record, 'tags', $file, false);
        $evidenceRaw = $record['repository_evidence'] ?? [];
        if (!is_array($evidenceRaw)) {
            throw new ValidationException($file, null, null, 'repository_evidence must be an array');
        }
        $repositoryEvidence = [];
        foreach ($evidenceRaw as $item) {
            if (!is_array($item)) {
                throw new ValidationException($file, null, null, 'repository_evidence entries must be objects');
            }
            /** @var array<string, mixed> $item */
            $repositoryEvidence[] = LearningNoteRepositoryEvidence::fromArray($item, $file);
        }
        $content = $record['content'] ?? null;
        if (!is_array($content)) {
            throw new ValidationException($file, null, null, 'content must be an object');
        }
        /** @var array<string, mixed> $content */
        $id = $record['id'] ?? null;
        if ($id !== null && (!is_string($id) || trim($id) === '')) {
            throw new ValidationException($file, null, null, 'id must be a non-empty string when present');
        }

        return new LearningNoteDraft(
            sourceFindings: $sourceFindings,
            sourceProposals: $sourceProposals,
            tags: $tags,
            repositoryEvidence: $repositoryEvidence,
            content: LearningNoteContent::fromArray($content, $file),
            id: is_string($id) ? trim($id) : null,
        );
    }

    /**
     * @param list<string> $tokens
     * @return array{array<string, list<string>>, list<string>}
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        $arguments = [];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }
            $nameValue = substr($token, 2);
            if ($nameValue === '') {
                throw new InvalidArgumentException('empty option');
            }
            if (str_contains($nameValue, '=')) {
                [$name, $value] = explode('=', $nameValue, 2);
            } else {
                $name = $nameValue;
                $value = $tokens[$index + 1] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException('--' . $name . ' requires a value');
                }
                ++$index;
            }
            if ($name === '' || $value === '') {
                throw new InvalidArgumentException('options require non-empty names and values');
            }
            $options[$name][] = $value;
        }

        return [$options, $arguments];
    }

    /** @param array<string, list<string>> $options */
    private function single(array $options, string $name): ?string
    {
        $values = $options[$name] ?? [];
        if (count($values) > 1) {
            throw new InvalidArgumentException('--' . $name . ' may be provided only once');
        }

        return $values[0] ?? null;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private function stringList(array $record, string $field, string $file, bool $required): array
    {
        $value = $record[$field] ?? [];
        if (!is_array($value)) {
            throw new ValidationException($file, null, null, $field . ' must be an array');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ValidationException($file, null, null, $field . ' entries must be non-empty strings');
            }
            $result[] = trim($item);
        }
        $result = array_values(array_unique($result));
        if ($required && $result === []) {
            throw new ValidationException($file, null, null, $field . ' must not be empty');
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(array $value): void
    {
        fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
