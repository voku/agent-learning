# Guidance Usage Evaluation

`guidance-evaluate` consumes immutable event histories from `voku/agent-recall-compiler` and rebuilds usage summaries deterministically.
It never edits `MEMORY.md`, skills, active constraints, PHPStan configuration, or CI.
With `--write-candidates`, it may write only reviewable proposal files under `proposals/candidate/`.

```bash
vendor/bin/agent-learning guidance-evaluate \
  --root infra/doc/agent-learning \
  --selection-history history/recall-selections.jsonl \
  --outcome-history history/outcomes.jsonl
```

The evaluator rejects unknown schema versions, malformed timestamps, duplicate event IDs, duplicate `compilation_id + guidance_id` pairs, unknown guidance types, unknown outcome values, empty task IDs, and outcome events without a corresponding selection event.
All records pass through `RedactionGuard`.

Projection is rebuilt from JSONL every run and does not persist counters.
It separates eligibility, selection, application, explicit feedback, distinct tasks, evidence event IDs, and last relevant timestamps.
Selection means the guidance was selected into a closed session; it does not prove model attention, application, or usefulness.

Decisions are `NO_ACTION`, `PROMOTION_CANDIDATE`, `STALE_CANDIDATE`, `REPLACEMENT_CANDIDATE`, or `CONFLICT`.
Each decision carries guidance ID, source tier, target tier when applicable, evidence event IDs, independent task IDs, reason, uncertainty, proposed scope, validation requirements, and source findings when available.

Promotion policies are separate and conservative:

- Finding to memory requires at least two validated findings across two task IDs, explicit scope, and no automatic mutation.
- Memory to skill requires at least three selected closed sessions, two helpful sessions, zero harmful outcomes, two independent task IDs, a recurring procedure, and validated source findings.
- Skill to constraint requires repeated selected/applied/helpful use, zero harmful outcomes, objective detectability, low or justified false-positive risk, validation commands, local examples or fixtures, repetitive manual-review cost, and a reviewable `constraint_candidate` specification.

Memory and skill inactivity can only create reviewable stale candidates.
Hard constraints never become stale from inactivity; concrete negative evidence such as harmful or false-positive feedback is required for review.

`--write-candidates` writes candidate proposals only.
It does not approve, apply, activate, delete, or edit target guidance.
Candidate proposals retain event provenance under `evolution_decision`.
Repeated runs are idempotent for the same decision key.
