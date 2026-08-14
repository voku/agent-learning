<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Allocates `<kind>.YYYY-MM-DD.<suffix>` record IDs that survive parallel branches.
 *
 * The original scheme was a per-day sequence: read the directory, take the
 * highest number, add one. That is only unique for a writer who can see every
 * other writer, and this system is built for several agents working on several
 * branches at once. Two branches each saw `.004`, each allocated `.005`, and
 * both passed their own validation - because each side only ever loaded its own
 * files. The duplicate appeared at the merge, where the cheapest fix is no
 * longer available.
 *
 * A random suffix removes the shared-state assumption instead of trying to
 * coordinate it. The date prefix stays, so the record set still reads as a
 * timeline and existing IDs keep their meaning; only the counter is replaced.
 *
 * Six hex characters give 16.7 million values per day. At a hundred records in
 * one day the birthday probability of a collision is roughly 0.03%, and a
 * collision that did happen is still rejected loudly by validation rather than
 * silently merged.
 */
final readonly class RecordIdGenerator
{
    /** Bytes of entropy in the suffix; two hex characters each. */
    private const int SUFFIX_BYTES = 3;

    private Closure $entropy;

    /**
     * @param null|Closure(int): string $entropy random source.
     *
     * Injectable so a test can assert what this class does with entropy
     * instead of sampling it. Drawing a thousand real suffixes and asserting
     * they are all distinct fails roughly once every thirty-four runs, which
     * measures `random_bytes` rather than this code and calls the result a
     * regression.
     */
    public function __construct(?Closure $entropy = null)
    {
        $this->entropy = $entropy ?? random_bytes(...);
    }

    public function generate(string $kind, ?DateTimeImmutable $date = null): string
    {
        $kind = trim($kind);
        if ($kind === '') {
            throw new InvalidArgumentException('Record kind must not be empty.');
        }

        return $kind
            . '.' . ($date ?? new DateTimeImmutable('now'))->format('Y-m-d')
            . '.' . bin2hex(($this->entropy)(self::SUFFIX_BYTES));
    }

    /**
     * The suffix shape a record ID may carry.
     *
     * Legacy sequential suffixes stay valid: they are published in changelogs,
     * memory rows and proposal citations, and rewriting history to adopt a new
     * allocator would break every reference to buy nothing.
     */
    public static function suffixPattern(): string
    {
        return '(?:\d{3}|[0-9a-f]{' . (self::SUFFIX_BYTES * 2) . '})';
    }

    /** Full anchored pattern for one record kind, for validators to reuse. */
    public static function pattern(string $kind): string
    {
        return '/^' . preg_quote($kind, '/') . '\.\d{4}-\d{2}-\d{2}\.' . self::suffixPattern() . '$/';
    }
}
