<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * How much of some wording already exists in a file: an exact whitespace-normalised
 * substring match, or the share of its word 4-grams found in the file.
 */
final readonly class WordingOverlap
{
    private const int SHINGLE_WORDS = 4;

    public function match(string $file, string $wording, string $existing): ProposalWordingMatch
    {
        if (str_contains($this->normalise($existing), $this->normalise($wording))) {
            return new ProposalWordingMatch($file, 100, true);
        }

        $wordingShingles = $this->shingles($wording);
        if ($wordingShingles === []) {
            return new ProposalWordingMatch($file, 0, false);
        }
        $existingShingles = array_flip($this->shingles($existing));
        $shared = 0;
        foreach ($wordingShingles as $shingle) {
            if (isset($existingShingles[$shingle])) {
                ++$shared;
            }
        }

        return new ProposalWordingMatch($file, intdiv($shared * 100, count($wordingShingles)), false);
    }

    /**
     * The distinct word 4-grams of a text; empty when the text has fewer than four words.
     *
     * @return list<string>
     */
    public function shingles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) < self::SHINGLE_WORDS) {
            return [];
        }

        $shingles = [];
        for ($index = 0, $last = count($words) - self::SHINGLE_WORDS; $index <= $last; ++$index) {
            $shingles[implode(' ', array_slice($words, $index, self::SHINGLE_WORDS))] = true;
        }

        return array_keys($shingles);
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    }
}
