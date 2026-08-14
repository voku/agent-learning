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

    public function testTheSuffixIsTakenFromTheEntropySourceAndNotFromNeighbouringFiles(): void
    {
        // The point of the change: the suffix comes from entropy, so an
        // allocator cannot be influenced by - or agree with - what another
        // branch happens to have written. Asserted against a stub rather than
        // by drawing real randomness, because sampling `random_bytes` a
        // thousand times and demanding no repeat fails about once in
        // thirty-four runs and would measure the platform, not this class.
        $generator = new RecordIdGenerator(static fn (int $bytes): string => str_repeat("\x0a", $bytes));

        self::assertSame(
            'finding.2026-08-14.0a0a0a',
            $generator->generate('finding', new DateTimeImmutable('2026-08-14 10:00:00')),
        );
    }

    public function testTwoIndependentAllocatorsDoNotAgree(): void
    {
        // Two generators standing in for two branches: neither can see the
        // other's output, which is exactly the condition the old scheme failed.
        // One pair, so the assertion is not a sampling experiment.
        $date = new DateTimeImmutable('2026-08-14 10:00:00');

        self::assertNotSame(
            (new RecordIdGenerator())->generate('finding', $date),
            (new RecordIdGenerator())->generate('finding', $date),
        );
    }

    public function testTheSuffixCarriesTheDeclaredEntropy(): void
    {
        $id = (new RecordIdGenerator())->generate('finding', new DateTimeImmutable('2026-08-14 10:00:00'));
        $suffix = substr($id, strrpos($id, '.') + 1);

        // Six hex characters: 16.7 million values per day. Shrinking this
        // silently would reintroduce the collision the class exists to remove.
        self::assertSame(6, strlen($suffix));
        self::assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $suffix);
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
