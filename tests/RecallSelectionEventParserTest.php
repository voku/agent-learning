<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\RecallSelectionEventParser;
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
