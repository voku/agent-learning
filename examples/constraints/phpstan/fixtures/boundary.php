<?php

declare(strict_types=1);

namespace App\Fixtures;

final class BoundaryCodeSample
{
    /**
     * Allowed boundary: Unpacked arguments cannot be statically counted;
     * the rule must return early without false violations.
     *
     * @param list<string> $extraArgs
     */
    public function translationWithUnpackedArgs(array $extraArgs): void
    {
        AppI18n::_('Item: %s', ...$extraArgs);
    }

    /**
     * Allowed boundary: Dynamic translation key not known at static analysis time.
     */
    public function translationWithDynamicFormat(string $dynamicKey, string $value): void
    {
        AppI18n::_($dynamicKey, $value);
    }

    /**
     * Allowed boundary: Escaped percent signs (%%) are not format placeholders.
     */
    public function translationWithEscapedPercents(): void
    {
        // %% is an escaped percent literal, so there are 0 placeholders and 0 values given -> valid
        AppI18n::_('100%% complete');
    }
}
