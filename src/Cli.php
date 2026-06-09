<?php

declare(strict_types=1);

namespace voku\AgentLearning;

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
        (new DecisionRecorder())->validateHistory($root, $proposalsById);

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
        $taskId = $this->stringOption($parsed['options'], 'ticket')
            ?? $this->stringOption($parsed['options'], 'task')
            ?? $parsed['arguments'][0]
            ?? null;
        if ($taskId === null || trim($taskId) === '') {
            throw new ValidationException($root, null, null, 'prepare requires --ticket or a task id argument');
        }

        $findings = [];
        foreach ($this->validateFindings($root, $this->stringOption($parsed['options'], 'task-id-pattern')) as $finding) {
            if ($finding->status === FindingStatus::VALIDATED && $finding->taskId === $taskId) {
                $findings[] = $finding;
            }
        }

        $rejectedHistoryPath = $root . '/history/rejected-proposals.jsonl';
        $rejectedHistory = is_file($rejectedHistoryPath) ? (string)file_get_contents($rejectedHistoryPath) : '';
        $prompt = (new ConsolidationPromptBuilder())->build($taskId, $findings, $rejectedHistory);
        $output = $this->stringOption($parsed['options'], 'output') ?? $root . '/consolidation-input.md';
        $written = file_put_contents($output, $prompt);
        if ($written === false) {
            throw new ValidationException($output, null, null, 'cannot write consolidation input');
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
        $this->write('Validated proposal: ' . $proposal->id . "\n");

        return 0;
    }

    private function helpCommand(): int
    {
        $this->write(
            "Usage: agent-learning <command> [options]\n\n"
            . "Commands:\n"
            . "  validate             Validate findings, proposals, and decision history.\n"
            . "  prepare              Build consolidation input for one task id.\n"
            . "  proposal-validate    Validate one proposal against known findings.\n\n"
            . "Options:\n"
            . "  --root PATH              Learning root or project root. Defaults to auto-discovery.\n"
            . "  --task-id-pattern REGEX  Override finding task id validation.\n"
            . "  --ticket ID              Task id for prepare.\n"
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
     * @return array{options: array<string, bool|string>, arguments: list<string>}
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
                $options[substr($option, 0, $equalsPosition)] = substr($option, $equalsPosition + 1);
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $options[$option] = $next;
                $index++;
                continue;
            }

            $options[$option] = true;
        }

        return ['options' => $options, 'arguments' => $arguments];
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
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
