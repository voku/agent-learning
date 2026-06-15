# Coding Agent | Learning-Loops

Reviewable finding, proposal, redaction, and decision-history tooling for coding-agent learning loops.

This library provides core domain logic and validation classes to support structured post-session learning for coding agents. It separates raw experiences (Findings) from potential guideline changes (Proposals), keeping the agent's knowledge extraction workflow structured, secure, and fully auditable.

---

## Key Concepts

### Findings
A **Finding** represents a single raw experience or observation captured from a task session. It stores:
* An observation and a hypothetical rule or pattern.
* A confidence level.
* Explicit validation metadata (`unverified`, `validated`, `invalidated`).
* A validated conclusion detailing why the pattern was verified or rejected.

### Proposals
A **Proposal** defines a potential durable mutation to the repository's guidelines or instructions (e.g., in `MEMORY.md` or dedicated agent skills). 
* Can represent actions like `ADD`, `DELETE`, `REPLACE`, `REJECT`, or `NO_DURABLE_LEARNING`.
* References one or more validated source findings that back it up.
* Contains metadata about target type, scope, proposed boundary, validation checklist, status, and approval.

### Constraint Specifications
A **ConstraintSpecification** is a typed, reviewable bridge from confirmed learning to executable validation. Constraint proposals describe the engine, rule identifier, scope, objective violation, allowed boundaries, false-positive risk, validation commands, local example rules, target rule path, and registration files. The package validates whether the learning is stable and precise enough for a later PHPStan, PHP-CS-Fixer, test, or CI generation step.

### Evidence
Findings must be backed by concrete, verifiable evidence. Supported types include:
* `file_reference`: References to specific files and line numbers.
* `commit`: Reference to a specific git commit.
* `test_result` / `phpstan_result`: Command execution command and summary.
* `review_comment`: Pull/merge request comments or reviews.
* `issue_reference`: Bounded issue or ticket tracker reference.
* Others (e.g., `schema_reference`, `runtime_observation`, `manual_verification`).

### Decision History
A persistent record of approved or rejected proposals stored in JSON Lines (`.jsonl`) format. 
* `decisions.jsonl` logs approved and applied mutations.
* `rejected-proposals.jsonl` logs rejected candidate proposals with detailed reasons.

---

## Core Classes & APIs

The package codebase is organized under the `voku\AgentLearning` namespace in the following structure:

### Value Objects & Enums
* [Finding](src/Finding.php): Read-only entity representing a captured session finding.
* [FindingStatus](src/FindingStatus.php): Enum defining finding lifecycles (`candidate`, `validated`, `invalidated`, `rejected`, `superseded`, `consolidated`, `archived`).
* [Proposal](src/Proposal.php): Read-only entity representing a proposed modification to guidelines.
* [ProposalStatus](src/ProposalStatus.php): Enum defining proposal states (`candidate`, `approved`, `rejected`, `applied`).
* [Action](src/Action.php): Enum representing actions (`NO_DURABLE_LEARNING`, `ADD`, `DELETE`, `REPLACE`, `REJECT`).
* [ConstraintSpecification](src/ConstraintSpecification.php): Read-only model for hard-constraint promotion candidates.
* [ConstraintEngine](src/ConstraintEngine.php): Enum defining supported hard-constraint engines (`phpstan`, `php_cs_fixer`, `test`, `ci`).
* [Detectability](src/Detectability.php): Enum describing whether the violation is statically, syntax-locally, runtime, or cross-file detectable.
* [FalsePositiveRisk](src/FalsePositiveRisk.php): Enum declaring expected false-positive risk (`low`, `medium`, `high`, `unknown`).

### Parsers & Repositories
* [FindingParser](src/FindingParser.php): Parses a finding JSON record or file.
* [ProposalParser](src/ProposalParser.php): Parses a proposal JSON record or file.
* [FindingRepository](src/FindingRepository.php): Loads validated findings from root directories.
* [ProposalRepository](src/ProposalRepository.php): Loads proposals under different lifecycle folders.

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
* [ConsolidationPromptBuilder](src/ConsolidationPromptBuilder.php): Assembles validated findings and rejected proposals history into a structured LLM consolidation prompt.
* [ConstraintGenerationPackageExporter](src/ConstraintGenerationPackageExporter.php): Exports `specification.json`, source findings/proposals, examples, validation plan, and generation prompt for coding-agent rule generation.
* [ConstraintLoopRunner](src/ConstraintLoopRunner.php): Drives the approved generated-rule close-out path by exporting, applying, and activating a hard constraint with one explicit command.
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
   - Proposal `status` describes the human lifecycle decision (`candidate`, `approved`, `rejected`, `applied`).
   - Durable actions (`ADD`, `DELETE`, `REPLACE`) may be `candidate`, `approved`, `rejected`, or `applied`.
   - `REJECT` and `NO_DURABLE_LEARNING` may only be `candidate` or `rejected`.
   - `APPROVED` or `APPLIED` proposal requires `approved_by` and `approved_at` timestamp.
   - `REJECTED` proposal or a `REJECT` action requires a non-empty `reason`.
7. **Lifecycle Directory Check**: Proposal files under `proposals/<status>/` must embed the same `status` value.
8. **Scope Broader Check**: If proposal `scope` includes entries not present in the referenced findings, a `scope_justification` must be provided.
9. **Constraint Promotion Gates**: Constraint proposals require confirmed source findings, several independent findings or a critical-incident justification, explicit scope, explicit allowed boundaries, objective detectability, validation commands, declared false-positive risk, local example rule references where available, and engine-compatible target paths/commands.

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

- [`agent-learning-consumer`](skills/agent-learning-consumer/SKILL.md): for end users setting up a learning root, capturing findings, validating proposals, and preparing consolidation input.
- [`agent-hard-constraint-author`](skills/agent-hard-constraint-author/SKILL.md): for end users promoting validated findings into executable PHPStan, PHP-CS-Fixer, test, or CI constraints.
- [`agent-learning-maintainer`](skills/agent-learning-maintainer/SKILL.md): for maintainers changing `voku/agent-learning` source, tests, docs, or local vendor syncs.

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

The Composer binary exposes the package workflow without requiring consuming-project classes:

```bash
vendor/bin/agent-learning validate --root infra/doc/agent-learning
vendor/bin/agent-learning prepare --root infra/doc/agent-learning --task PROJECT-1234 --task GH-158
vendor/bin/agent-learning prepare --root infra/doc/agent-learning --finding finding.2026-06-08.001 --scope src/Auth --since 2026-06-01
vendor/bin/agent-learning proposal-validate --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-export --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-activate --root infra/doc/agent-learning --proposal proposal.2026-06-08.001.json
vendor/bin/agent-learning constraint-loop --root infra/doc/agent-learning proposal.2026-06-08.001 --by lars --commit working-tree --validation infra/doc/agent-learning/validation-results/proposal.2026-06-08.001.json --approve-candidate
```

`prepare` prints the selected finding IDs before writing the prompt. Empty selections fail unless `--allow-empty` is passed. If `templates/consolidation-prompt.md` exists under the learning root, its content is appended to the generated consolidation input as a project-specific prompt addendum.

`--root` may point either to the learning root itself or to a project root containing one of these directories:

* `infra/doc/agent-learning`
* `.agent-learning`
* `docs/agent-learning`
* `agent-learning`

Zero-byte `.json` files are treated as extraction placeholders and skipped. Non-empty finding, proposal, and history records are validated strictly.
