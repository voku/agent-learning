# LearningNotes

`LearningNote` is the durable solved-case / precedent product for findings classified as `ADD_LEARNING_NOTE`.

It preserves useful context around a verified case without claiming that the case is already a generally applicable instruction, Skill, ADR, Memory rule, or executable Constraint.

## Authority boundary

A LearningNote is durable Learning-owned knowledge, but it is **not active guidance by existence alone**.

The intended flow is:

```text
validated Finding(s)
  -> ADD_LEARNING_NOTE classification
  -> LearningNote
  -> later task may consume it as precedent

repeated/generalized evidence
  -> existing Proposal review flow
  -> Memory / Skill / Constraint when explicitly approved
```

Creating, updating, reading, checking, or retiring a LearningNote never approves or applies a Proposal.

## Typed owner API

Use `LearningNoteService` instead of parsing or writing `notes/**` directly.

The supported owner operations are:

- `prepare()` — validate source Findings and produce a bounded authoring package;
- `publish()` — validate lineage/redaction/pattern ownership and atomically publish or update one active note;
- `activeProjections()` — return compact typed read projections for sibling consumers;
- `evidenceState()` — distinguish current evidence, changed evidence, missing sources, and notes without hashable repository evidence;
- `retire()` — explicitly retire a note while preserving lineage and reason.

One active `LearningNote` owns one stable `pattern_key`. Later validated Findings with the same pattern update that note and accumulate source lineage, scope, tags, and repository evidence rather than creating competing active precedents.

## Standalone CLI

The package exposes a thin operator/script projection over the same owner service:

```bash
vendor/bin/agent-learning-note prepare \
  --root .agent-loop/learning \
  --finding finding.2026-08-31.001

vendor/bin/agent-learning-note publish \
  --root .agent-loop/learning \
  --input learning-note-candidate.json

vendor/bin/agent-learning-note status \
  --root .agent-loop/learning

vendor/bin/agent-learning-note retire \
  --root .agent-loop/learning \
  learning-note.2026-08-31.abcdef \
  --reason "Superseded by narrower evidence."
```

`publish` accepts an authoring candidate, not the private persisted storage schema. Callers provide source Finding IDs, optional source Proposal IDs, tags, repository evidence, and structured solved-case content; Learning derives the stable pattern, validation case, lifecycle fields, timestamps, and durable storage representation.

## Repository evidence and drift

Repository evidence is project-relative and SHA-256 bound. Absolute paths and `..` traversal are rejected. When a referenced source changes, the note remains durable historical precedent but is reported as `review_needed`; drift alone never silently rewrites or retires the note.

Configured durable state with an unsupported LearningNote schema fails explicitly. Derived consumers may rebuild their own context, but they must not reinterpret Learning-private storage.

## Content shape

A note supports structured case fields rather than forcing every learning into a bug template:

- title;
- context;
- guidance;
- why it works;
- when to apply;
- when not to apply;
- verification;
- optional symptoms;
- optional failed approaches;
- optional root cause;
- optional examples.

Bug-shaped learnings may use symptoms/root cause. Architecture, convention, tooling, and workflow precedents may omit them.
