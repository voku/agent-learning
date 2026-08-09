# Applied Memory/Skill guidance must exist in the repository

`APPLIED` is stronger than `APPROVED`.

For `target_type=memory` and `target_type=skill`, an applied proposal must prove
that the reviewed mutation exists in a concrete file under the configured
project root. A proposal record saying that work was applied is not evidence that
the canonical target actually contains the change.

## Required application evidence

The proposal's `applied_validation` object must include:

```json
{
  "target_source_ref": "path/inside/project",
  "target_content_hash": "<sha256 hex>"
}
```

`target_source_ref` is a repository-relative path. Absolute paths and path
traversal are rejected. `target_content_hash` is the SHA-256 digest of the real
target file after the mutation.

Repository validation resolves the target under the configured project root and
checks all of the following:

1. the path remains inside the project root;
2. the target file exists and can be read;
3. its SHA-256 digest matches `target_content_hash`;
4. `ADD`: the proposed new wording is present;
5. `REPLACE`: the new wording is present and the old wording is absent;
6. `DELETE`: the old wording is absent.

If any check fails, repository validation fails. During
`ProposalTransitionManager::apply()` that failure rolls the transition back to
`approved` and no successful application decision is appended.

## Why the semantic target is not enough

Proposal `target` remains semantic identity, for example `skill.auth-context` or
`memory.testing`. It answers **what durable guidance is being changed**.

`target_source_ref` answers a different question: **where is the reviewed change
physically present right now**.

Keeping those concepts separate avoids turning repository paths into durable
semantic IDs while still preventing an `APPLIED` record from outrunning reality.

## Constraint proposals

Constraint application keeps its existing stronger generated-files,
registration, content-hash, commit, tests, and validation contract. The
Memory/Skill target validator does not replace that path.

## Authority handoff remains separate

Physical application proof does not by itself mean another package consumes the
canonical target. A Skill file can exist without a recall configuration selecting
it. `APPLIED` therefore proves application, not automatic authority handoff or
activation. Retirement and downstream canonical-source selection remain separate,
reviewable steps.
