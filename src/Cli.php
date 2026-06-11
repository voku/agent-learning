<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class Cli
{
    public function __construct(
        private readonly PathResolver $pathResolver = new PathResolver(),
        private readonly FindingLifecycle $findingLifecycle = new FindingLifecycle(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $tokens = $argv;
        array_shift($tokens);
        $command = array_shift($tokens) ?? 'help';

        try {
            return match ($command) {
                'validate' => $this->validateCommand($tokens),
                'prepare' => $this->prepareCommand($tokens),
                'proposal-validate' => $this->proposalValidateCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $throwable) {
            $this->writeError($throwable->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function validateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $taskIdPattern = $this->stringOption($parsed['options'], 'task-id-pattern');

        $findingsById = $this->validateFindings($root, $taskIdPattern);
        $proposalsById = (new ProposalRepository())->loadAll($root, $findingsById);
        (new DecisionHistoryValidator())->validateHistory($root, $proposalsById);

        $this->write(
            'Validated agent learning root: ' . $root . "\n"
            . 'Findings: ' . count($findingsById) . "\n"
            . 'Proposals: ' . count($proposalsById) . "\n"
        );

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function prepareCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $selection = $this->findingSelection($parsed['options'], $parsed['arguments'], $root);
        if (!$selection->hasSelectors()) {
            throw new ValidationException($root, null, null, 'prepare requires --finding, --task, --ticket, --scope, --since, or a task id argument');
        }

        $findings = $this->selectFindings(
            $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern')),
            $selection,
        );
        if ($findings === [] && $this->boolOption($parsed['options'], 'allow-empty') === false) {
            throw new ValidationException($root, null, null, 'prepare selection matched no validated findings');
        }

        $rejectedHistoryPath = $root . '/history/rejected-proposals.jsonl';
        $rejectedHistory = is_file($rejectedHistoryPath) ? (string)file_get_contents($rejectedHistoryPath) : '';
        $prompt = $this->appendConsolidationAddendum(
            (new ConsolidationPromptBuilder())->build($selection->label(), $findings, $rejectedHistory),
            $root,
        );
        $output = $this->stringOption($parsed['options'], 'output') ?? $root . '/consolidation-input.md';
        $written = file_put_contents($output, $prompt);
        if ($written === false) {
            throw new ValidationException($output, null, null, 'cannot write consolidation input');
        }

        $this->write('Selected findings: ' . count($findings) . "\n");
        foreach ($findings as $finding) {
            $this->write('- ' . $finding->id . ' (' . $finding->taskId . ")\n");
        }
        $this->write('Wrote consolidation input: ' . $output . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function proposalValidateCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->pathResolver->resolve($this->stringOption($parsed['options'], 'root'));
        $proposalPath = $this->stringOption($parsed['options'], 'proposal') ?? $parsed['arguments'][0] ?? null;
        if ($proposalPath === null || trim($proposalPath) === '') {
            throw new ValidationException($root, null, null, 'proposal-validate requires --proposal or a proposal path argument');
        }

        $proposalPath = $this->resolveProposalPath($proposalPath, $root);
        $findingsById = $this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern'));
        $proposal = (new ProposalValidator())->validateFile($proposalPath, $findingsById);
        (new ProposalLifecycle())->assertPathMatchesStatus($proposal, $proposalPath, $root);
        $this->write('Validated proposal: ' . $proposal->id . "\n");

        return 0;
    }

    private function helpCommand(): int
    {
        $this->write(
            "Usage: agent-learning <command> [options]\n\n"
            . "Commands:\n"
            . "  validate             Validate findings, proposals, and decision history.\n"
            . "  prepare              Build consolidation input for selected validated findings.\n"
            . "  proposal-validate    Validate one proposal against known findings.\n\n"
            . "Options:\n"
            . "  --root PATH              Learning root or project root. Defaults to auto-discovery.\n"
            . "  --task-id-pattern REGEX  Override finding task id validation.\n"
            . "  --finding ID             Finding id selector for prepare. Repeatable.\n"
            . "  --task ID                Task id selector for prepare. Repeatable.\n"
            . "  --ticket ID              Alias for --task.\n"
            . "  --scope PATH             Scope selector for prepare. Repeatable.\n"
            . "  --since YYYY-MM-DD       Include findings created on or after this date.\n"
            . "  --until YYYY-MM-DD       Include findings created on or before this date.\n"
            . "  --allow-empty            Allow prepare to write a prompt with no selected findings.\n"
            . "  --proposal PATH          Proposal path for proposal-validate.\n"
            . "  --output PATH            Output file for prepare.\n"
        );

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        $this->writeError('Unknown command: ' . $command . "\n");
        $this->helpCommand();

        return 1;
    }

    /**
     * @return array<string, Finding>
     */
    private function validateFindings(string $root, ?string $taskIdPattern): array
    {
        $validator = $taskIdPattern === null ? new FindingValidator() : new FindingValidator(taskIdPattern: $taskIdPattern);
        $findingsById = [];
        foreach ($this->findingLifecycle->findingFiles($root) as $file) {
            $finding = $validator->validateFile($file);
            $this->findingLifecycle->assertPathMatchesStatus($finding, $file, $root);
            if (isset($findingsById[$finding->id])) {
                throw new ValidationException($file, null, $finding->id, 'duplicate finding ID');
            }
            $findingsById[$finding->id] = $finding;
        }

        return $findingsById;
    }

    private function resolveProposalPath(string $proposalPath, string $root): string
    {
        if (is_file($proposalPath)) {
            return $proposalPath;
        }

        foreach (['candidate', 'approved', 'rejected', 'applied'] as $statusDirectory) {
            $candidate = $root . '/proposals/' . $statusDirectory . '/' . $proposalPath;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ValidationException($proposalPath, null, null, 'proposal file does not exist');
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{options: array<string, bool|string|list<string>>, arguments: list<string>}
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        $arguments = [];
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }

            $option = substr($token, 2);
            $equalsPosition = strpos($option, '=');
            if ($equalsPosition !== false) {
                $this->addOption($options, substr($option, 0, $equalsPosition), substr($option, $equalsPosition + 1));
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $this->addOption($options, $option, $next);
                $index++;
                continue;
            }

            $this->addOption($options, $option, true);
        }

        return ['options' => $options, 'arguments' => $arguments];
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (is_array($value)) {
            $value = $value[count($value) - 1] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     *
     * @return list<string>
     */
    private function stringOptions(array $options, string $name): array
    {
        $value = $options[$name] ?? null;
        if ($value === null || $value === true) {
            return [];
        }
        if (is_string($value)) {
            return trim($value) === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (trim($item) !== '') {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function boolOption(array $options, string $name): bool
    {
        return ($options[$name] ?? false) === true;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     * @param bool|string                             $value
     */
    private function addOption(array &$options, string $name, bool|string $value): void
    {
        $existing = $options[$name] ?? null;
        if ($existing === null) {
            $options[$name] = $value;

            return;
        }

        $values = [];
        if (is_array($existing)) {
            $values = $existing;
        } elseif (is_string($existing)) {
            $values[] = $existing;
        }

        if (is_string($value)) {
            $values[] = $value;
            $options[$name] = $values;

            return;
        }

        $options[$name] = true;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     * @param list<string>                            $arguments
     */
    private function findingSelection(array $options, array $arguments, string $root): FindingSelection
    {
        $taskIds = $this->uniqueStrings([
            ...$this->stringOptions($options, 'task'),
            ...$this->stringOptions($options, 'ticket'),
        ]);
        if ($taskIds === [] && isset($arguments[0]) && trim($arguments[0]) !== '') {
            $taskIds[] = $arguments[0];
        }

        return new FindingSelection(
            $this->uniqueStrings($this->stringOptions($options, 'finding')),
            $taskIds,
            $this->uniqueStrings($this->stringOptions($options, 'scope')),
            $this->dateOption($options, 'since', $root),
            $this->dateOption($options, 'until', $root),
        );
    }

    /**
     * @param list<string> $values
     *
     * @return list<non-empty-string>
     */
    private function uniqueStrings(array $values): array
    {
        $seen = [];
        $unique = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $unique[] = $value;
        }

        return $unique;
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private function dateOption(array $options, string $name, string $root): ?DateTimeImmutable
    {
        $value = $this->stringOption($options, $name);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable) {
            throw new ValidationException($root, null, null, 'malformed date option --' . $name . ': ' . $value);
        }

        return $date;
    }

    /**
     * @param array<string, Finding> $findingsById
     *
     * @return list<Finding>
     */
    private function selectFindings(array $findingsById, FindingSelection $selection): array
    {
        ksort($findingsById);
        $selected = [];
        foreach ($findingsById as $finding) {
            if ($finding->status !== FindingStatus::VALIDATED || $finding->validationStatus !== 'validated') {
                continue;
            }
            if (!$this->matchesSelection($finding, $selection)) {
                continue;
            }
            $selected[$finding->id] = $finding;
        }

        return array_values($selected);
    }

    private function matchesSelection(Finding $finding, FindingSelection $selection): bool
    {
        $hasIdentitySelector = $selection->findingIds !== [] || $selection->taskIds !== [] || $selection->scopes !== [];
        $matchesIdentitySelector = !$hasIdentitySelector
            || in_array($finding->id, $selection->findingIds, true)
            || in_array($finding->taskId, $selection->taskIds, true)
            || $this->scopeMatches($finding->scope, $selection->scopes);
        if (!$matchesIdentitySelector) {
            return false;
        }

        $createdAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $finding->createdAt);
        if (!$createdAt instanceof DateTimeImmutable) {
            return false;
        }
        if ($selection->since instanceof DateTimeImmutable && $createdAt < $selection->since) {
            return false;
        }
        if ($selection->until instanceof DateTimeImmutable && $createdAt > $selection->until->setTime(23, 59, 59)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $findingScopes
     * @param list<string> $selectedScopes
     */
    private function scopeMatches(array $findingScopes, array $selectedScopes): bool
    {
        foreach ($selectedScopes as $selectedScope) {
            foreach ($findingScopes as $findingScope) {
                if ($findingScope === $selectedScope || str_starts_with($findingScope, rtrim($selectedScope, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function appendConsolidationAddendum(string $prompt, string $root): string
    {
        $templatePath = $root . '/templates/consolidation-prompt.md';
        if (!is_file($templatePath) || filesize($templatePath) === 0) {
            return $prompt;
        }

        $addendum = file_get_contents($templatePath);
        if ($addendum === false) {
            throw new ValidationException($templatePath, null, null, 'cannot read consolidation prompt addendum');
        }

        return rtrim($prompt) . "\n\n---\n\n" . trim($addendum) . "\n";
    }

    private function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    private function writeError(string $message): void
    {
        fwrite(STDERR, $message);
    }
}
