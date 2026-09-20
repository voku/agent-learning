---
name: agent-skill-promotion
description: Author a bounded UPDATE_SKILL or CREATE_SKILL promotion candidate from validated voku/agent-learning evidence, choosing an existing semantic owner first and shaping discoverable, cohesive, progressively disclosed guidance without approving or applying it.
argument-hint: "[validated Finding ID ...] [classification: UPDATE_SKILL|CREATE_SKILL]"
---

# Agent Skill Promotion

Use this skill only after Learning evidence has been validated and classified toward `UPDATE_SKILL` or `CREATE_SKILL`. It turns that evidence into a reviewable skill-mutation candidate. It does **not** decide approval, apply guidance, or create a second promotion lifecycle.

## Authority boundary

The Learning owner remains authoritative for Finding state, classification, pattern lineage, proposal lifecycle, and human approval.

This skill may:

- inspect the supplied validated Finding evidence and current target skills;
- recommend an existing skill target or a new skill boundary consistent with the supplied classification;
- author bounded candidate wording and structure;
- report conflicts, overlap, missing evidence, or a better deterministic enforcement target.

This skill must never:

- change a Finding classification silently;
- approve, apply, retire, or acknowledge a Proposal;
- edit `proposals/**` directly;
- mutate the target skill as if a candidate were already approved;
- create active Memory, Skill, or Constraint state;
- treat repeated wording as proof of a reusable pattern.

## 1. Start from explicit Learning evidence

Require explicit validated Finding IDs plus their current Learning classification, `pattern_key`, and `validation_case`.

For `CREATE_SKILL`, require the existing Learning overlap evidence. Do not replace that owner check with a fresh intuition-based similarity claim.

If source Findings disagree materially, span unrelated patterns, or no longer support the classified destination, return `SKILL_PROMOTION_BLOCKED` with the exact conflict. Do not repair classification by implication.

## 2. Prefer the existing semantic owner

Before drafting wording, inspect the smallest current skill set needed to answer:

1. Does an existing skill already own this behavior or decision?
2. Would updating that owner preserve a coherent trigger and scope?
3. Is the proposed guidance portable, or is it actually coupled to one tool's CLI, schema, storage, or lifecycle?
4. Is the behavior objectively enforceable enough that a test, static rule, schema, type, or CI gate is the better durable destination?

For `UPDATE_SKILL`, name the existing target and the exact boundary being changed.

For `CREATE_SKILL`, keep the new skill only when the pattern has a distinct discovery trigger and can be reused or evolve independently of neighboring guidance. Mere file length or topic adjacency is not evidence for a new skill.

When another repository or package is the semantic owner, return a blocked or redirected candidate rather than duplicating its contract.

## 3. Define the discovery contract

A skill description is the discovery boundary. Draft it so a future agent can decide applicability before loading the body.

The description should state:

- the distinctive capability or governing decision;
- the smallest discriminating intents, artifacts, or failure modes that make it relevant;
- a narrow exclusion only when a competing workflow could otherwise be selected incorrectly.

Do not repeat a long trigger inventory inside the body after discovery has already happened.

## 4. Keep one coherent context boundary

The candidate skill should own one coherent procedure or body of judgment.

Keep guidance together when its parts always trigger together and cannot be reused independently.

Split or redirect guidance when a subsection has its own trigger, independent reuse, or independent evolution boundary. Do not split merely to shorten files, and do not widen the candidate because adjacent advice seems useful.

## 5. Use progressive disclosure

Keep the minimum governing model and rules needed for every invocation in `SKILL.md`.

Move detail behind a local rule/reference only when it is conditional, branch-specific, example-heavy, or otherwise unnecessary for every invocation.

Every routed detail should be directly discoverable from the entry point. The routing sentence must explain the decision or situation that makes the detail relevant; a bare filename or topic label is not enough.

Prefer a flat owned resource structure. Do not create documentation depth that carries no separate ownership.

## 6. Preserve portable ownership

A portable skill may describe reusable engineering judgment. Tool-coupled executable facts belong with the tool that owns them.

Do not copy another owner's current CLI commands, schemas, generated-file contracts, or lifecycle rules into a generic skill merely to make the candidate self-contained. Route to that owner instead.

If an objective invariant can already be enforced reliably, prefer `NO_DURABLE_LEARNING` or the existing hard-constraint path as appropriate to the current Learning classification and evidence. Do not keep prose active merely because prose is easier to write.

## 7. Produce a bounded promotion candidate

Return one candidate with:

- source Finding IDs;
- current classification;
- `pattern_key` and validation case;
- target existing skill or proposed new skill name;
- semantic-owner rationale;
- overlap evidence summary;
- discovery description;
- proposed `SKILL.md` governing content;
- conditional rule/reference files only when justified;
- scope and non-goals;
- validation plan;
- unresolved uncertainty.

Do not write the candidate into Learning-private proposal storage. Hand it to the existing reviewed Proposal flow.

## Terminal contract

Return exactly one terminal status:

```text
SKILL_PROMOTION_CANDIDATE <UPDATE_SKILL|CREATE_SKILL> <target>
SKILL_PROMOTION_REDIRECT <owner-or-constraint-path> <reason>
SKILL_PROMOTION_BLOCKED <reason>
```

A candidate means "ready for proposal review", not approved guidance.
