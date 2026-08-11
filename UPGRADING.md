# Upgrading

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
