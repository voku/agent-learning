---
name: project-agent-guidance-maintenance
description: Maintain repository-owned agent skills, memory, docs, and installed copies after reviewed durable learnings.
---

# Project Agent Guidance Maintenance

Use this wrapper after a learning proposal has enough evidence to change durable guidance.

## Fast Path

1. Edit the canonical repository source first, such as `infra/doc/agents/skills/`, `MEMORY.md`, or the owning docs.
2. Prefer updating an existing skill or doc over creating a new one.
3. Keep the edit bounded: add, replace, delete, or retire one rule at a time.
4. Keep deletion explicit and recoverable; stale guidance becomes a review candidate, not a silent drop.
5. Validate the changed guidance and reinstall generated client copies through repository commands.
6. Mark the learning proposal applied only after validation evidence exists.

## Do Not Store

- praise
- success summaries
- self-justification
- duplicated rules already owned elsewhere
- generic advice without a validation case
