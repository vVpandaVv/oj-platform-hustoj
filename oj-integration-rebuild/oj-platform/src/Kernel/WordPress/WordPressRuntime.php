<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

final class WordPressRuntime
{
    public function version(): string
    {
        return (string) ($GLOBALS['wp_version'] ?? '0');
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }
}
