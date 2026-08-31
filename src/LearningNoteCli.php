<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use JsonException;
use Throwable;

final class LearningNoteCli
{
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
                default => $this->unknown($command),
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function prepare(array $tokens): int
    {
        $options = $this->parseOptions($tokens);
        $root = $this->root($options);
        $findingIds = $this->values($options, 'finding');
        $result = (new LearningNotePreparer())->prepare($root, $findingIds);
        $this->json($result->toArray());

        return 0;
    }

    /** @param list<string> $tokens */
    private function publish(array $tokens): int
    {
        $options = $this->parseOptions($tokens);
        $root = $this->root($options);
        $contentData = $this->content($options, $root);
        $repositoryEvidence = [];
        foreach ($this->values($options, 'repository-evidence-json') as $encoded) {
            $decoded = $this->decodeObject($encoded, $root, '--repository-evidence-json');
            $repositoryEvidence[] = LearningNoteRepositoryEvidence::fromArray($decoded, $root, null);
        }

        $result = (new LearningNotePublisher())->publish(
            root: $root,
            sourceFindingIds: $this->values($options, 'finding'),
            content: LearningNoteContent::fromArray($contentData, $root, null),
            sourceProposalIds: $this->values($options, 'proposal'),
            scope: $this->values($options, 'scope'),
            tags: $this->values($options, 'tag'),
            repositoryEvidence: $repositoryEvidence,
            id: $this->value($options, 'id'),
        );
        $this->json([
            'id' => $result->note->id,
            'pattern_key' => $result->note->patternKey,
            'path' => $result->path,
            'digest' => $result->digest,
        ]);

        return 0;
    }

    /** @param list<string> $tokens */
    private function status(array $tokens): int
    {
        $options = $this->parseOptions($tokens);
        $root = $this->root($options);
        $id = $this->value($options, 'id');
        $repository = new LearningNoteRepository();
        $inspector = new LearningNoteStatusInspector();
        $projectRoot = $this->value($options, 'project-root');

        $notes = $id === null ? $repository->loadAll($root) : array_filter([
            $id => $repository->find($root, $id),
        ], static fn (?LearningNote $note): bool => $note instanceof LearningNote);
        if ($id !== null && $notes === []) {
            throw new ValidationException($root, null, $id, 'LearningNote not found');
        }

        $reports = [];
        foreach ($notes as $note) {
            if (!$note instanceof LearningNote) {
                continue;
            }
            $reports[] = $inspector->inspect($root, $note, $projectRoot)->toArray();
        }
        $this->json(['notes' => $reports]);

        return 0;
    }

    /** @param list<string> $tokens */
    private function retire(array $tokens): int
    {
        $options = $this->parseOptions($tokens);
        $root = $this->root($options);
        $id = $this->required($options, 'id', $root);
        $reason = $this->required($options, 'reason', $root);
        $result = (new LearningNotePublisher())->retire($root, $id, $reason);
        $this->json([
            'id' => $result->note->id,
            'status' => $result->note->status->value,
            'path' => $result->path,
            'digest' => $result->digest,
        ]);

        return 0;
    }

    private function help(): int
    {
        fwrite(STDOUT, <<<'TXT'
Usage: agent-learning-note <command> [options]

Commands:
  prepare  Prepare owner-validated evidence for one LearningNote pattern.
  publish  Publish or update one active LearningNote through the owner boundary.
  status   Inspect source drift for one or all LearningNotes.
  retire   Explicitly retire one active LearningNote while preserving lineage.

Common options:
  --root PATH
  --finding ID                  Repeatable for prepare/publish.
  --proposal ID                 Repeatable for publish.
  --scope PATH                  Repeatable for publish.
  --tag TAG                     Repeatable for publish.
  --id ID                       Optional explicit note id; required for retire/status-one.
  --content-json JSON           LearningNote content object for publish.
  --content-file PATH           Alternative to --content-json.
  --repository-evidence-json JSON Repeatable {source_ref,content_sha256} for publish.
  --project-root PATH           Optional source root override for status.
  --reason TEXT                 Required for retire.

TXT);

        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, 'Unknown LearningNote command: ' . $command . "\n");

        return 1;
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string|list<string>>
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new ValidationException('agent-learning-note', null, null, 'unexpected positional argument: ' . $token);
            }
            $nameValue = substr($token, 2);
            $equals = strpos($nameValue, '=');
            if ($equals !== false) {
                $name = substr($nameValue, 0, $equals);
                $value = substr($nameValue, $equals + 1);
            } else {
                $name = $nameValue;
                $value = $tokens[++$index] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    throw new ValidationException('agent-learning-note', null, null, '--' . $name . ' requires a value');
                }
            }
            if ($name === '' || $value === '') {
                throw new ValidationException('agent-learning-note', null, null, 'LearningNote options require non-empty names and values');
            }
            $existing = $options[$name] ?? null;
            if ($existing === null) {
                $options[$name] = $value;
            } elseif (is_string($existing)) {
                $options[$name] = [$existing, $value];
            } else {
                $existing[] = $value;
                $options[$name] = $existing;
            }
        }

        return $options;
    }

    /** @param array<string, string|list<string>> $options */
    private function root(array $options): string
    {
        return (new PathResolver())->resolve($this->value($options, 'root'));
    }

    /** @param array<string, string|list<string>> $options */
    private function required(array $options, string $name, string $root): string
    {
        $value = $this->value($options, $name);
        if ($value === null) {
            throw new ValidationException($root, null, null, 'LearningNote command requires --' . $name);
        }

        return $value;
    }

    /** @param array<string, string|list<string>> $options */
    private function value(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (is_array($value)) {
            return $value[count($value) - 1] ?? null;
        }

        return $value;
    }

    /**
     * @param array<string, string|list<string>> $options
     * @return list<string>
     */
    private function values(array $options, string $name): array
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $values = array_values(array_unique(array_map('trim', $values)));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<string, string|list<string>> $options
     * @return array<string, mixed>
     */
    private function content(array $options, string $root): array
    {
        $inline = $this->value($options, 'content-json');
        $file = $this->value($options, 'content-file');
        if (($inline === null) === ($file === null)) {
            throw new ValidationException($root, null, null, 'publish requires exactly one of --content-json or --content-file');
        }
        if ($file !== null) {
            $inline = file_get_contents($file);
            if ($inline === false) {
                throw new ValidationException($file, null, null, 'cannot read LearningNote content file');
            }
        }
        if ($inline === null) {
            throw new ValidationException($root, null, null, 'LearningNote content is unavailable');
        }

        return $this->decodeObject($inline, $root, 'LearningNote content');
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $root, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ValidationException($root, null, null, $label . ' is malformed JSON: ' . $exception->getMessage());
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ValidationException($root, null, null, $label . ' must be a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): void
    {
        fwrite(STDOUT, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");
    }
}
