# LearningNotes

A `LearningNote` is a durable, evidence-backed solved-case precedent owned by `agent-learning`.

It preserves useful context from validated `ADD_LEARNING_NOTE` Findings without promoting that context into active guidance merely because the note exists.

```text
validated ADD_LEARNING_NOTE Finding(s)
        |
        v
note prepare / typed LearningNotePreparer
        |
        v
bounded authoring
        |
        v
note publish / typed LearningNotePublisher
        |
        v
LearningNote precedent
```

## Authority boundary

A LearningNote is historical/reusable context. It is not:

- Contract or Run authority;
- active Memory/Skill guidance;
- a Constraint;
- approval of a Proposal;
- permission to widen task scope;
- verification that a current implementation is correct.

Promotion into reviewed guidance still uses the existing Proposal lifecycle.

## Durable state

LearningNotes use schema version `1.0` and a minimal lifecycle:

```text
active
retired
```

The storage layout under the Learning root is owner-private. Sibling packages should consume `LearningNoteCatalog` / `LearningNoteProjection`, not reconstruct note paths.

An active `pattern_key` has exactly one LearningNote owner. Additional validated Findings for the same pattern update that note while preserving source lineage.

## Source lineage and drift

Publishing requires one or more Findings that are:

- in the validated/consolidated lifecycle used for note creation;
- classified `ADD_LEARNING_NOTE`;
- carrying one stable `pattern_key`;
- carrying a concrete `validation_case`.

Repository evidence may include project-relative source references with exact SHA-256 hashes. `LearningNoteStatusInspector` reports:

- `current` when all hashable sources still match;
- `review_needed` when a source exists but changed;
- `source_missing` when a referenced source disappeared;
- `no_hashable_evidence` when the note has no hashable repository evidence.

Source drift does not auto-rewrite or auto-retire the note.

## Operator CLI

The standalone projection is intentionally thin:

```bash
vendor/bin/agent-learning-note prepare --root .agent-loop/learning --finding finding.2026-08-31.001

vendor/bin/agent-learning-note publish \
  --root .agent-loop/learning \
  --finding finding.2026-08-31.001 \
  --content-file /tmp/learning-note-content.json

vendor/bin/agent-learning-note status --root .agent-loop/learning

vendor/bin/agent-learning-note retire \
  --root .agent-loop/learning \
  --id learning-note.2026-08-31.001 \
  --reason "Superseded by reviewed guidance."
```

CLI commands delegate to the same typed owner APIs. Invalid lineage, duplicate active pattern ownership, unsafe paths, redaction failures and unsupported schemas fail explicitly.

## Non-goals

LearningNotes do not introduce semantic similarity search, embeddings, automatic authoring in PHP, automatic Skill/Constraint creation, automatic Proposal approval, or another workflow phase.
