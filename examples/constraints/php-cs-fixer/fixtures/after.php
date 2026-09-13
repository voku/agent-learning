<?php

declare(strict_types=1);

// Sample after running ForbiddenNativeStringFunctionFixer and NoLeadingSlashInGlobalNamespaceFixer

$time = new DateTime();

if (app_str_starts_with($haystack, 'prefix')) {
    echo 'starts with';
}

if (app_str_contains($haystack, 'needle')) {
    echo 'contains';
}

if (app_str_ends_with($haystack, 'suffix')) {
    echo 'ends with';
}
