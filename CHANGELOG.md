# Changelog

## [Unreleased]

### Added

- Add first-class evidence-backed `LearningNote` precedents for `ADD_LEARNING_NOTE`, with typed prepare/publish/status/retire owner boundaries, stable pattern ownership, exact Finding lineage, redaction, repository-evidence drift reporting, and compact read projections without promoting notes into active guidance.
- Add the package-owned `agent-learning-note` authoring skill. It grounds current-state claims before synthesizing one bounded solved case, uses only the typed Learning owner boundary for durable publication, distinguishes bug-shaped from architecture/workflow-shaped precedents, and never auto-promotes notes into Memory, Skills, Proposals, or Constraints.
- Dogfood LearningNote authoring with two reviewed historical `agent-loop` cases: linked-worktree repository detection and tool-coupled instruction ownership. A clean copied Composer consumer proves the skill and owner CLI ship together.

## [0.13.6] - 2026-08-26

### Fixed

- Keep inactive and historical Learning records discoverable through the typed bounded overview by exposing deterministic recent finding and proposal IDs. Rejected, superseded, retired and other non-attention states remain browsable without downstream storage scans, while overview lists stay capped at 20 records.

## [0.13.5] - 2026-08-26

### Added

- Add the typed read-only `LearningCatalog` query boundary with bounded overview, finding, proposal, durable-guidance and task-scoped projections. The catalog preserves owner lifecycle states, finding/proposal/task lineage, guidance usage outcomes, rejected/history visibility and explicit attention candidates without exposing Learning's directory layout to consumers.

## [0.13.4] - 2026-08-25

### Fixed

- Preserve applied guidance records created before the 2026-08-09 physical-target-proof policy as immutable legacy history. Historical generic `file` targets and proof-less pre-policy `memory`/`skill` records remain readable, while new applications continue to require a modern `memory`, `skill`, or `constraint` target and current physical proof.

## [0.13.3] - 2026-08-20

### Fixed

- Make Constraint generation-package export adoption-aware. When the configured target rule and every registration file already exist, export now records `mode: adopt_existing`, keeps the validation commands and lineage evidence, omits synthetic PHP fixture expectations, and emits an adoption prompt instead of instructing the caller to generate duplicate enforcement.
- Keep incomplete enforcement in `mode: generate`: a missing target rule or any missing registration file preserves the existing generation package and four fixture expectations.
- Align the packaged hard-constraint author skill with the same generate-versus-adopt boundary while preserving explicit human activation authority.

### Validation

- PR #36 passed `composer ci` on PHP 8.3 and 8.4 on the exact final head. Focused regressions cover existing enforcement adoption, missing-target fallback, and missing-registration fallback; CodeRabbit's only review finding requested the missing-registration regression and was addressed before merge.
## [0.13.2] - 2026-08-20

### Fixed

- Match a PHPStan constraint's `target_rule_path` per path segment, case-insensitively and with backslashes normalised, instead of requiring the literal substring `/PHPStan/`. The previous check compared a filesystem path against a namespace-shaped fragment and rejected two legitimate layouts: a lowercase rule directory such as `phpstan/Rules/`, and any path whose first segment is the rule directory, which has no leading slash. Paths that merely contain the word, such as `rules/phpstanish/`, are still rejected.
- Name `constraint` as a durable target in the consolidation prompt, which previously offered only skill and learning-note outcomes while the result schema, promotion validator, generation exporter, activator and `constraint-*` commands all supported constraints. The prompt now requires deterministic enforceability, asks for the cheapest reliable owner, and states that proposing a constraint neither approves nor activates anything.

### Validation

- PR #32 passed CI on its exact head before merge, covering both regressions plus the package's own static analysis at level 8.

## [0.13.1] - 2026-08-18

### Added

- Add `FindingCreator::createValidated()` as the typed owner-level operation for creating one schema-valid validated Finding, including optional ID allocation, target-directory creation, duplicate protection, validation-before-publication, and atomic publication.
- Add standalone `finding-create` support over the same owner behavior for human and script callers.

### Documentation

- Document `FindingCreator` as the PHP lifecycle-host boundary so orchestrators do not need `finding-id` plus hand-written JSON or knowledge of the `findings/validated` storage layout.
- Keep the judgment boundary explicit: callers provide evidence-backed content and decide whether a Finding is warranted; Learning owns record validity, not the conclusion itself.

### Validation

- PR #28 passed CI on its exact head before merge for the owner-level Finding creation path.
- PR #29 passed CI run #240 for the PHP embedding and consumer-skill documentation.
## [0.13.0] - 2026-08-16

### Added

- Findings can optionally identify an external Composer package with
  `target_package` and the tested package identity with `tested_ref`, allowing
  host repositories to keep dogfood friction in the normal validated Finding
  lifecycle instead of a parallel feedback document.
- `FindingExporter` emits deterministic, read-only JSON for one exact target
  package. It preserves the raw Finding record and projects current source Run
  IDs through the existing `RunLearningDecision.finding_ids` relation rather
  than copying `run_id` onto Findings.

### Validation

- Existing Findings without an external target remain valid unchanged.
- PHP 8.3 and 8.4 CI is green, including PHPUnit and PHPStan level 8 over
  `src` and `tests`.
