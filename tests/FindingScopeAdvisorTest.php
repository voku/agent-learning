<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingScopeAdvisor;

final class FindingScopeAdvisorTest extends TestCase
{
    /**
     * @param list<string> $scope
     */
    #[DataProvider('scopes')]
    public function testAdvisesOnlyWhenEveryEntryIsASingleFile(array $scope, bool $advised): void
    {
        $advisories = (new FindingScopeAdvisor())->advise($scope);

        self::assertSame($advised ? [FindingScopeAdvisor::SCOPE_NAMES_ONLY_FILES] : [], array_column($advisories, 'code'));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function scopes(): iterable
    {
        yield 'incident files only' => [['.github/workflows/ci.yml', 'src/Init/InitStatusCommand.php'], true];
        yield 'single file' => [['src/CloseGate.php'], true];
        yield 'dotfile' => [['.editorconfig.dist'], true];
        yield 'file plus directory home' => [['src/Init/InitStatusCommand.php', 'src/Init'], false];
        yield 'directory with trailing slash' => [['src/'], false];
        yield 'dot directory' => [['.github'], false];
        yield 'glob pattern' => [['src/**/*.php'], false];
        yield 'empty scope' => [[], false];
    }
}
