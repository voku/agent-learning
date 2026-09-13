<?php

declare(strict_types=1);

namespace App\Fixtures;

final class InvalidCodeSample
{
    public function translationPlaceholderMismatch(): void
    {
        // ERROR: project.translation.parameters (1 placeholder, 0 values given)
        AppI18n::_('Hello %s');

        // ERROR: project.translation.parameters (1 placeholder, 2 values given)
        AppI18n::_('Hello %s', 'Alice', 'Bob');
    }

    public function hardcodedDeveloperPath(): string
    {
        // ERROR: project.noHardcodedHostPath
        return '/home/developer/Projects/app/bin/tool';
    }

    public function switchWithoutDefault(int $x): string
    {
        // ERROR: project.switchMustContainDefault
        switch ($x) {
            case 1:
                return 'one';
            case 2:
                return 'two';
        }

        return 'other';
    }
}
