<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ConsolidationResultParser;
use voku\AgentLearning\ValidationException;

final class ConsolidationResultParserTest extends TestCase
{
    public function testParsesRawJsonAndMarkdownFencedJson(): void
    {
        $parser = new ConsolidationResultParser();

        $rawJson = '{"action": "ADD", "source_findings": ["f-1"]}';
        $res1 = $parser->parse($rawJson);
        self::assertSame('ADD', $res1['action']);

        $fencedJson = "```json\n{\"action\": \"REPLACE\", \"source_findings\": [\"f-2\"]}\n```";
        $res2 = $parser->parse($fencedJson);
        self::assertSame('REPLACE', $res2['action']);
    }

    public function testThrowsOnMalformedJson(): void
    {
        $parser = new ConsolidationResultParser();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('malformed JSON consolidation result');
        $parser->parse('{"action": "ADD", invalid_json}');
    }
}
