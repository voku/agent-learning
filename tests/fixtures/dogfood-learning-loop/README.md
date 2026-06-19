# Dogfood Learning Loop Fixtures

These fixtures preserve the evidence from the agent-learning dogfood runs without keeping a runnable `dogfood/` work area at the repository root.

They cover two regression scenarios:

1. `iteration-001`: recall compiled with no selected guidance and produced an empty outcome draft. This documents absence handling and guards against treating silence as guidance usage.
2. `iteration-002`: recall compiled with one selected scoped guidance item, logged one explicit helpful outcome, and `agent-learning guidance-evaluate` projected the signal without writing a promotion candidate.

The `learning-root/` directory is a self-contained learning root fixture. Tests copy it to a temporary directory before running validators or writing candidate outputs.
