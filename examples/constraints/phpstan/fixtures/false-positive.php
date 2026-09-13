<?php

declare(strict_types=1);

namespace App\Fixtures;

final class OtherI18nClass
{
    public static function _(string $format, mixed ...$args): string
    {
        return $format;
    }
}

final class FalsePositiveGuards
{
    /**
     * Guard: Static call to a different class with identical method name `_`
     * must NOT trigger AppTranslationParametersRule.
     */
    public function callsDifferentClass(): void
    {
        OtherI18nClass::_('Hello %s');
    }

    /**
     * Guard: Instance method named `_` must NOT trigger static call rule.
     */
    public function callsInstanceMethod(object $renderer): void
    {
        if (method_exists($renderer, '_')) {
            $renderer->_('Hello %s');
        }
    }

    /**
     * Guard: URL or filesystem path containing "/home" without matching
     * the private developer directory pattern should NOT be blocked.
     */
    public function safePublicPath(): string
    {
        return 'https://example.com/home/overview';
    }
}
