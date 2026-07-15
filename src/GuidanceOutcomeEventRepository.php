<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class GuidanceOutcomeEventRepository
{
    public function __construct(
        private readonly JsonlValidator $jsonlValidator = new JsonlValidator(),
        private readonly GuidanceOutcomeEventParser $parser = new GuidanceOutcomeEventParser(),
    ) {
    }

    /**
     * @return list<GuidanceOutcomeEvent>
     */
    public function load(string $root, ?string $path = null): array
    {
        $path = $path ?? $root . '/history/outcomes.jsonl';
        $records = $this->jsonlValidator->parseFile($path);
        $events = [];
        $ids = [];
        $compilationGuidance = [];
        foreach ($records as $index => $record) {
            $id = $record['id'] ?? null;
            if (!is_string($id) || !str_starts_with($id, 'guidance-outcome.')) {
                continue;
            }
            $event = $this->parser->parse($record, $path, $index + 1);
            if (isset($ids[$event->id])) {
                throw new ValidationException($path, $index + 1, $event->id, 'duplicate guidance outcome event ID');
            }
            $key = $event->compilationId . "\0" . $event->guidanceId;
            if (isset($compilationGuidance[$key])) {
                throw new ValidationException($path, $index + 1, $event->id, 'duplicate guidance outcome for compilation and guidance');
            }
            $ids[$event->id] = true;
            $compilationGuidance[$key] = true;
            $events[] = $event;
        }
        usort($events, static fn(GuidanceOutcomeEvent $a, GuidanceOutcomeEvent $b) => strcmp($a->id, $b->id));

        return $events;
    }

    /**
     * Count records in the outcome history that are NOT the current
     * "guidance-outcome.*" per-guidance-item shape (e.g. the older
     * "outcome.*" session-summary shape with helpful/irrelevant/harmful as
     * proposal-ID arrays). OutcomeRepository::loadAll() validates both
     * shapes without error, but load() above -- and therefore every
     * GuidanceUsageProjector-based statistic and promotion/staleness
     * decision -- silently excludes anything that is not "guidance-outcome.*".
     * Callers should surface this count instead of letting older recorded
     * usage signal disappear invisibly from guidance-evaluate output.
     */
    public function countLegacyRecords(string $root, ?string $path = null): int
    {
        $path = $path ?? $root . '/history/outcomes.jsonl';
        if (!is_file($path)) {
            return 0;
        }

        $records = $this->jsonlValidator->parseFile($path);
        $legacyCount = 0;
        foreach ($records as $record) {
            $id = $record['id'] ?? null;
            if (is_string($id) && !str_starts_with($id, 'guidance-outcome.')) {
                ++$legacyCount;
            }
        }

        return $legacyCount;
    }
}
