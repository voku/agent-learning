# Agent Learning (`voku/agent-learning`)

Reviewable finding, precedent, proposal, redaction, and decision-history tooling for coding-agent learning loops.

[![Build Status](https://github.com/voku/agent-learning/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-learning/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-learning/v/stable)](https://packagist.org/packages/voku/agent-learning)
[![Total Downloads](https://poser.pugx.org/voku/agent-learning/downloads)](https://packagist.org/packages/voku/agent-learning)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-learning/d/monthly)](https://packagist.org/packages/voku/agent-learning)
[![License](https://poser.pugx.org/voku/agent-learning/license)](https://packagist.org/packages/voku/agent-learning)
[![PHP Version Require](https://poser.pugx.org/voku/agent-learning/require/php)](https://packagist.org/packages/voku/agent-learning)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-learning?style=flat-square)](https://github.com/voku/agent-learning/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-learning?style=flat-square)](https://github.com/voku/agent-learning/network/members)

This library provides core domain logic and validation classes to support structured post-session learning for coding agents. It separates raw experiences (Findings), reusable solved-case precedent (LearningNotes), and potential guideline changes (Proposals), keeping the agent's knowledge extraction workflow structured, secure, and fully auditable.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | `^8.3` |
| ext-json | `*` |

## Installation

```bash
composer require voku/agent-learning
```

The package exposes the standalone CLI:

```bash
vendor/bin/agent-learning
```

## Quick Start

```bash
# Validate findings, proposals, and decision history
vendor/bin/agent-learning validate --root=infra/doc/agent-learning

# Check validated findings backlog waiting for consolidation
vendor/bin/agent-learning backlog --root=infra/doc/agent-learning

# Run the deterministic maintenance audit (Dream report)
vendor/bin/agent-learning dream --root=infra/doc/agent-learning --report=.agent-loop/dream/latest.json --dry-run

# Rebuild compact history projections (active-guidance and chronicle)
vendor/bin/agent-learning history-rebuild --root=infra/doc/agent-learning
vendor/bin/agent-learning history-status --root=infra/doc/agent-learning
```

## Shipped Assets & PackageResources

The package ships 5 first-party workflow skills under `resources/skills/`:
- `agent-hard-constraint-author`
- `agent-learning-consumer`
- `agent-learning-ctx-evidence`
- `agent-learning-maintainer`
- `agent-learning-note`

Consumers and tool integrations can resolve them programmatically without hard-coding filesystem paths:

```php
use voku\AgentLearning\PackageResources;

$skillsRoot = PackageResources::skillsRoot();
// returns /path/to/vendor/voku/agent-learning/resources/skills
```

---

## Key Concepts

### Findings
A **Finding** represents a single raw experience or observation captured from a task session. It stores:
* An observation and a hypothetical rule or pattern.
* A confidence level.
* Explicit validation metadata (`unverified`, `validated`, `invalidated`).
* A validated conclusion detailing why the pattern was verified or rejected.
* Optional learning triage metadata:
  * `classification`: `CREATE_SKILL`, `UPDATE_SKILL`, `ADD_LEARNING_NOTE`, or `IGNORE`.
  * `pattern_key`: stable dot-separated clustering key such as `tests.add_before_change`.
  * `validation_case`: concrete `given` / `when` / `then` behavior check.

`ADD_LEARNING_NOTE` is the default durable capture. `CREATE_SKILL` should be rare; prefer `UPDATE_SKILL` when an existing skill already owns the behavior. `IGNORE` is valid for praise, vague reflection, one-off details, and already-covered guidance.

### LearningNotes
A **LearningNote** is a durable, evidence-backed solved-case precedent derived from validated Findings classified `ADD_LEARNING_NOTE`. It preserves useful context such as what happened, what failed, why the resolution worked, and when the lesson does or does not apply without promoting one case into active project guidance.

LearningNotes have stable `pattern_key` ownership, exact Finding/Proposal lineage, versioned schema, redaction, repository-evidence currentness, and explicit `active` / `retired` lifecycle state. The typed `LearningNoteService` owns prepare, publish, read/status, and retire operations; consumers use `LearningNoteProjection` instead of reconstructing `notes/**` paths.

A LearningNote is **precedent, not authority**. Its existence does not approve mutation, widen task scope, satisfy validation, apply a Proposal, or create/update a Skill or Constraint. `voku/agent-recall-compiler` may deterministically select current LearningNotes as bounded low-authority precedent, while reviewed active guidance remains stronger.

See [docs/learning-notes.md](docs/learning-notes.md). Agentic authoring is provided by the package-owned [`agent-learning-note`](resources/skills/agent-learning-note/SKILL.md) skill over the deterministic owner boundary.

### Proposals
A **Proposal** defines a potential durable mutation to the repository's guidelines or instructions (e.g., in `MEMORY.md` or dedicated agent skills). 
* Can represent actions like `ADD`, `DELETE`, `REPLACE`, `REJECT`, or `NO_DURABLE_LEARNING`.
* References one or more validated source findings that back it up.
* Contains metadata about target type, scope, proposed boundary, validation checklist, status, and approval.
* May carry the same `learning_decision`, `pattern_key`, and `validation_case` fields used by consolidation. `CREATE_SKILL` proposals additionally require an `overlap_check` proving existing skills were inspected and no overlapping skill owns more than 50% of the behavior.

### Constraint Specifications
A **ConstraintSpecification** is a typed, reviewable bridge from confirmed learning to executable validation. Constraint proposals describe the engine, rule identifier, scope, objective violation, allowed boundaries, false-positive risk, validation commands, local example rules, target rule path, and registration files. The package validates whether the learning is stable and precise enough for a later PHPStan, PHP-CS-Fixer, test, or CI generation step.

### Evidence
Findings must be backed by concrete, verifiable evidence. Supported types include:
* `file_reference`: References to specific files and line numbers.
* `commit`: Reference to a specific git commit.
* `test_result` / `phpstan_result`: Command execution command and summary.
* `review_comment`: Pull/merge request comments or reviews.
* `issue_reference`: Bounded issue or ticket tracker reference.
* `agent_history_reference`: Bounded reference to inspected local agent-history evidence discovered with ctx.
* Others (e.g., `schema_reference`, `runtime_observation`, `manual_verification`).

### Using ctx as historical evidence
`agent-learning` does not index agent history itself. If [ctx](https://github.com/ctxrs/ctx) is installed, use it before writing findings to discover relevant prior sessions, failed attempts, rejected approaches, and validation history.

ctx hits are evidence references, not durable memory. Record only bounded summaries and ctx IDs in findings. Durable learning still flows through validated findings, LearningNotes where appropriate, proposals, and reviewed decisions.

ctx helps find what happened before; agent-learning decides what deserves to survive.

See [docs/ctx-integration.md](docs/ctx-integration.md) for the evidence schema and the local-history boundary. The related package skill is `agent-learning-ctx-evidence`; the ctx-side search skill is `ctx-agent-history-search`.

### Decision History
A persistent record of approved or rejected proposals stored in JSON Lines (`.jsonl`) format. 
* `decisions.jsonl` logs approved and applied mutations.
* `rejected-proposals.jsonl` logs rejected candidate proposals with detailed reasons.

### Dream maintenance cycle
`agent-learning dream` is a deterministic, read-mostly maintenance pass over immutable recall-selection and guidance-outcome histories. It validates the learning root, previews compact history projections, audits evidence coverage, evaluates promotion/staleness, and produces a byte-stable v1 review report (except explicitly volatile proposal creation timestamps).

The immutable `DreamRunResult` contains evaluated guidance, bounded evidence/outcome warnings, generated and suppressed decisions, metrics, and remaining uncertainty. Its stable keys include evidence, sources, scope, target tier, wording, and explicit lineage. Outcome completeness joins the selected `(compilation_id, guidance_id)` identities and is `null` when no guidance was selected; it never treats an empty history as 100% complete. The stale-candidate rate uses the reviewable decision population, and duplicate decisions measure producer overlap before deduplication.

The maintenance policies require bounded deterministic identity or explicit lineage:

* `REPLACEMENT_CANDIDATE` requires an approved/applied `REPLACE` successor for the same target, supported by exact old wording, explicit `supersedes_proposal_id`, harmful outcome plus `corrects_proposal_id`, newer validated `supersedes_findings`, or a strict scope narrowing.
* `CONFLICT` reports explicit `conflicts_with`, a later invalidated/superseded finding with `contradicts_proposal_id`, or exact normalized duplicate active wording with overlapping scope and source evidence across tiers. Different prose alone is never guessed to be contradictory.

The command never approves, applies, retires, deletes, archives, or rewrites guidance. `--write-candidates` writes review records only; conflict records use `NO_DURABLE_LEARNING`, so a reviewer can acknowledge or reject the report without an executable mutation. Existing candidate, rejected, and acknowledged records suppress only the same stable decision key.

Review a stale/replacement/conflict result by preserving the decision record first, then explicitly acknowledge/reject it or create/approve/apply a separately reviewed `REPLACE`/`DELETE` proposal. Retire previously applied guidance only with `proposal-retire` and an explicit reason; the proposal remains auditable in `proposals/retired/` and immutable history, but is excluded from active recall. Archive or supersede a finding through its lifecycle transition with a recorded reason; a replacement must retain validated source-finding lineage.

An applied `memory`/`skill` proposal proves it landed by pinning the whole target file in `applied_validation.target_content_hash`. A shared home such as `MEMORY.md` carries many rows, so an edit to any other row - a repository layout move repairing an evidence path, for example - makes every proof on that file report drift it did not cause. Repair them with `proposal-reanchor`, which takes the target, not one proposal, because the drift belongs to the file:

```bash
vendor/bin/agent-learning proposal-reanchor MEMORY.md \
  --by maintainer \
  --reason 'Repaired an unrelated MEMORY.md evidence path after a repository layout move.'
```

Every applied `memory`/`skill` proof on that target is re-pinned in one transaction, and only while each proposal's own guidance wording is still present - the same assertion the validator makes - so a target that lost a rule is refused and rolled back rather than re-pinned. Proofs are matched by the file they resolve to rather than by how they spell it, so `MEMORY.md` and `./MEMORY.md` are one target and no subset is left stale. Approval, application and validation evidence stay untouched; the repair adds the new hash, an explicit actor and an explicit reason, and one record per proposal in `history/reanchored-proposals.jsonl`.

Every transition - `approve`, `reject`, `retire`, `acknowledge`, `apply` and `reanchor` - allocates its history id and writes under one root-scoped exclusive lock held at `history/.transition.lock`, because each allocates that id by scanning the log it is about to append to. Concurrent runs would otherwise agree on the same sequence number and write duplicate ids into immutable history.

Re-anchoring is a proof repair, not a decision. It never makes a target that lost the rule look applied, and it is not a substitute for `proposal-retire` when the guidance itself should leave the active pool.

```bash
vendor/bin/agent-learning dream \
  --root infra/doc/agent-learning \
  --report .agent-loop/dream/latest.json \
  --dry-run
```

Use `--format=json` for CI. The report has no generated timestamp, so equivalent immutable inputs yield byte-stable output. It reports outcome completeness, active guidance by tier, candidate queue age, suppressed decisions, outcome signals, and median finding-to-human-decision time. `--project-root` is optional because the learning-root configuration and legacy layout resolver determine it by default.

### Compact history projections

Raw findings, proposals, and event histories remain the audit source. Create compact, reproducible working views only with an explicit write command:

```bash
vendor/bin/agent-learning history-rebuild --root infra/doc/agent-learning
vendor/bin/agent-learning history-status --root infra/doc/agent-learning
```

`history-rebuild` writes `history/active-guidance.snapshot.json`, `history/chronicle.jsonl`, and `history/projection-manifest.json`. The snapshot contains only approved/applied guidance; the chronicle records bounded lifecycle summaries for retired, rejected, acknowledged, superseded, archived, invalidated, and consolidated material. Both retain source IDs and content digests. The manifest hashes every raw input, so `history-status` fails clearly after source or projection corruption; rebuild to recover from any complete immutable source history. Dream reports expose source files/bytes, projection size, compression ratio, and an opt-in rebuild measurement via `--include-runtime`.

---

## Core Classes & APIs

The package codebase is organized under the `voku\AgentLearning` namespace in the following structure:

### Value Objects & Enums
* [Finding](src/Finding.php): Read-only entity representing a captured session finding.
* [FindingStatus](src/FindingStatus.php): Enum defining finding lifecycles (`candidate`, `validated`, `invalidated`, `rejected`, `superseded`, `consolidated`, `archived`).
* [LearningNote](src/LearningNote.php): Read-only durable solved-case precedent with stable pattern identity and exact source lineage.
* [LearningNoteStatus](src/LearningNoteStatus.php): Enum defining LearningNote lifecycle (`active`, `retired`).
* [LearningNoteProjection](src/LearningNoteProjection.php): Compact read-only sibling/Recall projection that hides private storage layout.
* [Proposal](src/Proposal.php): Read-only entity representing a proposed modification to guidelines.
* [ProposalStatus](src/ProposalStatus.php): Enum defining proposal states (`candidate`, `approved`, `rejected`, `applied`, `retired`).
* [Action](src/Action.php): Enum representing actions (`NO_DURABLE_LEARNING`, `ADD`, `DELETE`, `REPLACE`, `REJECT`).
* [ConstraintSpecification](src/ConstraintSpecification.php): Read-only model for hard-constraint promotion candidates.
* [GuidanceUsageSummary](src/GuidanceUsageSummary.php): Read-only projection of recall eligibility, selection, application, explicit outcomes, task spread, timestamps, and evidence event IDs.
* [ConstraintEngine](src/ConstraintEngine.php): Enum defining supported hard-constraint engines (`phpstan`, `php_cs_fixer`, `test`, `ci`).
* [Detectability](src/Detectability.php): Enum describing whether the violation is statically, syntax-locally, runtime, or cross-file detectable.
* [FalsePositiveRisk](src/FalsePositiveRisk.php): Enum declaring expected false-positive risk (`low`, `medium`, `high`, `unknown`).

### Parsers & Repositories
* [FindingParser](src/FindingParser.php): Parses a finding JSON record or file.
* [ProposalParser](src/ProposalParser.php): Parses a proposal JSON record or file.
* [FindingRepository](src/FindingRepository.php): Loads validated findings from root directories.
* [LearningNoteRepository](src/LearningNoteRepository.php): Owns private LearningNote persistence and active/retired state; consumers should prefer the service/projection boundary.
* [ProposalRepository](src/ProposalRepository.php): Loads proposals under different lifecycle folders.
* [RecallSelectionEventRepository](src/RecallSelectionEventRepository.php): Loads immutable recall-selection JSONL events produced by `voku/agent-recall-compiler`.
* [GuidanceOutcomeEventRepository](src/GuidanceOutcomeEventRepository.php): Loads immutable per-guidance outcome JSONL events.

### Validators
* [FindingValidator](src/FindingValidator.php): Enforces structure, format, and lifecycle consistency for findings.
* [ProposalValidator](src/ProposalValidator.php): Validates proposal mutations, targets, actions, and references.
* [EvidenceValidator](src/EvidenceValidator.php): Inspects list of evidence objects to ensure required fields for each type exist.
* [JsonlValidator](src/JsonlValidator.php): Parses and validates JSON Lines log formats.
* [RedactionGuard](src/RedactionGuard.php): Scans all content for credentials, secrets, or sensitive configuration keys to prevent accidental leaks.
* [DecisionHistoryValidator](src/DecisionHistoryValidator.php): Validates log consistency of the decision history.
* [ConstraintPromotionValidator](src/ConstraintPromotionValidator.php): Validates that constraint proposals come from confirmed findings and contain explicit promotion-gate evidence.
* [ConstraintManifestActivator](src/ConstraintManifestActivator.php): Writes the active manifest consumed by recall tooling after an approved or applied constraint rule exists in the project.

### Utilities & Infrastructure
* [LearningNoteService](src/LearningNoteService.php): Typed prepare/publish/read/status/retire owner boundary with lineage, redaction, duplicate-pattern and currentness validation.
* [ConsolidationPromptBuilder](src/ConsolidationPromptBuilder.php): Assembles validated findings and rejected proposals history into a structured LLM consolidation prompt.
* [ConstraintGenerationPackageExporter](src/ConstraintGenerationPackageExporter.php): Exports `specification.json`, source findings/proposals, examples, validation plan, and generation prompt for coding-agent rule generation.
* [ConstraintLoopRunner](src/ConstraintLoopRunner.php): Drives the approved generated-rule close-out path by exporting, applying, and activating a hard constraint with one explicit command.
* [GuidanceUsageProjector](src/GuidanceUsageProjector.php): Rebuilds deterministic usage summaries from immutable event histories without mutable counters.
* [GuidanceEvolutionEvaluator](src/GuidanceEvolutionEvaluator.php): Applies conservative tier-specific promotion and review policies.
* [RecordAccess](src/RecordAccess.php): Utility helper to extract strongly typed fields from raw array data.
* [Json](src/Json.php): Helper for decoding files safely.
* [ValidationException](src/ValidationException.php): Custom runtime exception with file name, line numbers, and record IDs context.

---

## Validation Specifications

### Finding Validation
1. **Finding ID**: Must match `finding.YYYY-MM-DD.NNN`.
2. **Created At**: Must be a valid ISO 8601/Atom timestamp string.
3. **Task ID**: Must match the configured task ID pattern (passed via `$taskIdPattern` to the [FindingValidator](src/FindingValidator.php) constructor; defaults to `'/^(?:[A-Z][A-Z0-9_-]*-\d+|TODO@[\w:\/.-]+)$/'`).
4. **Observation/Hypothesis Separation**: Both must be non-empty strings and cannot be identical.
5. **Confidence**: Must be one of `low`, `medium`, or `high`.
6. **Validation Status**: Must be one of `unverified`, `validated`, or `invalidated`.
7. **Lifecycle Enforcements**:
   - `candidate` requires `validation_status=unverified`.
   - `validated` and `consolidated` require `validation_status=validated`.
   - `invalidated` requires `validation_status=invalidated`.
   - `superseded` and `rejected` require `validation_status=validated` or `validation_status=invalidated`.
   - `archived` preserves the prior validation state and may use any supported `validation_status`.
   - A `validation_status=validated` finding requires a non-empty `validated_conclusion`.
   - The `validated_conclusion` must not be identical to the hypothesis.

### Proposal Validation
1. **Proposal ID**: Must match `proposal.YYYY-MM-DD.NNN`.
2. **Created At**: Must be a valid ISO 8601/Atom timestamp string.
3. **Mutations Constraint**: Fields `mutations`, `changes`, or `targets` must contain at most 1 item to prevent overly broad proposals.
4. **Source Findings**: Must have at least 1 referenced source finding.
5. **Action-Specific Constraints**:
   - If not a `NO_DURABLE_LEARNING` action: requires `target_type`, `target`, `scope` (non-empty list), `boundary` (non-empty), and `validation` checklist.
   - `ADD` action requires `new` wording.
   - `DELETE` action requires `old` wording.
   - `REPLACE` action requires both `old` and `new` wording.
6. **Status Constraints**:
   - Proposal `action` describes the requested durable change (`ADD`, `DELETE`, `REPLACE`, `REJECT`, `NO_DURABLE_LEARNING`).
   - Proposal `status` describes the human lifecycle decision (`candidate`, `approved`, `rejected`, `applied`, `retired`).
   - Durable actions (`ADD`, `DELETE`, `REPLACE`) may be `candidate`, `approved`, `rejected`, `applied`, or `retired`.
   - `REJECT` and `NO_DURABLE_LEARNING` may only be `candidate` or `rejected`.
   - `APPROVED`, `APPLIED`, or `RETIRED` proposal requires `approved_by` and `approved_at` timestamp.
   - `REJECTED` proposal or a `REJECT` action requires a non-empty `reason`.
   - `RETIRED` proposal requires a non-empty `reason`. Retirement only applies to a previously `APPLIED` proposal whose durable change is now fully captured in its target skill/doc/memory home; `voku/agent-recall-compiler`'s `loadActiveGuidance()` only scans `proposals/approved/` and `proposals/applied/`, so a retired proposal stops being read into every future active recall guidance pool without needing any change in that package.
7. **Lifecycle Directory Check**: Proposal files under `proposals/<status>/` must embed the same `status` value.
8. **Scope Broader Check**: If proposal `scope` includes entries not present in the referenced findings, a `scope_justification` must be provided.
9. **Constraint Promotion Gates**: Constraint proposals require confirmed source findings, several independent findings or a critical-incident justification, explicit scope, explicit allowed boundaries, objective detectability, validation commands, declared false-positive risk, local example rule references where available, and engine-compatible target paths/commands.
10. **Learning Triage Gates**: When present, `learning_decision` must align with the proposal:
   - `IGNORE` requires `NO_DURABLE_LEARNING`.
   - `ADD_LEARNING_NOTE` preserves the raw learning without pretending it is ready for skill promotion.
   - `UPDATE_SKILL` requires `target_type=skill`.
   - `CREATE_SKILL` requires `ADD`, `target_type=skill`, `pattern_key`, `validation_case`, and an `overlap_check` with inspected skills and `max_overlap_percent <= 50`.

### Applied Constraint Metadata
When a constraint proposal is marked `applied`, its validation JSON must include `generated_files`, `registration_file`, `commit`, `tests`, `validation_result`, and `content_hashes`. This preserves lineage from finding to proposal, generated rule, registration, commit, validation, and later outcome.

### Active Constraint Manifests
After a generated constraint is approved and implemented, run `constraint-activate` to create `constraints/active/constraint.<rule_id>.json`. The command validates the proposal, checks the target rule file and registration files exist relative to the project root, and writes the exact engine, rule identifier, scope, validation commands, and source proposal that `voku/agent-recall-compiler` selects later.

Learning roots may define `config.json` to avoid hard-coding one repository layout:

```json
{
  "schema_version": "1.0",
  "project_root": "../../..",
  "constraint_generation_dir": "constraint-generation",
  "active_constraints_dir": "constraints/active"
}
```

Relative paths are resolved from the learning root. CLI options `--project-root`, `--constraint-generation-dir`, and `--active-constraints-dir` override `config.json` for one run. Without configuration, the package keeps the legacy project-root inference for `infra/doc/agent-learning`, `.agent-learning`, `docs/agent-learning`, and `agent-learning`.

### Redaction Constraints
All keys and values are checked using [RedactionGuard](src/RedactionGuard.php) against secret assignment patterns. Any matches of standard credential assignments (e.g. `password`, `token`, `api_key`, `ms-Mcs-AdmPwd` patterns) throw a validation exception.

---

## JSON Structure Formats

### Example Finding
```json
{
  "id": "finding.2026-06-08.001",
  "task_id": "PROJECT-1234",
  "session": "session_abc123",
  "created_at": "2026-06-08T10:00:00+00:00",
  "created_by": "agent_alpha",
  "scope": [
    "lib/framework/forms"
  ],
  "observation": "FormElement validation fails when checking numeric bounds if string decimals are passed.",
  "evidence": [
    {
      "type": "file_reference",
      "path": "lib/framework/forms/FormElement.php",
      "line": 42
    },
    {
      "type": "test_result",
      "command": "make test_unit_file FILE=tests/FormElement_UnitCest.php",
      "summary": "Failed asserting that false is true on DecimalBound test"
    }
  ],
  "hypothesis": "String decimal inputs should be normalized to float/int before calling range checks in FormElement.",
  "validated_conclusion": "Normalizing value to float in range validation resolves bounds failures without side-effects.",
  "confidence": "high",
  "validation_status": "validated",
  "status": "validated",
  "sensitivity": "public"
}
```

With learning triage:

```json
{
  "classification": "ADD_LEARNING_NOTE",
  "pattern_key": "tests.add_before_change",
  "validation_case": {
    "given": "a task modifies behavior covered by existing tests",
    "when": "the agent prepares the implementation plan",
    "then": "it identifies relevant tests before editing production code"
  }
}
```

### Example Proposal
```json
{
  "id": "proposal.2026-06-08.001",
  "created_at": "2026-06-08T11:30:00+00:00",
  "action": "REPLACE",
  "target_type": "skill",
  "target": "form-validation",
  "scope": [
    "lib/framework/forms"
  ],
  "source_findings": [
    "finding.2026-06-08.001"
  ],
  "old": "Validate range bounds directly using the raw inputs.",
  "new": "Ensure numeric inputs are cast/normalized to numeric values before validating range bounds.",
  "reason": "Prevents float/string type comparisons from failing bounds checks.",
  "boundary": "Only run numeric bounds normalization on Decimal and Float FormElement subclasses.",
  "validation": [
    "Ensure unit tests verify decimal string normalization."
  ],
  "status": "candidate",
  "proposed_by": "agent_alpha",
  "approved_by": null,
  "approved_at": null
}
```

---

## Development & Testing

### Bundled Agent Skills

This package ships package-specific skills under `skills/`:

- [`agent-learning-consumer`](resources/skills/agent-learning-consumer/SKILL.md): for end users setting up a learning root, capturing findings, validating proposals, and preparing consolidation input.
- [`agent-learning-note`](resources/skills/agent-learning-note/SKILL.md): for authoring or updating one evidence-grounded LearningNote from explicit validated Finding IDs through `LearningNoteService`/CLI owner boundaries; it never promotes the note into active guidance.
- [`agent-learning-ctx-evidence`](resources/skills/agent-learning-ctx-evidence/SKILL.md): for using ctx-backed local agent-history search as bounded evidence references without importing raw transcripts.
- [`agent-hard-constraint-author`](resources/skills/agent-hard-constraint-author/SKILL.md): for end users promoting validated findings into executable PHPStan, PHP-CS-Fixer, test, or CI constraints.
- [`agent-learning-maintainer`](resources/skills/agent-learning-maintainer/SKILL.md): for maintainers changing `voku/agent-learning` source, tests, docs, or local vendor syncs.

### Running Tests
To run unit and integration tests for this package:
```bash
composer test
```
Or use the local `Makefile`:
```bash
make test
```

### Static Analysis
To run PHPStan checks on the package:
```bash
composer phpstan
```
Or use the local `Makefile`:
```bash
make phpstan
```

### CLI

The Composer binaries expose the package workflows without requiring consuming-project classes:

```bash
vendor/bin/agent-learning validate --root infra/doc/agent-learning
vendor/bin/agent-learning prepare --root infra/doc/agent-learning --task PROJECT-1234 --task GH-158
vendor/bin/agent-learning prepare --root infra/doc/agent-learning --finding finding.2026-06-08.001 --scope src/Auth --since 2026-06-01
vendor/bin/agent-learning proposal-validate --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-export --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-activate --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-loop --root infra/doc/agent-learning proposal.2026-06-08.001 --by lars --commit working-tree --validation infra/doc/agent-learning/validation-results/proposal.2026-06-08.001.json --approve-candidate
vendor/bin/agent-learning guidance-evaluate --root infra/doc/agent-learning --selection-history history/recall-selections.jsonl --outcome-history history/outcomes.jsonl

vendor/bin/agent-learning-note prepare --root infra/doc/agent-learning --finding finding.2026-06-08.001 --project-root .
vendor/bin/agent-learning-note publish --root infra/doc/agent-learning --input learning-note-candidate.json --project-root .
vendor/bin/agent-learning-note status --root infra/doc/agent-learning --project-root .
vendor/bin/agent-learning-note retire --root infra/doc/agent-learning learning-note.tests.add-before-change --reason "Superseded by reviewed current guidance."
```

`agent-learning-note prepare` emits only owner-validated authoring inputs. `publish` revalidates source Finding lineage, redaction, duplicate pattern ownership, schema, and repository evidence before atomically writing a durable note. `status` exposes typed currentness without making callers parse private storage.

`prepare` prints the selected finding IDs before writing the prompt. Empty selections fail unless `--allow-empty` is passed. If `templates/consolidation-prompt.md` exists under the learning root, its content is appended to the generated consolidation input as a project-specific prompt addendum.

`--root` may point either to the learning root itself or to a project root containing one of these directories:

* `infra/doc/agent-learning`
* `.agent-learning`
* `docs/agent-learning`
* `agent-learning`

Zero-byte `.json` files are treated as extraction placeholders and skipped. Non-empty finding, proposal, and history records are validated strictly.

### Guidance Usage Evaluation

`guidance-evaluate` consumes immutable event histories produced by `voku/agent-recall-compiler`, rebuilds deterministic usage summaries, and prints conservative decisions for finding-to-memory, memory-to-skill, skill-to-constraint, and stale/replacement review paths.

It does not edit `MEMORY.md`, skills, active constraints, PHPStan configuration, or CI. With `--write-candidates`, it may write only reviewable proposal files under `proposals/candidate/`; no proposal is approved, applied, or activated automatically.

Schema details, policy gates, and duplicate behavior are documented in [`docs/guidance-evaluation.md`](docs/guidance-evaluation.md). A complete findings-to-memory-to-skill-promotion fixture is available under [`examples/guidance-evaluation`](examples/guidance-evaluation).
