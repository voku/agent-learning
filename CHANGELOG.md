# Changelog

## [Unreleased]

## [0.16.1] - 2026-09-05

### Added

- Allow `FindingCreator::createValidated()` to publish optional typed `classification`, `pattern_key`, and `validation_case` metadata through the Learning owner boundary, so hosts can create an `ADD_LEARNING_NOTE` Finding that is immediately consumable by `LearningNoteService::prepare()` without hand-writing Learning-private storage.

### Validation

- PR #70 passed exact-head PHP 8.3, 8.4 and 8.5 package CI plus the clean installed-skill consumer before merge.

## [0.16.0] - 2026-09-04

### Changed

- Move package-owned skills from root `skills/` to `resources/skills/` following the shared repository layout convention. Introduce `PackageResources` as the single owner of package-shipped asset paths.

### Fixed

- Every transition now allocates its history id and writes under one root-scoped exclusive lock. `approve`, `reject`, `retire`, `acknowledge`, `apply` and `reanchor` each allocated their next id by scanning the log they were about to append to, so two runs that both read before either appended agreed on the same sequence number and wrote duplicate audit ids - into immutable history, the one place this package cannot repair. The lock file is `history/.transition.lock`, beside the logs it guards, so a project that already ignores `history/` ignores it too.

## [0.15.0] - 2026-09-04

### Added

- `proposal-reanchor <target>` repairs the applied `memory`/`skill` proofs pinned to one target file after it legitimately changed. `applied_validation.target_content_hash` pins the whole target, so a shared guidance home such as `MEMORY.md` could not be edited again - not even to repair an evidence path a directory move invalidated - without every applied record on that file reporting drift it did not cause; retiring answered a curation question nobody asked and re-applying is closed to an applied record. The repair is scoped to the target rather than one proposal because the drift belongs to the file: repairing one of several proofs would leave the root invalid and could never commit. It re-pins the hash in one transaction and only while each proposal's own guidance wording is still present, keeps approval/application/validation evidence unchanged, and requires an explicit actor and reason recorded per proposal in `history/reanchored-proposals.jsonl`.
- `history/reanchored-proposals.jsonl` is validated like the other transition logs: every record must name a known proposal, a reason, who re-anchored it and when, plus the target it re-pinned and that target's sha256 - the proof is the whole content of the record, so an entry missing it cannot be checked against the repository it describes.

### Changed

- The five dated transition-id generators share one scan, so a new transition cannot disagree with the existing ones about what "next" is.

### Fixed

- `proposal-reanchor` matches proofs by the file they resolve to rather than by how they spell it. `AppliedGuidanceTargetValidator` accepts `MEMORY.md` and `./MEMORY.md` as the same in-root target, so a root whose proofs used both forms repaired only one subset and then rolled the whole transaction back citing a proposal the caller had no way to name.

## [0.14.2] - 2026-09-01

### Fixed

- Accept multi-segment governed workflow task IDs as default Finding task references, so a Run can record a Finding for its own work without bypassing Learning validation. Ticket keys and explicit `TODO@...` references remain valid; ambiguous two-segment and malformed IDs remain rejected.

### Validation

- Focused Finding validator regression coverage accepts governed workflow IDs and preserves the invalid-ID boundary.

## [0.14.1] - 2026-09-01

### Added

- Expose complete read-only Finding and Proposal lists through `LearningCatalog`, with optional owner-status filtering and deterministic newest-first ordering, so consumers can browse Learning state without private storage scans.

### Validation

- Exact-head PHP 8.3, 8.4 and 8.5 package CI and the clean installed-skill consumer pass on the owner API follow-up.

## [0.14.0] - 2026-09-01

### Added

- Add first-class evidence-backed `LearningNote` precedents for `ADD_LEARNING_NOTE`, with a typed Learning-owned prepare/publish/read/status/retire boundary, stable `pattern_key` ownership, exact Finding/Proposal lineage, redaction, source-drift states, and compact read projections. LearningNotes are durable solved-case precedent, not active guidance or mutation authority.
- Add the package-owned `agent-learning-note` authoring skill and standalone CLI. The flow grounds current facts separately from validated historical Findings and synthesis, preserves human promotion authority, and ships with clean copied Composer consumer proof plus real bug-shaped and architecture/workflow-shaped dogfood.

### Fixed

- Make `finding-create` failures report the accepted evidence types and missing requirements together so a caller can repair an invalid Finding in one round trip.
- Expose `outcome_withheld_reason` through the typed `RecallSelectionEvent` owner API so downstream consumers do not need to inspect raw Learning JSONL history.
- Retire writes of the unversioned legacy outcome shape while keeping existing historical records readable, so new Learning state uses the versioned owner format without rewriting history.
- Make the installed `agent-learning-note` binary resolve Composer/package autoloading through the same supported path as the primary CLI instead of assuming a package-local `vendor/autoload.php`.

### Validation

- PR #57 established the durable typed LearningNote owner contract and passed exact-head package CI on PHP 8.3, 8.4 and 8.5.
- PR #59 passed exact-head package CI on PHP 8.3, 8.4 and 8.5, the focused LearningNote authoring regressions, two real-case dogfood fixtures, and the clean copied Composer installed-skill consumer.

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

## [0.12.0] - 2026-08-15

### Changed

- **Breaking:** a governed Run Learning decision may bind to the exact Contract
  revision, implementation snapshot, validation evidence digest, and review
  evidence digest it describes. A later complete evidence boundary for the
  same Run can replace the stale close-out instead of leaving old Learning
  authoritative forever.
- The four evidence-binding fields are all-or-none and remain opaque to
  `agent-learning`; repository snapshot semantics stay owned by the workflow
  orchestrator.

## [0.11.1] - 2026-08-14

### Changed

- `dream` text output now prints selected-versus-judged guidance outcome
  completeness and warns with the exact selections still missing a judgement.
  Placeholder `unknown` rows therefore cannot make a human-facing report look
  complete merely because every selected item has a draft row.

### Validation

- PHP 8.3/8.4/8.5 CI is green on the merged change, including the deterministic
  Dream report regression for one judged selection out of two selected items.


## [0.11.0] - 2026-08-14

### Added

- `RecordIdGenerator` and `agent-learning finding-id`: record IDs are now
  allocated rather than guessed. Findings never had an allocator at all, and
  `ProposalIdGenerator` derived the next number by scanning the local
  directory for the highest one - unique only for a writer who can see every
  other writer. In a system built for several agents on several branches, two
  branches each saw `.004`, each allocated `.005`, and each passed its own
  validation because neither could load the other's file. The duplicate only
  appeared at the merge, where renumbering is most expensive.

### Changed

- **Breaking:** `ProposalIdGenerator` is replaced by `RecordIdGenerator`.
  Allocated IDs now carry a random suffix (`proposal.2026-08-14.a3f2c1`)
  instead of a per-day sequence. The date prefix is unchanged, so a record set
  still reads as a timeline.
- Finding and proposal ID validation accepts both the legacy sequential suffix
  and the new random one. **Existing IDs are not migrated**: they are published
  in changelogs, memory rows and proposal citations, and rewriting them would
  break every reference to buy nothing.

### Fixed

- `GuidanceCandidateProposalWriter` now refuses to write over an existing
  candidate. The free filename used to be guaranteed by the sequential
  allocator's construction; an allocated suffix does not provide it, so the
  guarantee is asserted rather than assumed.

### Validation

- 239 tests and PHPStan level 8, plus the change applied against agent-loop's
  live store: 27 findings and 14 proposals carrying legacy sequential IDs all
  still validate, and `learn backlog` stays clear.
- The collision test injects its entropy source instead of sampling real
  randomness. Drawing a thousand suffixes and demanding no repeat carries a
  2.94% failure probability per run - roughly one CI run in thirty-four - and
  measures `random_bytes` rather than this package.

## [0.10.0] - 2026-08-12

### Added

- `RunLearningDecision`, `RunLearningDecisionStatus` and
  `RunLearningDecisionStore`: agent-learning now owns the durable Learning
  close-out for a governed Run, keyed by `run_id`. Previously this lived in
  `agent-session`, where pruning working memory destroyed the record that
  explained why a Run had been allowed to close. A decision recorded here
  outlives the Session it was reached in.

### Changed

- **Breaking:** the canonical learning root is `.agent-loop/learning`, and
  historical discovery of older locations is removed. A repository that kept
  learning state elsewhere must migrate it or configure the root explicitly;
  nothing is copied, symlinked or dual-read. See `UPGRADING.md`.

## [0.9.0] - 2026-08-09

### Changed

- **Breaking:** `APPLIED` Memory/Skill guidance must now prove that the reviewed
  mutation physically exists in a concrete canonical target under the configured
  project root. `applied_validation` requires `target_source_ref` and a SHA-256
  `target_content_hash`; repository validation checks the real file and the
  action postcondition before accepting the applied state.
- `ADD` requires the new wording to be present, `REPLACE` requires the new wording
  to be present and the old wording absent, and `DELETE` requires the old wording
  to be absent. A semantic proposal target alone is no longer sufficient proof
  that application happened.
- Application proof and authority handoff remain separate. A physically changed
  Skill or Memory file is evidence that the mutation landed; it does not by itself
  prove a downstream recall consumer selects that canonical source.

### Fixed

- `ProposalTransitionManager::apply()` now fails repository re-validation and
  rolls back to `approved` when Memory/Skill application evidence is missing,
  points outside the configured project root, references a missing file, carries
  the wrong content hash, or does not satisfy the proposed mutation.
- Successful application history is not appended when the physical target proof
  fails.

### Documentation

- Added `docs/applied-guidance-proof.md` describing the physical-proof contract,
  the distinction between semantic target identity and repository source
  evidence, and why canonical-source activation/retirement remain separate.

## [0.8.12] - 2026-08-03

### Changed

- Replaced host-project-specific release notes with repository-neutral wording.
- Migrated PHPUnit data providers to attributes, removing PHPUnit 12
  deprecation warnings from the release suite.

## [0.8.11] - 2026-08-02

### Added

- Add explicit `history-rebuild` and `history-status` commands. They produce
  deterministic `active-guidance.snapshot.json`, `chronicle.jsonl`, and a
  source-digest manifest from immutable learning evidence, and fail clearly
  when a compact projection is missing, corrupted, or stale.
- Include compact-history inventory metrics in the schema-versioned Dream
  report: active/archived records, files and bytes read, projection size, and
  compression ratio. Runtime remains opt-in so standard reports stay byte-stable.

### Fixed

- Calculate outcome completeness from unique selected
  `(compilation_id, guidance_id)` identities, report no rate for an empty
  selection history, count producer decision overlap before deduplication, and
  use the reviewable decision population for the stale-candidate rate.

## [0.8.10] - 2026-07-31

### Added

- Extend Agent Dreaming replacement detection with explicit newer-finding lineage,
  harmful-outcome corrected successors, and scope narrowing; extend conflict
  detection with contradicted guidance lineage and exact cross-tier duplicates.
- Validate the optional explicit lineage fields used by those policies and add
  outcome-completeness and stale-candidate rates to the deterministic report.

### Changed

- Document the DreamRunResult contract and the explicit human review/forgetting
  flow without creating another documentation tree.

All notable changes to `voku/agent-learning` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [0.8.9] - 2026-07-31

### Fixed

- Include the Dreaming value objects, evaluator, evidence-quality auditor, and
  replacement/conflict policies in the package archive. Version 0.8.8 wired
  the CLI to those classes but omitted the newly added source files, causing
  `agent-loop learn dream` to fail at runtime with a missing-class error.

## [0.8.8] - 2026-07-31

### Added

- `dream`: a deterministic, human-reviewed guidance-maintenance command. It
  rebuilds its result from immutable recall-selection and guidance-outcome
  histories; audits missing versus unknown outcomes, unresolved file evidence,
  and aged findings; reports compact machine-readable or text output; and
  writes no durable guidance unless `--write-candidates` is explicitly passed.
  Reports deliberately omit generated timestamps so equal inputs produce
  byte-stable output.
- Conservative `REPLACEMENT_CANDIDATE` production for exact, explicit applied
  guidance successors, plus report-only `CONFLICT` records. A conflict needs
  explicit `conflicts_with` lineage; unrelated wording under one pattern key is
  not guessed to be contradictory.
- Stable evolution decision keys. Candidate, rejected, and acknowledged review
  records now suppress only an unchanged decision key, allowing materially new
  evidence to create a new review candidate without replaying a rejected one.

## [0.8.7] - 2026-07-28

### Fixed

- `guidance-evaluate --write-candidates` regenerated a proposal a reviewer had
  already declined. `GuidanceCandidateProposalWriter::findExistingCandidate()`
  only scanned `proposals/candidate/`, so once a candidate was rejected (and
  therefore moved to `proposals/rejected/`) the next run no longer saw it and
  wrote an identical decision under a fresh proposal ID. Rejecting five
  auto-generated candidates and re-running the command immediately recreated
  all five. The lookup now also scans `proposals/rejected/` and
  `proposals/acknowledged/`, so a terminal human decision suppresses
  regeneration of the same guidance_id + decision_type + source_tier +
  target_tier combination. `approved`/`applied` are deliberately not included:
  those describe a change that landed, not a decision to stop proposing.

## [0.8.6] - 2026-07-28

### Added

- `ConstraintEngine::PHPCS` (`phpcs`). Repositories that enforce constraints with
  PHP_CodeSniffer sniffs previously had no honest way to express that: the enum
  offered only `phpstan`, `php_cs_fixer`, `test`, and `ci`, and `php_cs_fixer`
  is a different tool (php-cs-fixer) with a different rule location and command,
  so reusing it would have mislabeled the constraint. Discovered downstream in
  a downstream repository, where a `*_UnitCest.php` constraint had to be a phpcs
  sniff because `phpstan.neon` excludes `*Cest.php` from analysis entirely,
  making an equivalent PHPStan rule unable to ever fire. `ConstraintPromotionValidator`
  now applies the matching symmetry checks for the new engine: a `phpcs`
  constraint requires a phpcs/codesniffer validation command and a
  `target_rule_path` pointing at a `/Sniffs/` location.

## [0.8.5] - 2026-07-28

### Fixed

- `SkillStalenessPolicy`, `MemoryToSkillPromotionPolicy`, and
  `SkillToConstraintPromotionPolicy` did not propagate an approved proposal's
  `scope_justification` into the generated candidate's `proposalExtras`, unlike
  `MemoryStalenessPolicy` (fixed in 0.8.4/d436428 for the memory tier only).
  A `guidance-evaluate --write-candidates` run for a skill-tier STALE_CANDIDATE
  or a memory-to-skill / skill-to-constraint PROMOTION_CANDIDATE whose source
  proposal's scope was legitimately broader than any single cited finding
  (and had a `scope_justification` explaining why) would hit
  `ProposalValidator`'s "proposal scope is broader than source finding
  evidence without justification" check and abort the entire
  `write-candidates` batch, including every other pending candidate. All three
  policies now build `proposalExtras['scope_justification']` from the source
  proposal the same way `MemoryStalenessPolicy` does.

## [0.8.4] - 2026-07-15

### Added

- `guidance-evaluate` now warns when `history/outcomes.jsonl` contains records
  in the older "outcome.*" session-summary shape (helpful/irrelevant/harmful
  as proposal-ID arrays, predating the per-guidance-item "guidance-outcome.*"
  shape). `OutcomeRepository::loadAll()` already validated both shapes without
  error, but `GuidanceOutcomeEventRepository::load()` -- and therefore every
  `GuidanceUsageProjector` statistic and promotion/staleness decision -- has
  always silently skipped anything that is not `guidance-outcome.*`. A
  guidance item whose only recorded usage was in the older shape was
  indistinguishable from one that was never used at all, with no signal that
  older data was excluded. New `GuidanceOutcomeEventRepository::countLegacyRecords()`
  makes that count explicit; `guidance-evaluate` now prints it before its
  usual summary/decision output when the count is non-zero. This does not
  migrate old records into the new shape (that would require fabricating
  matching recall-selection linkage that never existed for them) -- it only
  makes the existing exclusion visible instead of silent.

## [0.8.3] - 2026-07-15

### Fixed

- `constraint-export`, `constraint-activate`, and `proposal-validate` failed with
  "proposal file does not exist" when given a bare proposal ID (e.g.
  `proposal.2026-06-14.001`) instead of a full file path, because they resolved
  `--proposal` via `Cli::resolveProposalPath()`, which concatenates the raw
  argument onto each status directory without appending `.json` and without
  falling back to `ProposalTransitionManager::resolveProposalPath()`.
  `constraint-loop` and `proposal-approve`/`proposal-reject` already handled bare
  IDs correctly via `resolveProposalPathOrId()` -- the three affected commands now
  use the same resolver, so all proposal-referencing commands accept either a
  bare ID or a full path consistently.

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
- Remove host-project-specific examples and local filesystem links from package documentation and fixtures.

### Fixed

- Reject invalid finding status and `validation_status` combinations.
- Reject invalid proposal action and status combinations, such as `REJECT` with `approved`.
- Reject proposal files whose embedded status does not match its `proposals/<status>/` directory.
- Validate `approved_at` timestamps on proposals when approval metadata is present.

## [0.0.1] - 2026-06-09

### Added

- Initial CLI for validating findings, proposals, and decision history.
- Initial consolidation prompt generation for one task selector.
- Finding, proposal, evidence, JSONL, redaction, and path validation primitives.
