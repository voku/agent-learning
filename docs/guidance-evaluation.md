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

## Decision-time attribution

A `guidance-outcome` event may carry an optional `attribution` object:

```json
"attribution": {
  "seen_before_decision": true,
  "also_prescribed_by": ["skill"]
}
```

- `seen_before_decision`: the session read the selected guidance before making the decision it credits.
- `also_prescribed_by`: every other source that already prescribed that decision. Allowed values are `task_prompt`, `contract`, `skill`, `template`, `constraint`, and `repository_docs`; an empty list states that no other source did.

A plain `helpful` judgement cannot distinguish "this changed my choice" from "this matches what I did anyway". Real history showed both confounds: guidance first read after the credited fix, and decisions already prescribed by an always-loaded skill, template, or Constraint.

`GuidanceUsageSummary::$attributableHelpfulEventIds` lists the `helpful` events whose attribution is unconfounded (read first, prescribed nowhere else). That list is the candidate set for a causal behavioral-value audit, not proof: the attribution is still a self-report and must be checked against the session evidence before any value is claimed. Records without `attribution` remain valid and are counted as before, but never enter that set. Malformed attribution fails closed.

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
