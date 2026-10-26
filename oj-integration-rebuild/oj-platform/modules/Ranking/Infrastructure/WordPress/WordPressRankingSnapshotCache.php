<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingSnapshotCache;

final class WordPressRankingSnapshotCache implements RankingSnapshotCache, \CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingRefreshCache
{
    /**
     * @var array<string, array{expires: int, rows: list<array<string, mixed>>}>
     */
    private static array $fallback = [];

    public function get(string $key): ?array
    {
        if (function_exists('wp_cache_get')) {
            $found = false;
            $value = wp_cache_get(
                $key,
                'oj-platform-ranking',
                false,
                $found
            );
            if ($found && is_array($value)) {
                return array_values($value);
            }
        }
        if (function_exists('get_transient')) {
            $value = get_transient($this->transientKey($key));
            if (is_array($value)) {
                $rows = array_values($value);
                if (function_exists('wp_cache_set')) {
                    wp_cache_set(
                        $key,
                        $rows,
                        'oj-platform-ranking',
                        60
                    );
                }

                return $rows;
            }
        }
        $entry = self::$fallback[$key] ?? null;
        if ($entry === null || $entry['expires'] < time()) {
            unset(self::$fallback[$key]);

            return null;
        }

        return $entry['rows'];
    }

    public function put(string $key, array $rows, int $ttlSeconds): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $rows = array_values($rows);
        if (function_exists('wp_cache_set')) {
            wp_cache_set(
                $key,
                $rows,
                'oj-platform-ranking',
                $ttlSeconds
            );
        }
        if (function_exists('set_transient')) {
            set_transient($this->transientKey($key . ':stale'), $rows, max(300, $ttlSeconds));
            set_transient(
                $this->transientKey($key),
                $rows,
                $ttlSeconds
            );
        }
        self::$fallback[$key] = [
            'expires' => time() + $ttlSeconds,
            'rows' => $rows,
        ];
    }

    private function transientKey(string $key): string
    {
        return 'oj_platform_ranking_' . hash('sha256', $key);
    }

    public function acquireRefresh(string $key): bool
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (! is_object($db) || ! method_exists($db, 'get_var') || ! method_exists($db, 'prepare')) { return true; }
        return (string) $db->get_var($db->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName($key))) === '1';
    }

    public function releaseRefresh(string $key): void
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (is_object($db) && method_exists($db, 'get_var') && method_exists($db, 'prepare')) {
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName($key)));
        }
    }

    public function stale(string $key): ?array
    {
        $value = function_exists('get_transient') ? get_transient($this->transientKey($key . ':stale')) : false;
        return is_array($value) ? array_values($value) : null;
    }

    private function lockName(string $key): string
    {
        return 'oj_rank_' . substr(hash('sha256', (string) ($GLOBALS['wpdb']->options ?? '') . ':' . $key), 0, 48);
    }
}
