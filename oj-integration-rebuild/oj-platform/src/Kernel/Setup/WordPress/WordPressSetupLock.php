<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup\WordPress;

use CodingPanda\OjPlatform\Kernel\Setup\SetupLock;

final class WordPressSetupLock implements SetupLock
{
    private const OPTION_NAME = 'oj_platform_setup_lock';

    public function acquire(string $token, int $ttlSeconds): bool
    {
        if (
            ! function_exists('add_option')
            || ! function_exists('get_option')
            || ! function_exists('delete_option')
        ) {
            return false;
        }

        $value = [
            'token' => $token,
            'expires_at' => time() + max(30, $ttlSeconds),
        ];

        if (add_option(self::OPTION_NAME, $value, '', false)) {
            return true;
        }

        $current = get_option(self::OPTION_NAME, null);
        if (
            ! is_array($current)
            || (int) ($current['expires_at'] ?? 0) >= time()
        ) {
            return false;
        }

        delete_option(self::OPTION_NAME);

        return add_option(self::OPTION_NAME, $value, '', false);
    }

    public function release(string $token): void
    {
        if (! function_exists('get_option') || ! function_exists('delete_option')) {
            return;
        }

        $current = get_option(self::OPTION_NAME, null);
        if (
            is_array($current)
            && isset($current['token'])
            && hash_equals((string) $current['token'], $token)
        ) {
            delete_option(self::OPTION_NAME);
        }
    }
}
