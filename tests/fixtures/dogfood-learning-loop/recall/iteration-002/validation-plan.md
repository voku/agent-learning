# Validation Plan

## Required Validation

## Guidance: proposal.2026-06-19.001
- composer validate --strict
- composer test
- composer phpstan
- php bin/agent-learning guidance-evaluate --root dogfood/learning-root --selection-history history/recall-selections.jsonl --outcome-history history/outcomes.jsonl --write-candidates
