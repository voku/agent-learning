<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\RecordIdGenerator;

/**
 * Record IDs are allocated for a system where several agents work in parallel.
 *
 * The previous scheme took the highest number visible in the local directory
 * and added one. Two branches each saw `.004`, each allocated `.005`, and each
 * passed its own validation because neither could see the other's file. The
 * duplicate only appeared at the merge.
 *
 * @internal
 */
final class RecordIdGeneratorTest extends TestCase
{
    public function testAllocatedIdsCarryTheDateAndValidate(): void
    {
        $id = (new RecordIdGenerator())->generate('finding', new DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertStringStartsWith('finding.2026-08-14.', $id);
        self::assertMatchesRegularExpression(RecordIdGenerator::pattern('finding'), $id);
    }

    public function testTwoIndependentAllocatorsDoNotCollide(): void
    {
        // Two generators standing in for two branches: neither can see the
        // other's output, which is exactly the condition the old scheme failed.
        $branchA = new RecordIdGenerator();
        $branchB = new RecordIdGenerator();
        $date = new DateTimeImmutable('2026-08-14 10:00:00');

        $ids = [];
        for ($i = 0; $i < 500; ++$i) {
            $ids[] = $branchA->generate('finding', $date);
            $ids[] = $branchB->generate('finding', $date);
        }

        self::assertCount(1000, array_unique($ids), 'Allocated IDs collided within a single day.');
    }

    public function testLegacySequentialIdsRemainValid(): void
    {
        // Published in changelogs, memory rows and proposal citations; rewriting
        // them to adopt a new allocator would break every reference to buy
        // nothing.
        foreach (['finding.2026-08-07.001', 'finding.2026-08-14.005'] as $legacy) {
            self::assertMatchesRegularExpression(RecordIdGenerator::pattern('finding'), $legacy);
        }
        self::assertMatchesRegularExpression(RecordIdGenerator::pattern('proposal'), 'proposal.2026-06-13.014');
    }

    public function testMalformedIdsAreStillRejected(): void
    {
        foreach ([
            'finding.2026-08-14',
            'finding.2026-08-14.',
            'finding.2026-08-14.05',
            'finding.2026-08-14.ABCDEF',
            'finding.2026-08-14.zzzzzz',
            'finding.2026-8-14.001',
            'proposal.2026-08-14.001',
            'finding.2026-08-14.001 ',
        ] as $malformed) {
            self::assertDoesNotMatchRegularExpression(
                RecordIdGenerator::pattern('finding'),
                $malformed,
                $malformed . ' should not be accepted as a finding ID.',
            );
        }
    }

    public function testKindMustBeNamed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RecordIdGenerator())->generate('  ');
    }
}
