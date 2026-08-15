<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Throwable;

final class FindingExporter
{
    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $options = [];
            for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
                $token = $tokens[$index];
                if (!str_starts_with($token, '--')) {
                    throw new ValidationException('', null, null, 'finding-export accepts options only');
                }
                $option = substr($token, 2);
                $equals = strpos($option, '=');
                if ($equals !== false) {
                    $name = substr($option, 0, $equals);
                    $value = substr($option, $equals + 1);
                } else {
                    $name = $option;
                    $value = $tokens[++$index] ?? '';
                }
                if (!in_array($name, ['root', 'target-package', 'source-repository'], true) || $value === '' || str_starts_with($value, '--')) {
                    throw new ValidationException('', null, null, 'invalid finding-export option: --' . $name);
                }
                $options[$name] = $value;
            }

            $root = (new PathResolver())->resolve($options['root'] ?? null);
            $targetPackage = $options['target-package'] ?? null;
            $sourceRepository = $options['source-repository'] ?? null;
            if (!is_string($targetPackage) || !is_string($sourceRepository)) {
                throw new ValidationException($root, null, null, 'finding-export requires --target-package and --source-repository');
            }

            echo $this->export($root, $targetPackage, $sourceRepository);

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    public function export(string $root, string $targetPackage, string $sourceRepository): string
    {
        $targetPackage = trim($targetPackage);
        if (!FindingValidator::isValidTargetPackage($targetPackage)) {
            throw new ValidationException($root, null, null, 'target package must be a lowercase vendor/package identity');
        }
        $sourceRepository = trim($sourceRepository);
        if ($sourceRepository === '') {
            throw new ValidationException($root, null, null, 'source repository must be non-empty');
        }

        $findings = (new FindingRepository())->loadValidated($root);
        ksort($findings, SORT_STRING);
        $selected = array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->targetPackage === $targetPackage,
        );

        /** @var array<string, list<string>> $runIdsByFindingId */
        $runIdsByFindingId = array_fill_keys(array_keys($selected), []);
        foreach ((new RunLearningDecisionStore($root))->all() as $decision) {
            foreach ($decision->findingIds as $findingId) {
                if (array_key_exists($findingId, $runIdsByFindingId)) {
                    $runIdsByFindingId[$findingId][] = $decision->runId;
                }
            }
        }
        foreach ($runIdsByFindingId as &$runIds) {
            sort($runIds, SORT_STRING);
        }
        unset($runIds);

        $records = [];
        foreach ($selected as $finding) {
            $records[] = [
                'record' => $finding->raw,
                'run_ids' => $runIdsByFindingId[$finding->id] ?? [],
            ];
        }

        return json_encode([
            'schema_version' => '1.0',
            'kind' => 'finding_export',
            'source_repository' => $sourceRepository,
            'target_package' => $targetPackage,
            'finding_count' => count($records),
            'findings' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
