<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Parses consolidation results from LLM JSON or Markdown-wrapped JSON.
 */
final class ConsolidationResultParser
{
    /**
     * Parse the LLM output string or file content into an array.
     *
     * @param string $content
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function parse(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $content = trim($matches[1]);
        } elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException('', null, null, 'malformed JSON consolidation result: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new ValidationException('', null, null, 'consolidation result is not a JSON object');
        }

        return $decoded;
    }
}
