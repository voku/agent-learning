# PHPStan Precision Map & Anti-Patterns

This reference maps common static analysis precision problems to clean architectural solutions.

## Anti-Patterns and Better Alternatives

### 1. Cargo-Cult Cast + Inline `@var` over Typed Property
- **Problem**: `/** @var EnumType $id */ $id = (int)$model->property;` when `$model->property` is already typed. The `(int)` cast widens the specific const/enum type to a generic `int`, and the `@var` re-narrows it.
- **Better**: Assign directly: `$id = $model->property;`. Keep explicit conversion only for genuinely untyped sources (e.g. raw query parameters, HTTP headers, generic array lookups).

### 2. Weakening Strict Contracts to Bypass Proof Gaps
- **Problem**: A method requires `class-string<Service>` or `literal-string`, but a caller passes a dynamically computed `string`. Instead of proving the string is safe, the parameter is relaxed to `string`.
- **Better**: Preserve the strict contract. Narrow the value at the boundary using an assertion (e.g. `is_subclass_of($class, Service::class)`), an explicit enum/map lookup, or a typed value object.

### 3. Inline `@var` Peppering on Reusable Helper Functions
- **Problem**: Callers of `array_last($items)` or `$db->fetchOne()` repeatedly annotate call sites with `/** @var User $user */`.
- **Better**: Implement a `PHPStan\Type\DynamicFunctionReturnTypeExtension` or `DynamicMethodReturnTypeExtension`. Once registered, PHPStan infers the exact return type across every call site automatically.

### 4. Vague `array` and Leaky `mixed`
- **Problem**: Passing untyped `array` across application layers leads to cascading `Cannot access offset ... on mixed` errors.
- **Better**: Define structured array shapes (`array{id: int, status: string, count: int}`) or `list<T>` at the boundary where data enters the system.

### 5. Stripping Casts Without Checking Strict Comparison Nullability
- **Problem**: Removing `(int)` in `$model->id === $targetId` can expose comparison type mismatch if `$model->id` is an untyped property or nullable.
- **Better**: Ensure the model property has an explicit native scalar type matching the database column nullability (`public int $id;`).

---

## Targeted Validation Workflow

1. Check PHP syntax:
   ```bash
   php -l path/to/ChangedFile.php
   ```
2. Run targeted PHPStan analysis:
   ```bash
   vendor/bin/phpstan analyse path/to/ChangedFile.php --no-progress
   ```
3. Run the closest unit tests to prove runtime behavior is intact:
   ```bash
   vendor/bin/phpunit tests/Path/To/Test.php
   ```
