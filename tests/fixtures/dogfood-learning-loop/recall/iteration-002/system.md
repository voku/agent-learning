# L2 Meta-Prompt Briefing for Task: DOGFOOD-2
> Generated at 2026-06-19T00:03:31+00:00

## Task Description
Second dogfood loop: selected guidance evidence projection without premature promotion

## Repository Global Memory (`MEMORY.md`)
# Dogfood Learning Root Memory

Temporary dogfood learning root used to exercise selected-guidance recall behavior without mutating repository-wide durable guidance.

## Selected Active Guidance
### Guidance: proposal.2026-06-19.001
- **Target**: memory.dogfood.selected_guidance_outcome
- **Scope**: dogfood
- **Boundary**: Dogfood evidence-capture tasks under the `dogfood/` directory only.

#### Directive:
```text
For dogfood tasks under `dogfood/`, treat selected guidance as a prompt to make an explicit outcome judgment, not as proof that the guidance was useful. Mark the selected item helpful, irrelevant, harmful, or not used based on concrete task impact, then verify `guidance-evaluate` does not create a promotion from insufficient evidence.
```
