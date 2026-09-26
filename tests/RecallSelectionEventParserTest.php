<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\RecallSelectionEventParser;
use voku\AgentLearning\RecallSelectionEventRepository;
use voku\AgentLearning\ValidationException;

final class RecallSelectionEventParserTest extends TestCase
{
    public function testOutcomeWithholdingReasonIsExposedAsTypedEvidence(): void
    {
        $event = (new RecallSelectionEventParser())->parse(
            $this->record('Current task cannot judge whether the selected guidance helped.'),
            'history/recall-selections.jsonl',
            1,
        );

        self::assertSame(
            'Current task cannot judge whether the selected guidance helped.',
            $event->outcomeWithheldReason,
        );
    }

    public function testEmptyOutcomeWithholdingReasonIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('outcome_withheld_reason must be non-empty when present');

        (new RecallSelectionEventParser())->parse(
            $this->record('   '),
            'history/recall-selections.jsonl',
            1,
        );
    }

    public function testDailySequenceMayGrowBeyondThreeDigits(): void
    {
        $record = ['id' => 'recall-selection.2026-09-26.1000'] + $this->record('Current task cannot judge it.');

        $event = (new RecallSelectionEventParser())->parse($record, 'history/recall-selections.jsonl', 1);

        self::assertSame('recall-selection.2026-09-26.1000', $event->id);
    }

    public function testDailySequenceNeedsAtLeastThreeDigits(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('recall selection id must match recall-selection.YYYY-MM-DD.NNN');

        (new RecallSelectionEventParser())->parse(
            ['id' => 'recall-selection.2026-09-26.99'] + $this->record('Current task cannot judge it.'),
            'history/recall-selections.jsonl',
            1,
        );
    }

    public function testRepositoryOrdersTheDailySequenceNumerically(): void
    {
        $path = sys_get_temp_dir() . '/recall-selections-order-' . bin2hex(random_bytes(8)) . '.jsonl';
        $lines = [];
        foreach (['1000' => 'skill.a', '999' => 'skill.b', '002' => 'skill.c'] as $sequence => $guidanceId) {
            $record = ['id' => 'recall-selection.2026-09-26.' . $sequence, 'guidance_id' => $guidanceId] + $this->record('Current task cannot judge it.');
            $lines[] = json_encode($record, JSON_THROW_ON_ERROR);
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        try {
            $events = (new RecallSelectionEventRepository())->load(sys_get_temp_dir(), $path);
        } finally {
            unlink($path);
        }

        self::assertSame(
            ['recall-selection.2026-09-26.002', 'recall-selection.2026-09-26.999', 'recall-selection.2026-09-26.1000'],
            array_map(static fn ($event): string => $event->id, $events),
        );
    }

    /** @return array<string, mixed> */
    private function record(string $outcomeWithheldReason): array
    {
        return [
            'schema_version' => '1.0',
            'id' => 'recall-selection.2026-08-28.001',
            'compilation_id' => 'compilation.PRE1.2026-08-28.001',
            'task_id' => 'PRE1-1',
            'guidance_id' => 'skill.owner-boundary',
            'guidance_type' => 'skill',
            'eligible' => true,
            'selected' => true,
            'selection_reason' => 'scope_overlap',
            'exclusion_reason' => null,
            'task_files' => ['src/Foo.php'],
            'recorded_at' => '2026-08-28T20:00:00+00:00',
            'outcome_withheld_reason' => $outcomeWithheldReason,
        ];
    }
}
