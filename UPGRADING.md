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

Historical roots remain valid when selected explicitly, but discovery prefers
`.agent-loop/learning/` when both the new and an old root exist. This prevents
an upgraded repository from silently continuing to write durable learning into
a historical directory after the migration.

For the common agent-loop layout, migrate once:

```text
infra/doc/agent-learning/ -> .agent-loop/learning/
```

Then run `agent-learning validate` against the new root. There is no automatic
copy, symlink, merge, or dual-write. Explicit `--root` and configured project
paths remain authoritative.
