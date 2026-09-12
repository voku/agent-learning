from pathlib import Path


def replace_once(source: str, old: str, new: str, label: str) -> str:
    count = source.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one {label} target, got {count}")
    return source.replace(old, new, 1)


service_path = Path("src/LearningLineageService.php")
source = service_path.read_text()

source = replace_once(
    source,
    """    public function precedentsForTask(
        string $root,
        string $taskId,
        ?string $projectRoot = null,
        int $maximumRelatedIdentities = 100,
    ): LearningTaskPrecedentResult {""",
    """    /**
     * @param list<string> $taskFiles
     * @param list<string> $taskTags
     */
    public function precedentsForTask(
        string $root,
        string $taskId,
        ?string $projectRoot = null,
        int $maximumRelatedIdentities = 100,
        array $taskFiles = [],
        array $taskTags = [],
    ): LearningTaskPrecedentResult {""",
    "service signature",
)

source = replace_once(
    source,
    """        $existingIds = array_fill_keys(
            array_map(static fn (LearningNoteProjection $projection): string => $projection->id, $precedents),
            true,
        );
        $remainingCapacity = $maximumRelatedIdentities - count($precedents);
        $topUpIds = [];
        $precedentsTruncated = false;
        foreach ($this->activeNoteIds($root) as $activeNoteId) {
            if (isset($existingIds[$activeNoteId])) {
                continue;
            }
            if (count($topUpIds) >= $remainingCapacity) {
                $precedentsTruncated = true;
                break;
            }
            $topUpIds[] = $activeNoteId;
        }
        foreach ($topUpIds as $activeNoteId) {
            $precedents[] = $this->projectActiveNote($root, $activeNoteId, $projectRoot);
        }
""",
    """        $existingIds = array_fill_keys(
            array_map(static fn (LearningNoteProjection $projection): string => $projection->id, $precedents),
            true,
        );
        $remainingCapacity = $maximumRelatedIdentities - count($precedents);
        $taskFiles = $this->canonicalPaths($taskFiles);
        $taskTags = $this->canonicalTags($taskTags);
        $hasTaskContext = $taskFiles !== [] || $taskTags !== [];
        $topUpIds = [];
        $precedentsTruncated = false;
        foreach ($this->activeNoteIds($root) as $activeNoteId) {
            if (isset($existingIds[$activeNoteId])) {
                continue;
            }
            if ($hasTaskContext) {
                $activeNote = $this->noteRepository->findActive($root, $activeNoteId);
                if (!$activeNote instanceof LearningNote) {
                    throw new RuntimeException('Current Learning precedent query references unavailable active LearningNote: ' . $activeNoteId);
                }
                if (!$this->matchesTaskContext($activeNote, $taskFiles, $taskTags)) {
                    continue;
                }
            }
            if (count($topUpIds) >= $remainingCapacity) {
                $precedentsTruncated = true;
                break;
            }
            $topUpIds[] = $activeNoteId;
        }
        foreach ($topUpIds as $activeNoteId) {
            $precedents[] = $this->projectActiveNote($root, $activeNoteId, $projectRoot);
        }
""",
    "top-up",
)

helpers = r'''    /**
     * @param list<string> $taskFiles
     * @param list<string> $taskTags
     */
    private function matchesTaskContext(LearningNote $note, array $taskFiles, array $taskTags): bool
    {
        $scope = $this->canonicalPaths($note->scope);
        if ($scope === [] || in_array('*', $scope, true) || in_array('/', $scope, true)) {
            return true;
        }

        foreach ($taskFiles as $taskFile) {
            foreach ($scope as $candidate) {
                $prefix = rtrim($candidate, '/');
                if ($prefix === '') {
                    continue;
                }
                if ($taskFile === $prefix || str_starts_with($taskFile, $prefix . '/')) {
                    return true;
                }
            }
        }

        return array_intersect($this->canonicalTags($note->tags), $taskTags) !== [];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function canonicalPaths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path !== '/' && $path !== '*') {
                $path = ltrim(preg_replace('~/+~', '/', $path) ?? $path, './');
            }
            if ($path !== '') {
                $result[] = $path;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function canonicalTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if ($tag !== '') {
                $result[] = $tag;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

'''
source = replace_once(
    source,
    """    /** @return list<string> */
    private function activeNoteIds(string $root): array
""",
    helpers + """    /** @return list<string> */
    private function activeNoteIds(string $root): array
""",
    "helper insertion",
)
service_path.write_text(source)


test_path = Path("tests/LearningLineageBoundedPrecedentTest.php")
tests = test_path.read_text()

new_test = r'''    public function testTaskContextFiltersTopUpBeforeApplyingTheBound(): void
    {
        $repository = new LearningNoteRepository();
        $repository->publish(
            $this->root,
            $this->note(
                'learning-note.2026-09-09.aaaaaa',
                'scope.irrelevant',
                scope: ['docs/'],
                tags: ['other'],
            ),
        );
        $repository->publish(
            $this->root,
            $this->note(
                'learning-note.2026-09-09.bbbbbb',
                'scope.file_match',
                scope: ['src/Target.php'],
                tags: ['other'],
            ),
        );
        $repository->publish(
            $this->root,
            $this->note(
                'learning-note.2026-09-09.cccccc',
                'scope.tag_match',
                scope: ['docs/'],
                tags: ['target'],
            ),
        );

        $service = new LearningLineageService();
        $service->rebuild($this->root, $this->root);

        $limited = $service->precedentsForTask(
            $this->root,
            'NO-LINEAGE-87',
            projectRoot: $this->root,
            maximumRelatedIdentities: 1,
            taskFiles: ['src/Target.php'],
            taskTags: ['TARGET'],
        );

        self::assertSame(
            ['learning-note.2026-09-09.bbbbbb'],
            array_map(static fn (LearningNoteProjection $precedent): string => $precedent->id, $limited->precedents),
        );
        self::assertTrue($limited->precedentsTruncated);

        $complete = $service->precedentsForTask(
            $this->root,
            'NO-LINEAGE-87',
            projectRoot: $this->root,
            maximumRelatedIdentities: 2,
            taskFiles: ['src/Target.php'],
            taskTags: ['target'],
        );
        self::assertSame(
            [
                'learning-note.2026-09-09.bbbbbb',
                'learning-note.2026-09-09.cccccc',
            ],
            array_map(static fn (LearningNoteProjection $precedent): string => $precedent->id, $complete->precedents),
        );
        self::assertFalse($complete->precedentsTruncated);
    }

'''
tests = replace_once(
    tests,
    """    /** @param list<LearningNoteRepositoryEvidence> $repositoryEvidence */
    private function note(string $id, string $patternKey, array $repositoryEvidence = []): LearningNote
""",
    new_test + """    /** @param list<LearningNoteRepositoryEvidence> $repositoryEvidence */
    private function note(string $id, string $patternKey, array $repositoryEvidence = []): LearningNote
""",
    "test insertion",
)

tests = replace_once(
    tests,
    """    /** @param list<LearningNoteRepositoryEvidence> $repositoryEvidence */
    private function note(string $id, string $patternKey, array $repositoryEvidence = []): LearningNote
    {
        return new LearningNote(
            id: $id,
            patternKey: $patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: ['scale'],
""",
    """    /**
     * @param list<LearningNoteRepositoryEvidence> $repositoryEvidence
     * @param list<string> $scope
     * @param list<string> $tags
     */
    private function note(
        string $id,
        string $patternKey,
        array $repositoryEvidence = [],
        array $scope = ['src/'],
        array $tags = ['scale'],
    ): LearningNote {
        return new LearningNote(
            id: $id,
            patternKey: $patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: $scope,
            tags: $tags,
""",
    "test helper",
)
test_path.write_text(tests)
