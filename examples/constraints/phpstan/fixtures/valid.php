<?php

declare(strict_types=1);

namespace App\Fixtures;

final class AppI18n
{
    public static function _(string $format, mixed ...$args): string
    {
        return sprintf($format, ...$args);
    }
}

final class ValidCodeSample
{
    public function translationMatchingPlaceholders(): void
    {
        // 1 placeholder, 1 value given -> valid
        AppI18n::_('Hello %s', 'World');

        // 2 placeholders, 2 values given -> valid
        AppI18n::_('User %s has %d items', 'Alice', 5);
    }

    public function relativePathOnly(): string
    {
        // Relative and vendor paths are valid
        return 'vendor/bin/phpstan';
    }

    public function switchWithDefault(int $status): string
    {
        switch ($status) {
            case 1:
                return 'pending';
            case 2:
                return 'completed';
            default:
                return 'unknown';
        }
    }
}
