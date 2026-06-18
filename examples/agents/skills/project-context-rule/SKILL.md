---
name: project-context-rule
description: Turn stable repository guidance into local executable constraints such as static-analysis rules, tests, or CI checks.
---

# Project Context Rule

Use this wrapper with `agent-hard-constraint-author` when a repeated learning is objectively detectable.

## Fast Path

1. Start from validated findings or an approved constraint proposal.
2. Define the violation, allowed boundary, false-positive risk, rule identifier, fixtures, and validation command.
3. Implement the rule in the consuming repository, not in `voku/agent-learning`, unless it is package-generic.
4. Prove both directions: invalid fixture fails with the intended rule, valid fixture and real target files pass.
5. Activate the constraint manifest only after human review and validation evidence.
