<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\GuidanceOutcomeAttributionSource;
use voku\AgentLearning\GuidanceOutcomeEventParser;
use voku\AgentLearning\ValidationException;

final class GuidanceOutcomeEventParserTest extends TestCase
{
    public function testRecordWithoutAttributionRemainsValid(): void
    {
        $event = (new GuidanceOutcomeEventParser())->parse($this->record(), 'history/outcomes.jsonl', 1);

        self::assertNull($event->attribution);
    }

    public function testUnconfoundedAttributionIsIndependentlyAttributable(): void
    {
        $event = (new GuidanceOutcomeEventParser())->parse(
            $this->record(['seen_before_decision' => true, 'also_prescribed_by' => []]),
            'history/outcomes.jsonl',
            1,
        );

        self::assertNotNull($event->attribution);
        self::assertTrue($event->attribution->isIndependentlyAttributable());
        self::assertSame(['seen_before_decision' => true, 'also_prescribed_by' => []], $event->attribution->toArray());
    }

    public function testGuidanceReadOnlyAfterTheDecisionIsNotAttributable(): void
    {
        $event = (new GuidanceOutcomeEventParser())->parse(
            $this->record(['seen_before_decision' => false, 'also_prescribed_by' => []]),
            'history/outcomes.jsonl',
            1,
        );

        self::assertNotNull($event->attribution);
        self::assertFalse($event->attribution->isIndependentlyAttributable());
    }

    public function testDecisionAlreadyPrescribedElsewhereIsNotAttributable(): void
    {
        $event = (new GuidanceOutcomeEventParser())->parse(
            $this->record(['seen_before_decision' => true, 'also_prescribed_by' => ['skill', 'constraint']]),
            'history/outcomes.jsonl',
            1,
        );

        self::assertNotNull($event->attribution);
        self::assertFalse($event->attribution->isIndependentlyAttributable());
        self::assertSame(
            [GuidanceOutcomeAttributionSource::SKILL, GuidanceOutcomeAttributionSource::CONSTRAINT],
            $event->attribution->alsoPrescribedBy,
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedAttributions(): iterable
    {
        yield 'not an object' => ['yes', 'attribution must be an object'];
        yield 'list instead of object' => [[true], 'attribution must be an object'];
        yield 'unknown field' => [['seen_before_decision' => true, 'also_prescribed_by' => [], 'confidence' => 1], 'unknown attribution field: confidence'];
        yield 'missing seen flag' => [['also_prescribed_by' => []], 'field must be boolean: attribution.seen_before_decision'];
        yield 'string seen flag' => [['seen_before_decision' => 'true', 'also_prescribed_by' => []], 'field must be boolean: attribution.seen_before_decision'];
        yield 'missing sources' => [['seen_before_decision' => true], 'missing or invalid list field: also_prescribed_by'];
        yield 'unknown source' => [['seen_before_decision' => true, 'also_prescribed_by' => ['memory']], 'unknown attribution source: memory'];
        yield 'duplicate source' => [['seen_before_decision' => true, 'also_prescribed_by' => ['skill', 'skill']], 'duplicate attribution source: skill'];
    }

    #[DataProvider('malformedAttributions')]
    public function testMalformedAttributionFailsClosed(mixed $attribution, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        (new GuidanceOutcomeEventParser())->parse($this->record($attribution), 'history/outcomes.jsonl', 1);
    }

    /** @return array<string, mixed> */
    private function record(mixed $attribution = null): array
    {
        $record = [
            'schema_version' => '1.0',
            'id' => 'guidance-outcome.2026-09-23.001',
            'compilation_id' => 'compilation.PRE1.2026-09-23.001',
            'task_id' => 'PRE1-2',
            'guidance_id' => 'proposal.2026-09-01.001',
            'outcome' => 'helpful',
            'applied' => true,
            'comment' => 'Chose the dry-run default because the guidance named the asymmetric guard.',
            'commit' => 'abc1234',
            'recorded_by' => 'codex',
            'recorded_at' => '2026-09-23T10:00:00+00:00',
        ];
        if ($attribution !== null) {
            $record['attribution'] = $attribution;
        }

        return $record;
    }
}
