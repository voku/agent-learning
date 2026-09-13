# Hard Constraint Precedents and Examples

This directory provides concrete examples, templates, and patterns for converting approved `agent-learning` constraints into executable checks using **PHPStan** and **PHP-CS-Fixer**.

## Workflow Architecture

`agent-learning` owns the durable finding, evidence, proposal, and decision history.
The **consuming repository** (or a shared rule repository such as `voku/phpstan-rules`) owns the actual rule class, fixtures, tests, and CI configuration. `agent-loop` orchestrates this flow.

```text
coding task
    ↓
Finding (observation + hypothesis + validation status)
    ↓
multiple Findings / confirmed pattern
    ↓
Proposal (target_type: constraint)
    ↓
ConstraintSpecification (typed specification)
    ↓
Human approval
    ↓
Generation Package
    ├── specification.json
    ├── source-findings.json
    ├── source-proposals.json
    ├── examples.json          ← few-shot structural and AST precedent
    ├── validation-plan.json
    └── generation-prompt.md   ← explicitly instructs agent to inspect examples
    ↓
L1 Coding Agent
    ↓
PHPStan rule / fixer / test / CI check
    ↓
Fixtures (valid, invalid, boundary, false-positive) + Validation
    ↓
CI becomes authority
```

---

## Ownership Heuristics

Where should a new constraint live?

| Learning Scope | Owner Repository | Example |
| -------------- | ---------------- | ------- |
| Project-specific architecture / layers | Host Repository | `infra/githooks/StandardProject/PHPStan/NoDirectDatabaseCallInViewRule.php` |
| Project-specific domain / API invariant | Host Repository | `infra/githooks/StandardProject/PHPStan/AppTranslationParametersRule.php` |
| General PHP semantics / AST patterns | `voku/phpstan-rules` | `voku/PHPStan/Rules/IfConditionBooleanAndRule.php` |
| Syntactic formatting / token normalization | Host Repository or Fixer package | `infra/githooks/StandardProject/fixer/ForbiddenNativeStringFunctionFixer.php` |
| Runtime state invariant | Project Test Suite | `tests/Unit/LifecycleStateTest.php` |
| Multi-repository workflow / git convention | CI / tooling | `.github/workflows/ci.yml` or git hooks |

---

## The Three Types of Precedent in `examples.json`

When `agent-learning` exports a generation package, it populates `examples.json` with the full content of the files referenced in `example_rule_paths`. Coding agents consume these examples as few-shot precedent under three distinct categories:

1. **Structural Precedent** ("How a good rule/fixer is structured"):
   - Rule interface and type generics (e.g. `PHPStan\Rules\Rule<PhpParser\Node\Expr\StaticCall>`).
   - Constructor injection of configuration parameters or reflection providers.
   - Using `RuleErrorBuilder` with unique, stable identifiers (`->identifier('category.ruleName')`).
   - Standard fixer lifecycle (`isCandidate`, `isRisky`, `applyFix`, documentation methods).

2. **Semantic-Neighbor Precedent** ("How a related AST node or token sequence is traversed"):
   - Node selection via `getNodeType()`.
   - AST node filtering (e.g., distinguishing method calls from static calls, checking argument counts).
   - Scope inspection and type generalized evaluations (`$scope->getType($node)`, `$type->generalize()`, `$scope->getClassReflection()`).
   - Token stream navigation with `Tokens` (e.g., `getNextMeaningfulToken`, `findSequence`, `isGivenKind`).

3. **Validation Precedent** ("How candidate rules are proven"):
   - `valid.php`: Clean code that conforms to the rule and must pass without diagnostics.
   - `invalid.php`: Code intentionally violating the rule; must trigger the intended error identifier and line.
   - `boundary.php`: Edge cases and explicitly allowed exceptions that must not trigger a violation.
   - `false-positive.php`: Complex or adjacent syntax that naive checks would misinterpret, proving that false-positive guards work.

---

## Directory Contents

- [phpstan/](phpstan/): Reference custom PHPStan rules, AST inspection patterns, test fixtures, and `phpstan.neon` registration.
- [php-cs-fixer/](php-cs-fixer/): Custom PHP-CS-Fixer implementations, token transformations, fixer test cases, and configuration.
- [sample-generation-package/](sample-generation-package/): Example artifact directory produced by `vendor/bin/agent-learning constraint-export`.
