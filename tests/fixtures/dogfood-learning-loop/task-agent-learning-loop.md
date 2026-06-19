# Dogfood Agent Learning Loop

## Goal

Use `agent-learning` and `agent-recall-compiler` together on real work in `agent-learning` until we discover one evidence-backed durable learning or record `NO_DURABLE_LEARNING`.

## Context

This is a dogfood task. The work must remain low-risk and backward-compatible.

## Non-goals

- No breaking CLI changes.
- No public schema changes unless existing compatibility is preserved.
- No automatic promotion.
- No automatic mutation of MEMORY.md, skills, constraints, or CI.
- No new feature work unless required to test the loop.
- No invented learning.

## Current State

Iteration 001 complete. Selected maintenance task: make the repository PHPStan composer script use an explicit memory limit after the repository-default `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist` path exhausted PHP's 128M default in this environment.

## Assumptions

- [x] The current CLI commands are discoverable through README or --help.
- [x] The repositories can be used locally together after installing `voku/agent-recall-compiler` locally for the session.
- [x] At least one low-risk real task can be performed in this repository.
- [x] Outcome logging can produce usable evidence for agent-learning when guidance is selected; this iteration selected no guidance, so no per-guidance outcome events were expected.

## Plan

- [x] Inspect current commands and repository layout.
- [x] Compile recall for this dogfood task.
- [x] Select one small real maintenance task.
- [x] Perform the task with tests.
- [x] Log per-guidance outcomes.
- [x] Import or record findings.
- [x] Evaluate whether a durable learning exists.
- [x] Stop after a real tooling gap was found and fixed without inventing durable guidance.

## Commands Used

- `composer show`
- `ls -la`
- `find . -maxdepth 3 -type f | sort`
- `find bin vendor/bin -maxdepth 1 -type f 2>/dev/null | sort`
- `php -v`
- `composer test 2>/dev/null || true`
- `vendor/bin/agent-learning --help 2>/dev/null || true`
- `vendor/bin/agent-recall-compiler --help 2>/dev/null || true`
- `composer install --no-interaction`
- `composer require --dev voku/agent-recall-compiler:^0.3 --no-interaction`
- `php bin/agent-learning --help || true`
- `vendor/bin/agent-recall-compiler --help || true`
- `vendor/bin/agent-recall-compiler compile --root . --task dogfood-agent-learning-loop --description "Dogfood agent-learning and agent-recall-compiler on a real agent-learning maintenance task" --file README.md --file bin/agent-learning --file src --file tests --output-dir dogfood/recall/iteration-001`
- `composer validate --strict`
- `composer test`
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist`
- `composer dump-autoload`
- `composer phpstan`
- `vendor/bin/agent-recall-compiler log-outcome --root . --draft dogfood/recall/iteration-001/recall-log.draft.json --by dogfood-agent --commit working-tree`
- `composer remove --dev voku/agent-recall-compiler --no-update --no-interaction`
- `php bin/agent-learning guidance-evaluate --root . --selection-history history/recall-selections.jsonl --outcome-history history/outcomes.jsonl --write-candidates || true`
- `php bin/agent-learning validate --root . || true`

## Decisions

- Dogfood artifacts are committed under `dogfood/` because the task explicitly asked to create the task file and record loop evidence; no repository convention forbids this safe work area.
- `voku/agent-recall-compiler` was installed for local dogfooding, then removed from `composer.json` before committing so this package does not gain a runtime or dev dependency solely from the dogfood session.
- The maintenance change is limited to the existing `composer phpstan` script and does not change CLI commands, schemas, or durable guidance.

## Recall Summary

- Selected guidance: none.
- Selected constraints: none.
- Required validation from `validation-plan.md`: none beyond repository-default validation.
- Irrelevant guidance: none selected.
- Active constraints affecting task: none selected.

## Findings

### finding.dogfood.001

- Observation: The repository-default raw PHPStan command `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist` exhausted PHP's default 128M memory limit in this environment after dependencies were installed.
- Evidence: The command failed with `Allowed memory size of 134217728 bytes exhausted`; after changing the `composer phpstan` script to include `--memory-limit=512M`, `composer phpstan` completed with `[OK] No errors`.
- Affected files or commands: `composer.json`, `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist`, `composer phpstan`.
- Why it matters: The learning loop asks agents to run repository-default checks; a default PHPStan path that can fail from tool memory rather than code errors blocks trustworthy validation.
- One-off or recurring: Tooling/environment gap likely to recur for agents using PHP's 128M default, but the repository script now directly mitigates it.
- Existing coverage: Tests and PHPStan cannot enforce the memory flag inside the composer script; `composer validate --strict` verifies the edited script remains syntactically valid.
- Should become durable guidance: No. The behavior is now encoded in the repository command, which is a stronger and less ceremonial control than adding memory guidance.

## Validation

- `composer validate --strict`: passed.
- `composer test`: passed; PHPUnit reported deprecations unrelated to this change.
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist`: failed before the maintenance change due to PHP 128M memory exhaustion.
- `composer phpstan`: passed after the maintenance change with the explicit memory limit.
- `php bin/agent-learning validate --root .`: passed and reported zero findings/proposals in the active root.
- `php bin/agent-learning guidance-evaluate --root . --selection-history history/recall-selections.jsonl --outcome-history history/outcomes.jsonl --write-candidates`: ran and wrote zero candidate proposals because this dogfood iteration selected no guidance and produced no qualifying usage projection.

## Outcome

Iteration 001 produced one useful task-local tooling finding and a repository script fix, but no durable learning proposal.

Reason: the only generalizable observation was better handled by the executable Composer script (`composer phpstan` with `--memory-limit=512M`) than by memory, skill, or constraint guidance. Recall selected no guidance, so there was no evidence of broad or irrelevant selector behavior to promote into a learning.

NO_DURABLE_LEARNING

## Next Step

Add a small regression test or fixture for `agent-recall-compiler` outcome logging in its own repository to verify empty-guidance sessions remain valid and do not create misleading per-guidance outcome history.

## Evidence Freeze Follow-up

The empty-guidance finding is now treated as evidence for the next repository in the chain rather than as a prompt to invent durable guidance here.

### Agent Recall Compiler Acceptance Checkpoint

The follow-up regression task in `voku/agent-recall-compiler` is considered complete only when all of these are covered:

- [ ] Compile with no selected guidance is tested.
- [ ] The generated outcome draft is tested with empty `selected`, `guidance_outcomes`, `applied`, `helpful`, `irrelevant`, and `harmful` arrays.
- [ ] `log-outcome` with empty guidance is tested.
- [ ] No fake guidance ID such as `none` is emitted.
- [ ] No `not_used` event is emitted for non-existing guidance.
- [ ] Duplicate close-out is tested and safe.
- [ ] Existing non-empty guidance tests still pass.
- [ ] README or docs mention empty-guidance sessions if they did not already.

### Dogfood Chain State

Completed chain so far:

1. `agent-learning` dogfood run completed.
2. Empty-guidance behavior was observed and recorded without treating absence as usage.
3. A direct validation tooling gap in `agent-learning` was fixed in `composer.json`.
4. The next concrete regression task for `agent-recall-compiler` is defined by the acceptance checkpoint above.

### Next Experiment

Run a second dogfood loop in `voku/agent-learning` where guidance is intentionally selected for a tiny real maintenance task, then judge the outcome honestly.

The second loop should verify:

- selected guidance can be logged as useful evidence without being treated as automatic proof of usefulness;
- the outcome can be marked `helpful`, `irrelevant`, `not_used`, or `harmful` based on actual task impact;
- `agent-learning guidance-evaluate` projects the resulting evidence;
- no promotion candidate is created unless the accumulated evidence meets the repository's thresholds;
- selector scope is questioned if the selected guidance does not materially affect the task.

## Iteration 002: Selected Guidance Signal Test

### Task

Run a second dogfood loop using a deliberately scoped active guidance item in `dogfood/learning-root` and a tiny maintenance update to this task record.

### Recall Selected

- `proposal.2026-06-19.001` was selected by scope overlap for `dogfood/task-agent-learning-loop.md` and `dogfood/recall/iteration-002`.
- No constraints or rejected proposals were selected.

### Changes Made

- Added `dogfood/learning-root` as a temporary dogfood learning root with one validated finding and one approved scoped memory proposal.
- Compiled recall into `dogfood/recall/iteration-002`.
- Updated this task record with the selected-guidance outcome evidence.

### Outcome Judgment

The selected guidance was **helpful** for this maintenance task because it changed close-out behavior: the outcome was judged explicitly instead of treating selection as proof of usefulness, and the next validation step checks that one selected/helpful outcome does not create an unjustified promotion.

### Durable Learning?

No new durable learning is proposed from this single selected-guidance session. The purpose of the iteration is to create honest usage evidence and verify conservative projection behavior, not to promote a rule after one success.

### Projection Result

`php bin/agent-learning guidance-evaluate --root dogfood/learning-root --task-id-pattern '/^DOGFOOD-[0-9]+$/' --selection-history history/recall-selections.jsonl --outcome-history history/outcomes.jsonl --write-candidates` projected the selected guidance as `eligible=1 selected=1 applied=1 helpful=1 irrelevant=0 harmful=0 not_used=0 unknown=0 tasks=1` and wrote zero candidate proposals.

Result: the loop handled one honest helpful signal without creating a promotion candidate from insufficient evidence.
