# Changelog

All notable changes to `voku/agent-learning` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

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
