# L2 Meta-Prompt Briefing for Task: dogfood-agent-learning-loop
> Generated at 2026-06-18T22:32:59+00:00

## Task Description
Dogfood agent-learning and agent-recall-compiler on a real agent-learning maintenance task

## Repository Global Memory (`MEMORY.md`)
# Agent Learning Project Memory

Durable rules and boundaries for every coding session working on the `voku/agent-learning` package:

## 1. Governance Boundary
*   **Do not absorb task management or session recording**: This package must govern learning only. It is a firewall between untrusted LLM output and durable storage.
*   **Keep layers separate**:
    *   Findings under `findings/` represent raw experience.
    *   Proposals under `proposals/` manage transitions.
    *   Outcomes under `history/outcomes.jsonl` measure helpfulness/harmfulness.

## 2. Invariants & Validations
*   **Status Invariants**: `validation_status` must strictly align with the `status` enum (e.g., `REJECTED` status must be `validated` or `invalidated`, never `unverified`).
*   **Evidence Validation**: All findings require concrete, commit-safe evidence (e.g., test command, line reference, manual verification summary).
*   **Scope Invariants**:
    *   Proposal scope cannot be broadened compared to the source finding evidence unless a justification of at least 15 characters is provided in the `reason` field.
    *   Disjoint scope extensions (unrelated directory paths) must be treated as broadened scopes.
*   **Redaction Guard**: All file modifications and parsing operations must run through `RedactionGuard` to prevent accidental leak of sensitive content (e.g., passwords or secrets).

## 3. transitions
*   **Atomic Rollbacks**: Any file system modification during status transitions (approve, reject, apply) must use file backup and rollback logic to ensure repository validation is never left in a broken state.

## Selected Active Guidance
*No active task-specific guidance matched this task's scope.*
