---
name: project-phpstan-precision
description: Tighten project PHPStan precision, e.g. PHPDoc, array shapes, generics, class-string constraints, dynamic return types, and targeted checks.
---

# Project PHPStan Precision

Use PHPDoc as a precision layer, not as decoration.

## Fast Path

For a normal PHPStan improvement or fix:

1. Read `references/phpstan-precision-map.md` and inspect the actual PHPStan error before changing any contract.
2. Try a native type first (PHP 8.3+); add PHPDoc only when native syntax cannot express the needed shape, generic, range, or conditional behavior.
3. Preserve intentionally strict contracts such as `literal-string`, `class-string<T>`, and `non-empty-string`; fix the proof gap instead of weakening the contract when the intent is right.
4. Tighten boundary shapes with array shapes (`array{id: int, name: string}`), `list<T>`, templates, or typed adapters instead of piling vague annotations onto unstable internals.
5. Enhance PHPStan analysis precision for custom global helpers or database mappers by building dedicated **dynamic return type extensions** instead of adding inline casts.
6. Run `php -l`, targeted `vendor/bin/phpstan analyse`, and the nearest unit tests when behavior or data transformation changes.

## Default Rules

- Prefer native PHP types first.
- Add PHPDoc only when native PHP cannot express enough.
- Avoid inline `@var` unless there is no cleaner restructure. In particular, never pair a scalar cast with an inline `@var` to re-assert a type a property already declares.
- Prefer array shapes, `list<T>`, `non-empty-*`, `class-string<T>`, and templates over `array` or `mixed`.
- Keep public contracts analyzable across call sites.
- Do **not** weaken an intentionally stricter contract such as `literal-string`, `class-string<T>`, or `non-empty-string` to a looser type just because PHPStan currently cannot prove it.
- Enhance PHPStan analysis precision for custom helpers by building dedicated dynamic return type extensions (`DynamicFunctionReturnTypeExtension`, `DynamicMethodReturnTypeExtension`).

## Workflow

### 1. Confirm the real gap

- Read the PHPStan error.
- Check whether a native type can solve it first.
- Add PHPDoc only for shapes, generics, ranges, or conditional return behavior.
- Distinguish between a wrong contract and a proof gap. If the stricter type expresses the real intent, preserve it and instead narrow inputs earlier, add a typed adapter/assertion, or add a dynamic return type extension.

### 2. Tighten the contract

- Replace vague `array` with an explicit shape or `list<T>`.
- Replace `string` with `class-string<T>` or `non-empty-string` when appropriate.
- Introduce `@template` only when a reusable generic abstraction truly exists.
- Avoid over-annotating private implementation details unless PHPStan needs them.
- Prefer preserving semantic intent over matching whatever type is easiest to infer today. Only relax a strict contract when the contract itself is wrong.

### 3. Validate narrowly

- Run `php -l` on changed files.
- Run targeted PHPStan on touched files (`vendor/bin/phpstan analyse path/to/file.php`).
- Run the closest unit tests if behavior or data transformations changed.
