<?php

declare(strict_types=1);

final class StringHelper
{
    public static function str_contains(string $haystack, string $needle): bool
    {
        return $haystack !== '' && $needle !== '';
    }
}

StringHelper::str_contains('haystack', 'needle');
