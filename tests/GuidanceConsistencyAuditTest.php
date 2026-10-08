<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\GuidanceConsistencyAudit;
use voku\AgentLearning\GuidanceConsistencyCandidate;

final class GuidanceConsistencyAuditTest extends TestCase
{
    private const string LONG_RULE = 'Always run the project validation inside the container before reporting a result, because a host run can pass for reasons that do not hold in the container and the report would then claim a check that never happened here.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guidance-consistency-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/docs/sibling', 0777, true);
        mkdir($this->root . '/lib', 0777, true);
        file_put_contents($this->root . '/docs/real.md', "# Real\n");
        file_put_contents($this->root . '/docs/sibling/x.md', "# X\n");
        file_put_contents($this->root . '/lib/Thing.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOnlyPathsThatDoNotExistAreReportedWithTheirLine(): void
    {
        file_put_contents($this->root . '/docs/a.md', implode("\n", [
            '# Guide',
            'See `docs/real.md` and `lib/Thing.php:12` for the rule.',
            'The old rule lives in `docs/gone.md` and in `lib/Missing.php:7`.',
        ]) . "\n");

        $found = $this->paths(['docs/a.md']);

        self::assertSame(['docs/a.md:3 docs/gone.md', 'docs/a.md:3 lib/Missing.php'], $found);
    }

    public function testPlaceholdersGlobsUrlsAndCodeBlocksAreNotChecked(): void
    {
        file_put_contents($this->root . '/docs/a.md', implode("\n", [
            'Pattern `docs/*.md`, placeholder `docs/<name>.md`, template `docs/{x}.md`, link `https://example.com/a/b.md`,',
            'a class `Foo::bar` and a call `$x->get/set`, plus `lib/Thing.php::method`.',
            '```',
            'echo `docs/hidden-in-a-fence.md` is not a claim about the repository',
            '```',
            'A bare word `README` and a single segment `notes.md` have no directory part.',
        ]) . "\n");

        self::assertSame([], $this->paths(['docs/a.md']));
    }

    public function testAPathIsResolvedAgainstTheProjectRootAndAgainstTheFileItself(): void
    {
        file_put_contents($this->root . '/docs/a.md', "Sibling `sibling/x.md` and root `lib/Thing.php` and missing `sibling/nope.md`.\n");

        self::assertSame(['docs/a.md:1 sibling/nope.md'], $this->paths(['docs/a.md']));
    }

    public function testADirectoryReferenceWithATrailingSlashIsChecked(): void
    {
        file_put_contents($this->root . '/docs/a.md', "Generated into `build/out/` and sources in `docs/sibling/`.\n");

        self::assertSame(['docs/a.md:1 build/out'], $this->paths(['docs/a.md']));
    }

    public function testAPathTheVersionControlIgnoresIsAGeneratedPathNotAStaleOne(): void
    {
        file_put_contents($this->root . '/docs/a.md', "Local state in `runtime/cache/state.json` and a removed file `docs/gone.md`.\n");

        $found = array_map(
            static fn (GuidanceConsistencyCandidate $c): string => $c->evidence,
            (new GuidanceConsistencyAudit())->audit($this->root, ['docs/a.md'], static fn (string $path): bool => str_starts_with($path, 'runtime/')),
        );

        self::assertSame(1, count($found));
        self::assertStringContainsString('docs/gone.md', $found[0]);
    }

    public function testTheSamePassageInTwoFilesIsADuplicateAndNamesBothPlaces(): void
    {
        file_put_contents($this->root . '/docs/a.md', "# A\n\nIntro line.\n\n" . self::LONG_RULE . "\n");
        file_put_contents($this->root . '/docs/b.md', "# B\n\n" . self::LONG_RULE . " It also adds one extra closing sentence about reporting.\n");

        $duplicates = $this->candidates(['docs/*.md'], GuidanceConsistencyCandidate::KIND_DUPLICATE_WORDING);

        self::assertCount(1, $duplicates);
        self::assertSame('docs/a.md:5', $duplicates[0]->sourceA);
        self::assertSame('docs/b.md:3', $duplicates[0]->sourceB);
        self::assertMatchesRegularExpression('/\d+% of the shorter passage/', $duplicates[0]->evidence);
    }

    public function testRepeatingAPassageInsideOneFileShortTextAndDifferentTextAreNotDuplicates(): void
    {
        file_put_contents($this->root . '/docs/a.md', self::LONG_RULE . "\n\n" . self::LONG_RULE . "\n\nShort sentence about the same container rule.\n");
        file_put_contents($this->root . '/docs/b.md', "Short sentence about the same container rule.\n\nA completely different paragraph that talks about translations, German source strings, the naming rules and the way user facing text is stored in the portal translation table for later review.\n");

        self::assertSame([], $this->candidates(['docs/*.md'], GuidanceConsistencyCandidate::KIND_DUPLICATE_WORDING));
    }

    public function testALongTableRowIsComparedLikeAParagraph(): void
    {
        $row = '| container rule | ' . self::LONG_RULE . ' | `docs/real.md` |';
        file_put_contents($this->root . '/docs/a.md', "| Subject | Rule | Home |\n| --- | --- | --- |\n" . $row . "\n");
        file_put_contents($this->root . '/docs/b.md', "Some prose.\n\n" . self::LONG_RULE . "\n");

        $duplicates = $this->candidates(['docs/*.md'], GuidanceConsistencyCandidate::KIND_DUPLICATE_WORDING);

        self::assertCount(1, $duplicates);
        self::assertSame('docs/a.md:3', $duplicates[0]->sourceA);
    }

    public function testNoCandidateCarriesARecommendation(): void
    {
        foreach ((new \ReflectionClass(GuidanceConsistencyCandidate::class))->getProperties() as $property) {
            self::assertStringNotContainsString('recommend', strtolower($property->getName()));
            self::assertStringNotContainsString('verdict', strtolower($property->getName()));
        }
    }

    /**
     * @param list<string> $globs
     *
     * @return list<string> "file:line path", sorted
     */
    private function paths(array $globs): array
    {
        $out = array_map(
            static fn (GuidanceConsistencyCandidate $c): string => $c->sourceA . ' ' . trim(explode('`', $c->evidence)[1] ?? ''),
            $this->candidates($globs, GuidanceConsistencyCandidate::KIND_UNRESOLVED_PATH),
        );
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $globs
     *
     * @return list<GuidanceConsistencyCandidate>
     */
    private function candidates(array $globs, string $kind): array
    {
        return array_values(array_filter(
            (new GuidanceConsistencyAudit())->audit($this->root, $globs, static fn (string $path): bool => false),
            static fn (GuidanceConsistencyCandidate $c): bool => $c->kind === $kind,
        ));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
