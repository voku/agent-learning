# Agent Learning Project Memory

Durable rules and boundaries for every coding session working on the `voku/agent-learning` package:

## 1. Governance Boundary
* **Do not absorb task management or session recording**: This package must govern learning only. It is a firewall between untrusted LLM output and durable storage.
* **Keep layers separate**:
  * Findings under `findings/` represent raw experience.
  * Proposals under `proposals/` manage transitions.
  * Outcomes under `history/outcomes.jsonl` measure helpfulness/harmfulness.
* **Expose typed owner operations when lifecycle hosts need to write Learning state**: orchestrators should call owner APIs such as `FindingCreator` instead of hand-authoring Learning JSON or depending on storage-directory choreography.

## 2. Invariants & Validations
* **Status Invariants**: `validation_status` must strictly align with the `status` enum (e.g., `REJECTED` status must be `validated` or `invalidated`, never `unverified`).
* **Evidence Validation**: All findings require concrete, commit-safe evidence (e.g., test command, line reference, manual verification summary).
* **Scope Invariants**:
  * Proposal scope cannot be broadened compared to the source finding evidence unless a justification of at least 15 characters is provided in the `reason` field.
  * Disjoint scope extensions (unrelated directory paths) must be treated as broadened scopes.
* **Redaction Guard**: All file modifications and parsing operations must run through `RedactionGuard` to prevent accidental leak of sensitive content (e.g., passwords or secrets).
* **Constraint adoption is evidence, not existence**: `adopt_existing` is valid only when the configured target and every registration file already exist. Activation still requires validation against the approved semantics and historical bad/good behavior; do not generate duplicate enforcement merely to complete a promotion path.

## 3. Transitions
* **Atomic Rollbacks**: Any file system modification during status transitions (approve, reject, acknowledge, retire, apply, activate) must use file backup and rollback logic so repository validation never leaves Learning state partially transitioned.
* **Judgment is not automation**: proposal approval, rejection, acknowledgement, and Constraint activation remain explicit authority-bearing transitions. A candidate must not become approved or active merely because generation/export/validation succeeded.
* **`NO_DURABLE_LEARNING` is a valid conclusion**: acknowledge it when the analysis is accepted; do not misuse rejection to make a correct conservative result look like maintainer disagreement.

## 4. Refactoring Boundary
* **Refactor invariants at their owner**: reduce duplicated transition mechanics only where behavior is demonstrably the same. Keep Finding/Proposal policy decisions explicit and separate from persistence mechanics.
* **Do not manufacture architecture to remove duplication**: prefer an owner-local extraction before introducing a shared abstraction. A generic transaction framework, new lifecycle layer, or cross-package orchestration helper requires independent evidence beyond naming/style preference.
* **Memory follows executable truth**: when this file and tested owner behavior disagree, correct this file toward the behavior. Do not add a second enforcement point merely to make prose true.
