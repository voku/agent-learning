<?php

declare(strict_types=1);

// Sample before running ForbiddenNativeStringFunctionFixer and NoLeadingSlashInGlobalNamespaceFixer

$time = new \DateTime();

if (str_starts_with($haystack, 'prefix')) {
    echo 'starts with';
}

if (str_contains($haystack, 'needle')) {
    echo 'contains';
}

if (str_ends_with($haystack, 'suffix')) {
    echo 'ends with';
}
