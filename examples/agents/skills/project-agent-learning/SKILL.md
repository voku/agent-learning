---
name: project-agent-learning
description: Capture reviewable agent-learning findings for this repository after meaningful sessions.
---

# Project Agent Learning

Use this wrapper around `agent-learning-consumer` at task close-out, or when the user asks the agent to preserve a durable workflow lesson.

## Fast Path

1. Read this repository's learning root README and `infra/doc/agent-learning/config.json`.
2. Check existing memory, skills, docs, and constraints before writing a new finding.
3. Capture only durable signals: user corrections, repeated failures, missed validation, repo rules, or hard-constraint candidates.
4. Default first occurrences to `ADD_LEARNING_NOTE`; use `IGNORE` for praise, summaries, and one-off task details.
5. Require `pattern_key` and `validation_case.given / when / then` for every non-`IGNORE` learning.
6. Run `vendor/bin/agent-learning validate --root infra/doc/agent-learning`.
7. Hand off durable guidance edits to `project-agent-guidance-maintenance`; do not self-approve promotions.

## Commands

```bash
vendor/bin/agent-learning validate --root infra/doc/agent-learning
vendor/bin/agent-learning prepare --root infra/doc/agent-learning --task PROJECT-123
vendor/bin/agent-learning proposal-validate --root infra/doc/agent-learning --proposal proposal.2026-06-15.001.json
```
