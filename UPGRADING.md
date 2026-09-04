# Upgrading

## Packaged skills move to `resources/skills/`

Skills shipped by this package have moved from the repository root `skills/` to `resources/skills/`:

```text
skills/ -> resources/skills/
```

Runtime code can use `PackageResources::skillsRoot()` or `PackageResources::SKILLS` rather than hardcoding the path.

## Record IDs are allocated, not guessed

`ProposalIdGenerator` is replaced by `RecordIdGenerator`. If you constructed it
directly, swap the class and pass the record kind:

```php
- (new ProposalIdGenerator())->generate($root);
+ (new RecordIdGenerator())->generate('proposal');
```

Allocated IDs now end in a random suffix instead of a per-day sequence. Nothing
needs migrating: validation still accepts every existing `NNN` ID, and the date
prefix is unchanged.

For findings, stop hand-picking the next number and ask for one:

```bash
vendor/bin/agent-learning finding-id
# finding.2026-08-14.a3f2c1
```

This matters when more than one agent or branch writes findings: the old scheme
could only see the local directory, so two branches reliably allocated the same
ID and the duplicate surfaced at the merge.

## Default learning root moves below `.agent-loop/`

This is a breaking default-path change.

The canonical repository-local learning root is now:

```text
.agent-loop/learning/
```

Older installations commonly used:

```text
infra/doc/agent-learning/
.agent-learning/
docs/agent-learning/
agent-learning/
```

Those historical locations are no longer auto-discovered. If a repository still
needs one temporarily, select it explicitly with `--root`; otherwise migrate the
state once. There is deliberately no compatibility fallback or second source of
truth.

For the common agent-loop layout, migrate once:

```text
infra/doc/agent-learning/ -> .agent-loop/learning/
```

Then run `agent-learning validate` against the new root. There is no automatic
copy, symlink, merge, or dual-write. Explicit `--root` and configured project
paths remain authoritative.
