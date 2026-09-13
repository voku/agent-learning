# Constraint Generation Prompt

Generate a repository-local phpstan rule for `project.translation.parameters`.

Rule class: `AppTranslationParametersRule`

Target path: `infra/githooks/StandardProject/PHPStan/AppTranslationParametersRule.php`

Violation: Translation placeholders and supplied parameter keys differ.

Scope:
- src/

Allowed boundaries:
- unpacked_arguments
- dynamic_format_strings

Validation commands:
- vendor/bin/phpstan analyse

The candidate must include valid, invalid, boundary, and false-positive fixtures. Do not activate the rule without human approval.

## Existing examples and precedent

Before implementing the rule, inspect `examples.json`.

Use the examples as structural precedent for:
- PHPStan node selection (getNodeType), AST traversal, and type resolving
- Rule/fixer interface, generics, and return types
- Rule error identifiers, error builder usage, and clear violation messaging
- Scope handling, AST inspection, and type resolving
- False-positive avoidance and allowed boundary checks
- Configuration and registration files
- Fixture and test structure (valid, invalid, boundary, and false-positive)

Reuse established repository patterns where applicable.

Do not blindly copy behavior from an example: the approved ConstraintSpecification remains authoritative. Examples demonstrate implementation patterns, not requirements. Ensure fixtures prove that divergence from examples is intentional and properly tested.
