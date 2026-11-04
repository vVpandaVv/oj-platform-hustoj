<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiterStorageLock;
use InvalidArgumentException;
use RuntimeException;

final class WordPressAttemptLimiter implements AttemptLimiter, \CodingPanda\OjPlatform\Modules\Auth\Contract\FixedWindowAttemptLimiter
{
    private const INDEX_OPTION = 'oj_platform_rate_index';

    private readonly AttemptLimiterStorageLock $storageLock;

    public function __construct(
        private readonly \CodingPanda\OjPlatform\Shared\Clock\Clock $clock = new \CodingPanda\OjPlatform\Shared\Clock\SystemClock(),
        ?AttemptLimiterStorageLock $storageLock = null
    ) {
        $this->storageLock = $storageLock ?? new WordPressAttemptLimiterStorageLock();
    }

    public function consumeFixedWindow(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope) !== 1 || $identifier === '' || $limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('요청 횟수 제한값이 올바르지 않습니다.');
        }
        $key = 'oj_platform_rate_' . hash_hmac('sha256', "fixed\0" . $scope . "\0" . $identifier,
            function_exists('wp_salt') ? wp_salt('nonce') : 'oj-platform-rate');
        return $this->storageLock->synchronized(function () use ($key, $limit, $windowSeconds): bool {
            $now = $this->clock->now()->getTimestamp();
            $value = $this->readCounter($key, $windowSeconds, true);
            $resetAt = is_array($value) ? (int) ($value['reset_at'] ?? 0) : 0;
            $count = $resetAt > $now ? max(0, (int) ($value['count'] ?? 0)) : 0;
            if ($count >= $limit) { $this->trackKey($key); return false; }
            if ($resetAt <= $now) { $resetAt = $now + $windowSeconds; }
            $this->writeCounter($key, ['count' => $count + 1, 'reset_at' => $resetAt], max(1, $resetAt - $now));
            $this->trackKey($key);
            return true;
        });
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
        return $this->storageLock->synchronized(function () use ($key, $limit, $windowSeconds): bool {
            $value = $this->readCounter($key, $windowSeconds, false);
            $count = is_array($value) ? max(0, (int) ($value['count'] ?? 0)) : 0;
            if ($count >= $limit) { $this->trackKey($key); return false; }
            $this->writeCounter($key, ['count' => $count + 1], $windowSeconds);
            $this->trackKey($key);
            return true;
        });
    }

    public function resetAll(): int
    {
        return $this->storageLock->synchronized(function (): int {
            $this->freshOptionCache();
            $index = $this->read(static fn () => get_option(self::INDEX_OPTION));
            $index = is_array($index) ? $index : [];
            $cleared = 0;
            foreach ($index as $key) {
                if (is_string($key) && delete_transient($key)) { ++$cleared; }
            }
            delete_option(self::INDEX_OPTION);
            $this->freshOptionCache();
            if ($this->read(static fn () => get_option(self::INDEX_OPTION)) !== false) { throw $this->storageFailure(); }
            return $cleared;
        });
    }

    /** @param string $key already-namespaced transient key, never the raw scope/identifier */
    private function trackKey(string $key): void
    {
        $this->freshOptionCache();
        $index = $this->read(static fn () => get_option(self::INDEX_OPTION));
        $index = is_array($index) ? $index : [];

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            update_option(self::INDEX_OPTION, $index, false);
            $this->freshOptionCache();
            if ($this->read(static fn () => get_option(self::INDEX_OPTION)) !== $index) { throw $this->storageFailure(); }
        }
    }

    private function readCounter(string $key, int $windowSeconds, bool $fixed): mixed
    {
        $this->freshOptionCache($key);
        $value = $this->read(static fn () => get_transient($key));
        if (! is_array($value) || $this->externalCache()) { return $value; }
        $timeout = $this->read(static fn () => get_option('_transient_timeout_' . $key));
        if (is_numeric($timeout) && (int) $timeout > time()) { return $value; }
        // Older WordPress set_transient() can report a value write as success
        // after its separate timeout INSERT failed. Repair only that row:
        // deleting/recreating the transient could lose an already-full budget.
        $ttl = $windowSeconds;
        $resetAt = $fixed ? (int) ($value['reset_at'] ?? 0) : 0;
        if ($resetAt > $this->clock->now()->getTimestamp()) {
            $ttl = max(1, min($ttl, $resetAt - $this->clock->now()->getTimestamp()));
        }
        $deadline = time() + $ttl;
        if ($timeout === false) { add_option('_transient_timeout_' . $key, $deadline, '', false); }
        else { update_option('_transient_timeout_' . $key, $deadline, false); }
        $this->freshOptionCache($key);
        if (! $this->validTimeout($key, $ttl)) { throw $this->storageFailure(); }
        return $value;
    }

    /** WordPress may return false for an unchanged value, or true without TTL. */
    private function writeCounter(string $key, array $value, int $ttl): void
    {
        set_transient($key, $value, $ttl);
        $this->freshOptionCache($key);
        $stored = $this->read(static fn () => get_transient($key));
        if (! is_array($stored) || (int) ($stored['count'] ?? 0) < $value['count']
            || (isset($value['reset_at']) && ($stored['reset_at'] ?? null) !== $value['reset_at'])
            || (! $this->externalCache() && ! $this->validTimeout($key, $ttl))) {
            throw $this->storageFailure();
        }
    }

    private function validTimeout(string $key, int $ttl): bool
    {
        $timeout = $this->read(static fn () => get_option('_transient_timeout_' . $key));
        return is_numeric($timeout) && (int) $timeout > time() && (int) $timeout <= time() + $ttl + 1;
    }

    private function freshOptionCache(?string $key = null): void
    {
        if (! function_exists('wp_cache_delete')) { return; }
        wp_cache_delete(self::INDEX_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($key !== null && ! $this->externalCache()) {
            wp_cache_delete('_transient_' . $key, 'options');
            wp_cache_delete('_transient_timeout_' . $key, 'options');
        }
    }

    private function externalCache(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    private function read(callable $operation): mixed
    {
        $database = $GLOBALS['wpdb'] ?? null;
        if (is_object($database)) { $database->last_error = ''; }
        $value = $operation();
        // A database error is not a missing counter. In particular, never
        // replace an existing full budget with count=1 after a failed read.
        if (is_object($database) && ($database->last_error ?? '') !== '') { throw $this->storageFailure(); }
        return $value;
    }

    private function storageFailure(): RuntimeException
    {
        return new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.');
    }
}
