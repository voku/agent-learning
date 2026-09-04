# Contributing

Thanks for considering a contribution to `voku/agent-learning`.

## Scope

`agent-learning` provides structured post-session learning for coding agents:
Findings (raw observations), LearningNotes (solved-case precedents), and
Proposals (guideline changes and validations). It maintains auditability and
prevents hallucinated guidance from becoming durable authority.

## Development setup

```bash
git clone https://github.com/voku/agent-learning.git
cd agent-learning
composer install
```

## Before opening a PR

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan at max level
composer ci        # Runs composer validate --strict, test, and phpstan
```

All checks must pass cleanly.

## Code style

- `declare(strict_types=1)` in every PHP file.
- `final` classes and `readonly` value objects wherever applicable.
- Strict typing with zero PHPStan errors at the highest analysis level.
- Put unit tests under `tests/` mirroring the `src/` directory structure.
- Clear, descriptive commit messages and focused pull requests.
