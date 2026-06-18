<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class RecallSelectionEventRepository
{
    public function __construct(
        private readonly JsonlValidator $jsonlValidator = new JsonlValidator(),
        private readonly RecallSelectionEventParser $parser = new RecallSelectionEventParser(),
    ) {
    }

    /**
     * @return list<RecallSelectionEvent>
     */
    public function load(string $root, ?string $path = null): array
    {
        $path = $path ?? $root . '/history/recall-selections.jsonl';
        $records = $this->jsonlValidator->parseFile($path);
        $events = [];
        $ids = [];
        $compilationGuidance = [];
        foreach ($records as $index => $record) {
            $event = $this->parser->parse($record, $path, $index + 1);
            if (isset($ids[$event->id])) {
                throw new ValidationException($path, $index + 1, $event->id, 'duplicate recall selection event ID');
            }
            $key = $event->compilationId . "\0" . $event->guidanceId;
            if (isset($compilationGuidance[$key])) {
                throw new ValidationException($path, $index + 1, $event->id, 'duplicate recall selection for compilation and guidance');
            }
            $ids[$event->id] = true;
            $compilationGuidance[$key] = true;
            $events[] = $event;
        }
        usort($events, static fn(RecallSelectionEvent $a, RecallSelectionEvent $b) => strcmp($a->id, $b->id));

        return $events;
    }
}
