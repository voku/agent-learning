# AGENTS.md

## Repository role

`voku/agent-learning` owns durable, reviewable learning state: Findings, Proposals, evidence, decision history, guidance evolution, constraint specifications, and the explicit transitions that make learning durable.

This package decides what learning artifacts mean and whether they are structurally valid. It does not own workflow approval, execution authority, Session working memory, Recall context selection, or automatic mutation of project policy.

## Dependency direction

This package is a low-level owner with no runtime `agent-*` dependency. Preserve that direction.

- Do not add `agent-loop`, `agent-recall-compiler`, `agent-session`, `agent-kanban`, or `agent-loop-runner` as runtime dependencies to solve orchestration concerns.
- Recall may consume Learning-owned guidance, constraints, selections, and outcome history, but Learning must not depend back on Recall to define its durable model.
- Workflow hosts may decide when a Learning transition is requested; this package owns validation and persistence semantics for that transition.

## Invariants to preserve

- Findings are observations/hypotheses with explicit validation state; confidence is never validation.
- Durable policy changes flow through reviewed Proposals and recorded decisions. Never auto-promote model output, outcome counts, review prose, or a Dream result into approved guidance.
- `CREATE_SKILL` is exceptional. Prefer updating an existing owner when one already exists, and require overlap evidence before creating a new durable skill surface.
- Constraint promotion must stay evidence-backed and explicit about engine, scope, rule identity, validation commands, and false-positive risk.
- Immutable histories remain the audit source. Compact projections are derived views and must be rebuildable from source history.
- `dream` is deterministic/read-mostly review machinery. It may surface candidates and uncertainty, but it must not approve, apply, retire, delete, or rewrite guidance on its own.
- A Learning record must not claim workflow authority, implementation completion, current test truth, or review acknowledgement merely because it references those artifacts as evidence.

## Implementation guidance

Keep state transitions explicit, typed, and auditable. Prefer owner APIs/repositories over direct path knowledge in consumers. Treat external/model-produced statements as candidate evidence until validated. Preserve source IDs, lineage, and reasons across replacement/retirement/superseding operations.

When adding a new durable field, ask which lifecycle transition owns it and how old records remain readable. Do not introduce mutable counters when the value can be projected deterministically from immutable events.

## Validation

Run:

```bash
composer ci
```

This includes strict Composer validation, PHPUnit, and PHPStan.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must identify a release-ready ancestor commit whose own `CHANGELOG.md` contains the release section. Existing tags are immutable evidence; do not retarget them.