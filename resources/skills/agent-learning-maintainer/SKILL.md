---
name: agent-learning-maintainer
description: Maintain voku/agent-learning package source, tests, docs, CLI behavior, configurable paths, constraint lifecycle code, and local vendor syncs in consuming projects.
---

# Agent Learning Maintainer

Use this skill when changing the `voku/agent-learning` package itself. Keep package behavior generic; consuming-project conventions belong in configuration, examples, or tests, not hard-coded paths.

## Fast Path

1. Inspect `git status --short` and the relevant package source before editing.
2. Preserve source-package authority: package repo first, consuming-project `vendor/voku/agent-learning` only for local integration testing.
3. Convert project-specific assumptions into `config.json`, CLI options, docs, examples, or tests.
4. Keep activation human-gated. Package code may generate candidates and manifests, but should not silently approve durable guidance.
5. For guidance event work, keep projection deterministic from JSONL, keep policies tier-specific, and write only candidate proposals under `proposals/candidate`.
6. Add focused regression tests for lifecycle state, path resolution, manifest activation, generation-package export, event projection, policy gates, or CLI behavior when those change.
7. Validate from the package repo.

## Validation

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
```

When testing inside a consuming project, separately report those integration commands and do not treat them as a substitute for package tests.
