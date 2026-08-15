<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class FindingExporter
{
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
