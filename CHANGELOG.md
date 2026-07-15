# Changelog

All notable changes to `voku/agent-learning` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [0.8.2] - 2026-07-15

### Added

- New `proposal-acknowledge` command and `ProposalTransitionManager::acknowledge()`,
  plus a new `ProposalStatus::ACKNOWLEDGED` status and `proposals/acknowledged/`
  directory. Closing a candidate `NO_DURABLE_LEARNING` proposal previously required
  `proposal-reject`, which is semantically wrong: `NO_DURABLE_LEARNING` already
  represents a correct, considered conclusion ("nothing durable to change here"),
  not a decision a maintainer disagreed with. `approve()` was already (correctly)
  refusing `NO_DURABLE_LEARNING`, leaving `reject()` as the only available terminal
  transition and producing an audit trail that reads as disapproval for what is
  actually acceptance. `acknowledge()` only accepts proposals whose `action` is
  `NO_DURABLE_LEARNING`; every other action must still go through `approve()` or
  `reject()`.

## [0.8.1] - 2026-07-15

### Fixed

- Preserve an approved memory proposal's non-empty `scope_justification` when
  `guidance-evaluate --write-candidates` creates a stale-review candidate. This
  keeps a valid broader scope reviewable instead of failing candidate validation
  after the justification was dropped during projection.

## [0.8.0] - 2026-07-06

### Added

- add docs about https://github.com/ctxrs/ctx and how to use it for findings existing learnings from your coding agents
- New `backlog` CLI command that lists validated findings not yet consolidated into a proposal and
  exits non-zero while any remain. This is a deterministic gate for the recurring "only the recent
  findings were processed" failure: a learning-loop pass can no longer be reported as done while a
  backlog of unconsolidated findings exists. `--allow-nonempty` makes the listing informational
  (exit 0) for reporting without gating. Backed by `BacklogCommandTest`.

## [0.7.0] - 2026-06-23

### Added

- Added a `retired` `ProposalStatus`, reachable only from `applied` via the new
  `ProposalTransitionManager::retire()` / `proposal-retire` CLI command. Once a proposal's durable
  change is fully captured in its target skill/doc/memory home, retiring it moves the file to
  `proposals/retired/` and appends an immutable record to the new `history/retired-proposals.jsonl`
  (validated by `DecisionHistoryValidator`). Consuming projects do not need any change in
  `voku/agent-recall-compiler`: its `RecallRepository::loadActiveGuidance()` already only scans
  `proposals/approved/` and `proposals/applied/`, so a retired proposal is simply never read into the
  active recall guidance pool again, instead of accumulating there forever.
- `ProposalValidator`'s source-finding check now also accepts findings with
  `FindingStatus::ARCHIVED` (previously only `VALIDATED`/`CONSOLIDATED`), and
  `ProposalTransitionManager::approve()`'s inline check was widened to match, so a finding can be
  archived after its proposal already cited it as evidence without breaking that proposal's
  validation on a later `validate`/`approve`/`retire` run.

## [0.6.1] - 2026-06-20

### Fixed

- `Cli::parseOptions()` used `foreach ($tokens as $index => $token)` with a manual `$index++` to skip
  a consumed option value. `foreach` ignores manual mutation of the loop variable, so the consumed
  value was re-read on the next iteration and pushed onto `$arguments` as a bogus leading positional
  argument. Any command that reads its primary ID from `$arguments[0]` (`proposal-approve`,
  `proposal-reject`, `proposal-mark-applied`, `finding-transition`, and `constraint-export`/
  `constraint-activate`/`constraint-loop` when given a bare ID) silently operated on the wrong record
  whenever at least one `--option value` pair preceded it. Switched to an explicit indexed `for` loop
  (matching the already-correct pattern in the sibling `voku/agent-recall-compiler` package) and added
  a regression test that exercises `proposal-approve` through `Cli::run()` with space-separated options.

## [0.6.0] - 2026-06-19

### Added

- Add validating recall history in learning root checks

### Refactored

- Consolidate learning root resolution

## [0.5.0] - 2026-06-18

### Added

- Add strict recall-selection and per-guidance outcome event models, parsers, and JSONL repositories.
- Add deterministic `GuidanceUsageProjector` summaries for eligibility, selection, application, explicit outcomes, distinct tasks, timestamps, and evidence event IDs.
- Add typed evolution decisions for promotion, staleness, replacement, conflict, and no-action review results.
- Add separate conservative policies for finding-to-memory, memory-to-skill, skill-to-constraint, memory staleness, skill staleness, and constraint review.
- Add `guidance-evaluate` CLI to validate event histories, print deterministic usage summaries and decisions, and optionally write reviewable candidate proposals.
- Add idempotent candidate proposal writing with preserved event provenance under `evolution_decision`.
- Add guidance-evaluation schema documentation and a complete fixture showing findings to memory to recalled outcomes to skill-promotion candidate.
- Add regression coverage for deterministic projection, malformed and duplicate events, selected/applied/helpful separation, promotion gates, harmful blockers, stale review behavior, constraint inactivity, candidate provenance, and idempotent writes.

### Changed

- Allow `OutcomeRepository` validation to read new `guidance-outcome.*` event rows alongside legacy aggregate outcome records.
- Update README and bundled skills to document immutable event projection, candidate-only writes, and the human approval gate.

## [0.4.0] - 2026-06-18

### Added

- Add typed learning triage with `LearningClassification` values for `CREATE_SKILL`, `UPDATE_SKILL`, `ADD_LEARNING_NOTE`, and `IGNORE`.
- Add `ValidationCase` support so findings, consolidation results, and proposals can carry concrete `given` / `when` / `then` behavior checks.
- Add optional `classification`, `pattern_key`, and `validation_case` fields to findings.
- Add optional `learning_decision`, `pattern_key`, `validation_case`, and `overlap_check` fields to consolidation results and proposals.
- Add validation coverage for learning-note defaults, CREATE_SKILL overlap gates, pattern-key format, and validation-case requirements.

### Changed

- Update consolidation prompts to default useful raw lessons to `ADD_LEARNING_NOTE`, prefer `UPDATE_SKILL` over duplicate skill creation, and reject praise, self-justification, or generic advice.
- Require `CREATE_SKILL` proposals to prove existing skill overlap was inspected and that maximum overlap is not above 50%.
- Preserve learning triage metadata when importing consolidation results into candidate proposals.

## [0.3.0] - 2026-06-15

### Added

- Add `constraint-activate` to write active constraint manifests from approved or applied constraint proposals.
- Add `constraint-loop` to export, apply, and activate generated constraints with one explicit tooling command.
- Add learning-root `config.json` path settings for `project_root`, `constraint_generation_dir`, and `active_constraints_dir`.

### Changed

- Resolve constraint example, generation-package, and active-manifest paths from CLI options, learning-root configuration, or legacy project-root inference.

## [0.2.0] - 2026-06-14

### Added

- Add typed constraint promotion models (`ConstraintSpecification`, `ConstraintEngine`, `Detectability`, `FalsePositiveRisk`) for hard-constraint candidates.
- Add promotion-gate validation for confirmed findings, scope, boundaries, detectability, validation commands, false-positive risk, and engine-compatible target paths.
- Add `constraint-export` to write generation packages containing specifications, source findings/proposals, examples, validation plans, and generation prompts.
- Add generated-rule outcome result types such as `violation_detected`, `false_positive`, `rule_suppressed`, and `rule_disabled`.

### Changed

- Require generated files, registration file, commit, tests, validation result, and content hashes when applying constraint proposals.

## [0.1.0] - 2026-06-12

### Added

- Add Active Guidance modeling and repository to parse/load active guidance files.
- Add Rejection filtering via a repository and selector to skip proposing previously rejected items.
- Add structured JSON-based consolidation input prompt formatting inside fenced JSON blocks for secure LLM parsing.
- Add consolidation result parsing and schema validation supporting concrete result types (`ADD`, `DELETE`, `REPLACE`, `REJECT`, `NO_DURABLE_LEARNING`).
- Add proposal importer to atomically write candidate proposals.
- Add transition managers for atomic finding and proposal lifecycle operations (approvals, rejections, application states) with rollback support.
- Add outcome logging and repository to track applied proposal outcomes.
- Integrated all new operations into the CLI tool (`proposal-import`, `finding-transition`, `proposal-approve`, `proposal-reject`, `proposal-mark-applied`).

## [0.0.2] - 2026-06-11

### Added

- Add deterministic multi-selector consolidation preparation with repeatable `--finding`, `--task`, `--ticket`, and `--scope` selectors plus `--since` and `--until` date filters.
- Print selected findings before writing consolidation input.
- Append `templates/consolidation-prompt.md` from the learning root during `prepare`.
- Add proposal lifecycle directory validation through `ProposalLifecycle`.
- Add `FindingSelection` for explicit consolidation input selection.

### Changed

- Replace the project-specific default task ID pattern with a generic default that accepts IDs like `PROJECT-123`, `GH-123`, and `TODO@path`.
- Rename `DecisionRecorder` to `DecisionHistoryValidator` because the class validates history and does not record decisions yet.
- Document the difference between proposal actions and proposal lifecycle status.
- Remove IT-Portal-specific examples and local filesystem links from package documentation and fixtures.

### Fixed

- Reject invalid finding status and `validation_status` combinations.
- Reject invalid proposal action and status combinations, such as `REJECT` with `approved`.
- Reject proposal files whose embedded status does not match their `proposals/<status>/` directory.
- Validate `approved_at` timestamps on proposals when approval metadata is present.

## [0.0.1] - 2026-06-09

### Added

- Initial CLI for validating findings, proposals, and decision history.
- Initial consolidation prompt generation for one task selector.
- Finding, proposal, evidence, JSONL, redaction, and path validation primitives.
- Composer binary entrypoint `agent-learning`.
