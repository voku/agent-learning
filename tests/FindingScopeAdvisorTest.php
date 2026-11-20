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

    public function testExistingPathsAreClassifiedByTheFilesystem(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-scope-advisor-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/Init.v2', 0o777, true);
        mkdir($root . '/src/Init..v3', 0o777, true);
        file_put_contents($root . '/Dockerfile', "FROM php\n");
        $advisor = new FindingScopeAdvisor();

        try {
            self::assertSame([], $advisor->advise(['src/Init.v2'], $root), 'an existing dotted directory is a directory');
            self::assertSame([], $advisor->advise(['src/Init..v3'], $root), 'a double dot inside a name is not a parent segment');
            self::assertSame(
                [FindingScopeAdvisor::SCOPE_NAMES_ONLY_FILES],
                array_column($advisor->advise(['Dockerfile'], $root), 'code'),
                'an existing extensionless file is a file',
            );
            self::assertSame([], $advisor->advise(['Dockerfile'], null), 'without a project root an extensionless name stays unadvised');
        } finally {
            unlink($root . '/Dockerfile');
            rmdir($root . '/src/Init.v2');
            rmdir($root . '/src/Init..v3');
            rmdir($root . '/src');
            rmdir($root);
        }
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
