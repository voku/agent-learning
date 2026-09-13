# Custom PHP-CS-Fixer Rules and Extensions

This directory contains reference implementations of custom PHP-CS-Fixer fixers, token stream inspection patterns, fixtures, and configuration.

## Structural Anatomy of a Custom Fixer

A custom fixer extends `PhpCsFixer\AbstractFixer`:

```php
<?php

declare(strict_types=1);

namespace App\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

final class ExampleFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Brief description of the transformation.',
            [new CodeSample("<?php\nold_code();\n")]
        );
    }

    public function getName(): string
    {
        return 'App/example_fixer';
    }

    public function isCandidate(Tokens $tokens): bool
    {
        // Fast pre-check before iterating
        return $tokens->isTokenKindFound(\T_STRING);
    }

    public function isRisky(): bool
    {
        return false;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            // Check tokens and mutate safely
        }
    }
}
```

### Core Invariants

1. **Fast candidacy**: `isCandidate(Tokens $tokens)` should check `isTokenKindFound(...)` or similar fast lookups so files without candidate tokens are skipped instantly.
2. **Reverse traversal when deleting/inserting**: When removing or adding tokens, iterate backwards (`for ($index = $tokens->count() - 1; $index >= 0; --$index)`) to prevent index shifting bugs.
3. **Idempotency**: Running the fixer twice must produce identical output to running it once (`applyFix` must be idempotent).
4. **Boundary and false-positive checks**: Ensure methods on objects (e.g. `$obj->str_starts_with(...)`) are not mistaken for global function calls.
