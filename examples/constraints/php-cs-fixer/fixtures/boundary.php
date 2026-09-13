<?php

declare(strict_types=1);

namespace App\Fixtures;

final class BoundaryFixerCodeSample
{
    /**
     * Boundary: Namespaced classes retain leading slashes when referring
     * to the global root namespace.
     */
    public function createsGlobalFromNamespace(): \DateTime
    {
        return new \DateTime();
    }

    /**
     * Boundary: Method calls with matching names must NOT be rewritten.
     */
    public function callsMethod(object $service, string $haystack, string $needle): bool
    {
        return $service->str_starts_with($haystack, $needle);
    }
}
