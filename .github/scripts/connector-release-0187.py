from pathlib import Path

path = Path('CHANGELOG.md')
source = path.read_text()
anchor = '## [Unreleased]\n'
entry = '''## [Unreleased]\n\n## [0.18.7] - 2026-09-12\n\n### Added\n\n- Add explicit post-capture Finding classification through `FindingClassifier` and the `finding-classify` CLI command. Raw observation capture remains separate from reusable-learning triage, while supported tooling can now provide classification, pattern identity, and a validation case so `LearningNoteService::promotionReadiness()` can become satisfiable without schema edits or private-file mutation.\n\n### Fixed\n\n- `LearningLineageService::precedentsForTask()` can now accept task files and tags and applies that coarse relevance to active-note top-up candidates before the bounded capacity cut. Exact lineage precedents remain intact, legacy callers without task context keep their existing behavior, and expensive owner projection remains limited to selected candidates. This fixes the scale failure from #87 where lexicographically earlier unrelated note IDs consumed the entire top-up window and Recall never saw cross-task precedents that actually matched the task.\n\n### Validation\n\n- PR #103 passed exact-head PHP 8.3, 8.4 and 8.5 `composer ci` plus the installed-skill consumer for the supported Finding classification path.\n- PR #104 passed exact-head PHP 8.3, 8.4 and 8.5 `composer ci`, PHPStan, the bounded file/tag relevance regression, and the installed-skill consumer.\n'''
if source.count(anchor) != 1:
    raise SystemExit(f'expected one Unreleased anchor, got {source.count(anchor)}')
path.write_text(source.replace(anchor, entry, 1))
