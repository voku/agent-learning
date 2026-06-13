# Agent Learning (Self-Hosting)

This directory is the review layer for `voku/agent-learning` coding-agent learnings. It stores raw findings, consolidation input, candidate proposals, and decision history before any long-term guidance is promoted into `MEMORY.md`, `infra/doc/`, or `infra/doc/agents/skills/`.

## Workflow

- Capture findings under `findings/` with commit-safe evidence and explicit validation status.
- Use `make agent_learning_validate` after editing findings, proposals, or history.
- Use `make agent_learning_prepare TASK=...` to build deterministic consolidation input from selected validated findings.
- Use `make agent_learning_proposal_validate PROPOSAL=...` for concrete candidate proposals.
