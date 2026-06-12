<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\ActiveGuidanceRepository;
use voku\AgentLearning\GuidanceType;
use voku\AgentLearning\ValidationException;

final class ActiveGuidanceRepositoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/active-guidance-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLoadGuidanceParsesMetadataAndValidates(): void
    {
        $file1 = $this->root . '/MEMORY.md';
        file_put_contents($file1, "<!-- id: custom-memory -->\n<!-- type: memory -->\n<!-- scope: src/Auth -->\nMemory Content");

        $file2 = $this->root . '/skills-auth.md';
        file_put_contents($file2, "skill content");

        $repo = new ActiveGuidanceRepository();
        $guidances = $repo->loadAll($this->root, ['MEMORY.md', 'skills-auth.md']);

        self::assertCount(2, $guidances);
        
        // Sorting is deterministic by ID: custom-memory vs skill.skills-auth
        self::assertSame('custom-memory', $guidances[0]->id);
        self::assertSame(GuidanceType::MEMORY, $guidances[0]->type);
        self::assertSame(['src/Auth'], $guidances[0]->scope);
        self::assertSame("<!-- id: custom-memory -->\n<!-- type: memory -->\n<!-- scope: src/Auth -->\nMemory Content", $guidances[0]->content);

        self::assertSame('skill.skills-auth', $guidances[1]->id);
        self::assertSame(GuidanceType::SKILL, $guidances[1]->type);
        self::assertSame(['/'], $guidances[1]->scope);
    }

    public function testFailsOnMissingFile(): void
    {
        $repo = new ActiveGuidanceRepository();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('guidance file does not exist');
        $repo->loadAll($this->root, ['missing.md']);
    }

    public function testFailsOnEmptyFile(): void
    {
        $file = $this->root . '/empty.md';
        file_put_contents($file, "  \n  ");
        $repo = new ActiveGuidanceRepository();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty guidance content');
        $repo->loadAll($this->root, ['empty.md']);
    }

    public function testFailsOnDuplicateId(): void
    {
        $file1 = $this->root . '/one.md';
        file_put_contents($file1, "<!-- id: dup -->\none");
        $file2 = $this->root . '/two.md';
        file_put_contents($file2, "<!-- id: dup -->\ntwo");

        $repo = new ActiveGuidanceRepository();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('duplicate guidance ID');
        $repo->loadAll($this->root, ['one.md', 'two.md']);
    }

    public function testFailsOnRedactionMatch(): void
    {
        $file = $this->root . '/secret.md';
        file_put_contents($file, "password = secret123");

        $repo = new ActiveGuidanceRepository();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sensitive-data match');
        $repo->loadAll($this->root, ['secret.md']);
    }

    public function testParsesFrontmatterWithoutTrailingNewline(): void
    {
        $file = $this->root . '/fm.md';
        file_put_contents($file, "---\nid: fm-test\ntype: constraint\nscope: [src/Auth, src/Db]\n---");

        $repo = new ActiveGuidanceRepository();
        $guidances = $repo->loadAll($this->root, ['fm.md']);

        self::assertCount(1, $guidances);
        self::assertSame('fm-test', $guidances[0]->id);
        self::assertSame(GuidanceType::CONSTRAINT, $guidances[0]->type);
        self::assertSame(['src/Auth', 'src/Db'], $guidances[0]->scope);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
