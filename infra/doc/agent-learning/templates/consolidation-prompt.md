# Agent Learning Consolidation Prompt Addendum

Apply this acceptance test before proposing any durable guidance change:

- Cite concrete agent-learning package evidence such as paths, commands, tests, or code structure.
- Keep the wording specific enough that a future developer/agent can act on it without guessing the validation path or context.
- Keep the scope reusable for a bounded task family instead of the exact incident only.
- State the known boundary where the rule does not apply.
- Preserve the package's design goals: only govern learning, do not absorb session recording/task management.

Return `NO_DURABLE_LEARNING` for generic advice, raw session summaries, one-off facts, or over-broad conclusions that the evidence does not support.
