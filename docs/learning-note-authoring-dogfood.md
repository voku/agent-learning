# LearningNote authoring dogfood

Issue #56 is dogfooded against two real `voku/agent-loop` historical learnings. The source Findings predate first-class Finding triage metadata, so the test fixture preserves their historical Finding content and copies only `ADD_LEARNING_NOTE`, `pattern_key`, and `validation_case` from the later human-reviewed approved/applied Proposal. It does not rewrite the historical source repository.

## Bug-shaped case: Git worktree repository detection

Reviewed classification source: `voku/agent-loop` `proposal.2026-08-14.003` (`approved_by: voku`).

- pattern: `git.repository.state.asked.not.inferred`
- source Findings: `finding.2026-08-08.001`, `finding.2026-08-14.013`
- historical failure: production code inferred repository state from `is_dir(<root>/.git)`, which rejects a linked worktree because `.git` is a file.

Current-tree grounding on 2026-09-01:

- `voku/agent-loop:src/GitWorkTree.php` asks `git rev-parse --is-inside-work-tree`; inspected blob `9460c3dbb1c5bde351e70a1c7568c07a592646ee`.
- `voku/agent-loop:phpstan/Rules/NoGitDirectoryShapeAssumptionRule.php` rejects the production `is_dir(.git)` shortcut and points callers to `GitWorkTree`; inspected blob `72ed0100de2a1111adda35a9c7f7441e3ac95aae`.

The dogfood LearningNote therefore records the broken behavior as historical context, not current fact. Its bug-shaped content includes the observed symptoms, failed filesystem-shape approach, validated root cause, current resolution, applicability boundary, and verification path.

## Architecture/workflow case: tool-coupled instruction ownership

Reviewed classification source: `voku/agent-loop` `proposal.2026-08-14.013` (`applied`, `approved_by: voku`).

- pattern: `tool.coupled.instructions.ship.with.the.tool`
- source Finding: `finding.2026-08-14.005`
- historical friction: a Recall-specific operating-prompt catalog lived in a separately pinned skill repository while Recall code and releases evolved independently.

Current-tree grounding on 2026-09-01:

- `voku/agent-recall-compiler:skills/agent-recall-consumer/SKILL.md` declares that Recall owns reusable assets coupled to its compile/output contract; inspected blob `27338e95442306d1d98b944819111594da975410`.
- Recall ships `skills/agent-recall-consumer/operating-prompts.json`; inspected blob `a8fbce58e4727ef616856442ad6df706a1e41a72`.
- `voku/agent-loop` separately owns a smaller loop-level `resources/operating-prompts.json`; inspected blob `8660f23f984286ad050738c588fa59d02b7f481c`.
- agent-loop's reviewed repository memory names the same ownership rule; inspected blob `15210971cdb8ad20dc015508a2fc4fa04a731ca8`.

The resulting note is knowledge-shaped: it has context, guidance, applicability boundaries, a historically failed ownership arrangement, examples, and verification, but deliberately leaves `symptoms` and `root_cause` absent. The current lesson is ownership by tool contract, not a global ban on similar filenames in separate tools.

## What this proves

`tests/LearningNoteSkillDogfoodTest.php` runs both cases through the typed Learning owner preparation and publication services with current repository-evidence hashes. It asserts distinct stable patterns, current evidence state, correct case shape, exact Finding lineage, and durable note digests.

The packaged skill itself remains an agent-facing authoring procedure. PHP performs no model call and grants no new authority. The installed-consumer CI separately copies the Composer package into a clean project and proves the skill plus `agent-learning-note` owner CLI are present without relying on a path symlink.
