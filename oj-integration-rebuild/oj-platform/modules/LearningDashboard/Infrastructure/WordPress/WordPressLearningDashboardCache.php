<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearningDashboardCache;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\LearningDashboardSnapshot;

/**
 * Short-lived per-user object cache for the learning dashboard snapshot, so
 * repeat page loads within the TTL don't re-walk the submission history.
 */
final class WordPressLearningDashboardCache implements LearningDashboardCache
{
    private const GROUP = 'oj-platform-learning-dashboard';

    /**
     * @var array<string, array{expires: int, snapshot: LearningDashboardSnapshot}>
     */
    private static array $fallback = [];

    public function get(string $key): ?LearningDashboardSnapshot
    {
        if (function_exists('wp_cache_get')) {
            $found = false;
            $value = wp_cache_get($key, self::GROUP, false, $found);
            if ($found && $value instanceof LearningDashboardSnapshot) {
                return $value;
            }
        }
        $entry = self::$fallback[$key] ?? null;
        if ($entry === null || $entry['expires'] < time()) {
            unset(self::$fallback[$key]);

            return null;
        }

        return $entry['snapshot'];
    }

    public function put(
        string $key,
        LearningDashboardSnapshot $snapshot,
        int $ttlSeconds
    ): void {
        $ttlSeconds = max(1, $ttlSeconds);
        if (function_exists('wp_cache_set')) {
            wp_cache_set($key, $snapshot, self::GROUP, $ttlSeconds);
        }
        self::$fallback[$key] = [
            'expires' => time() + $ttlSeconds,
            'snapshot' => $snapshot,
        ];
    }
}
