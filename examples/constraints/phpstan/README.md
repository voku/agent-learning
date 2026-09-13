# Custom PHPStan Rules and Extensions

This directory contains reference implementations of custom PHPStan rules, AST inspection precedents, test fixtures, and analyzer registration.

## Structural Anatomy of a Custom PHPStan Rule

A modern, robust PHPStan rule should implement `PHPStan\Rules\Rule<NodeType>`:

```php
<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\Expr\StaticCall>
 */
final class ExampleRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\StaticCall::class;
    }

    /**
     * @param Node\Expr\StaticCall $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // 1. Fast boundary return for unrelated nodes
        if (!$this->isCandidate($node)) {
            return [];
        }

        // 2. Resolve types, reflection, or argument positions
        // ...

        // 3. Emit structured error with stable identifier and line number
        return [
            RuleErrorBuilder::message('Clear human explanation.')
                ->identifier('category.ruleIdentifier')
                ->line($node->getLine())
                ->build(),
        ];
    }
}
```

### Core Invariants

1. **Always assign a stable identifier**: Use `->identifier('rule.id')`. This allows ignoring or testing errors deterministically.
2. **Avoid false positives**: Return early if the node uses unpacked arguments (`$arg->unpack`), dynamic expressions, or matches an allowed boundary.
3. **Prefer Type methods over instanceof**: Use `$scope->getType($expr)->getObjectClassNames()` rather than deprecated type instanceof checks.
4. **Fixture coverage**: Provide `valid.php`, `invalid.php`, `boundary.php`, and `false-positive.php`.

---

## Rules vs. Extensions: When to Build Which

Not every static analysis concern is a rule violation. Often the real problem is that PHPStan does not understand the precise return type of a custom helper or framework method, leading developers to cargo-cult inline `@var` casts or downgrade strict contracts.

| Category | PHPStan Interface | Purpose | Example |
| -------- | ----------------- | ------- | ------- |
| **Custom Rule** | `PHPStan\Rules\Rule` | Enforce invariant by reporting violations on forbidden AST nodes. | `AppTranslationParametersRule`, `NoHardcodedHostPathRule` |
| **Dynamic Return Type** | `DynamicFunctionReturnTypeExtension`<br>`DynamicMethodReturnTypeExtension` | Inform PHPStan of exact return type based on passed arguments. | `ArrayLastDynamicReturnTypeExtension` (returns exact last element type instead of `mixed`) |
| **Type Specifying** | `FunctionTypeSpecifyingExtension`<br>`MethodTypeSpecifyingExtension` | Narrow argument types in conditional branches (truthy/falsy). | `StrContainingTypeSpecifyingExtension` (narrows haystack to `non-empty-string` inside `if (str_contains(...))`) |

### Structural Anatomy of a Dynamic Return Type Extension

```php
<?php

declare(strict_types=1);

namespace App\PHPStan\Extensions;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class CustomArrayHelperExtension implements DynamicFunctionReturnTypeExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'my_array_helper';
    }

    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        $args = $functionCall->getArgs();
        if (!isset($args[0])) {
            return new \PHPStan\Type\MixedType();
        }

        $argType = $scope->getType($args[0]->value);

        // Constant arrays: precise element type
        $constantArrays = $argType->getConstantArrays();
        if (count($constantArrays) > 0) {
            $types = [];
            foreach ($constantArrays as $ca) {
                $valTypes = $ca->getValueTypes();
                if (count($valTypes) > 0) {
                    $types[] = end($valTypes);
                }
            }
            return TypeCombinator::union(...$types);
        }

        return $argType->getIterableValueType();
    }
}
```

---

## PHPStan Precision Guidelines

1. **PHPDoc is a precision layer, not decoration**:
   - Prefer native PHP 8.3+ types on properties, parameters, and return types.
   - Use PHPDoc (`@param`, `@return`, `@var`) only when native PHP cannot express the needed shape (e.g. `list<T>`, `array{id: int, label: non-empty-string}`, generics, or conditional types).

2. **Preserve intentional strict contracts**:
   - Contracts like `literal-string`, `class-string<T>`, and `non-empty-string` prevent injection and runtime crashes.
   - When PHPStan reports a proof gap on a strict contract, **do not weaken the contract to `string` or `mixed`**. Fix the proof gap: narrow inputs earlier, add a typed assertion/adapter, or build a dynamic return type extension.

3. **Avoid cargo-cult inline casts**:
   - Anti-pattern: `/** @var EnumType $x */ $x = (int)$row->property;` over a property that is already typed. A scalar cast widens precise types, and the inline `@var` re-narrows it. Read the property directly without the cast.
   - When removing redundant casts, check whether strict comparison (`===`) on untyped properties triggers warnings; fix the root cause by declaring the native type on the property.

4. **Contain legacy boundaries**:
   - When communicating with legacy untyped arrays or external APIs, define explicit array shapes at the boundary and validate/adapt immediately so `mixed` does not leak into domain code.

