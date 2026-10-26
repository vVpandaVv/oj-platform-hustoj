<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use InvalidArgumentException;
use RuntimeException;

final class WordPressAttemptLimiter implements AttemptLimiter, \CodingPanda\OjPlatform\Modules\Auth\Contract\FixedWindowAttemptLimiter
{
    private const INDEX_OPTION = 'oj_platform_rate_index';

    public function __construct(private readonly \CodingPanda\OjPlatform\Shared\Clock\Clock $clock = new \CodingPanda\OjPlatform\Shared\Clock\SystemClock()) {}

    public function consumeFixedWindow(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope) !== 1 || $identifier === '' || $limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('요청 횟수 제한값이 올바르지 않습니다.');
        }
        $key = 'oj_platform_rate_' . hash_hmac('sha256', "fixed\0" . $scope . "\0" . $identifier,
            function_exists('wp_salt') ? wp_salt('nonce') : 'oj-platform-rate');
        $now = $this->clock->now()->getTimestamp();
        $value = get_transient($key);
        $resetAt = is_array($value) ? (int) ($value['reset_at'] ?? 0) : 0;
        $count = $resetAt > $now ? max(0, (int) ($value['count'] ?? 0)) : 0;
        if ($count >= $limit) { return false; }
        if ($resetAt <= $now) { $resetAt = $now + $windowSeconds; }
        $next = ['count' => $count + 1, 'reset_at' => $resetAt];
        if (! set_transient($key, $next, max(1, $resetAt - $now))) {
            $stored = get_transient($key);
            if (! is_array($stored) || (int) ($stored['count'] ?? 0) < $count + 1 || (int) ($stored['reset_at'] ?? 0) !== $resetAt) {
                throw new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.');
            }
        }
        $this->trackKey($key);
        return true;
    }

    public function consume(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): bool {
        if (
            preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope) !== 1
            || $identifier === ''
            || $limit < 1
            || $windowSeconds < 1
        ) {
            throw new InvalidArgumentException('요청 횟수 제한값이 올바르지 않습니다.');
        }

        $key = 'oj_platform_rate_' . hash_hmac(
            'sha256',
            $scope . "\0" . $identifier,
            function_exists('wp_salt') ? wp_salt('nonce') : 'oj-platform-rate'
        );
        $value = get_transient($key);
        $count = is_array($value) ? (int) ($value['count'] ?? 0) : 0;
        if ($count >= $limit) {
            return false;
        }

        if (! set_transient(
            $key,
            ['count' => $count + 1],
            $windowSeconds
        )) {
            // WordPress can report no change when a concurrent request saved the same value.
            $stored = get_transient($key);
            if (! is_array($stored) || (int) ($stored['count'] ?? 0) < $count + 1) {
                throw new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.');
            }
        }

        $this->trackKey($key);

        return true;
    }

    public function resetAll(): int
    {
        $index = get_option(self::INDEX_OPTION);
        $index = is_array($index) ? $index : [];
        $cleared = 0;

        foreach ($index as $key) {
            if (is_string($key) && delete_transient($key)) {
                ++$cleared;
            }
        }

        delete_option(self::INDEX_OPTION);

        return $cleared;
    }

    /** @param string $key already-namespaced transient key, never the raw scope/identifier */
    private function trackKey(string $key): void
    {
        $index = get_option(self::INDEX_OPTION);
        $index = is_array($index) ? $index : [];

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            update_option(self::INDEX_OPTION, $index, false);
        }
    }
}
