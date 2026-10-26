<?php
declare(strict_types=1);

namespace FocusTracker\Support;

use InvalidArgumentException;

/**
 * Single option (`focus_tracker_settings`) holding every site-level default
 * this addon needs, so Admin\Settings_Page has one thing to write and every
 * consumer (Tracking\Tracked_Page_Enqueuer, Jobs\Retention_Cleanup,
 * Scoring\Activity_Score_Service) has one thing to read. Individual
 * activities may still override the three weights per docs/DESIGN.md §4.
 */
final class Settings {
    private const OPTION = 'focus_tracker_settings';

    /** @return array<string,mixed> */
    public static function all(): array {
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        // Existing sites used one period for both raw events and checks.
        if (! array_key_exists('check_retention_days', $stored)) {
            $stored['check_retention_days'] = max(1, (int) ($stored['retention_days'] ?? 60));
        }
        return array_merge(self::defaults(), $stored);
    }

    public static function get(string $key) {
        $all = self::all();

        return $all[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public static function update(array $values): void {
        $current = self::all();
        $merged = array_merge($current, array_intersect_key($values, self::defaults()));
        $error = self::retention_error($merged);
        if ($error !== null) { throw new InvalidArgumentException($error); }
        foreach (array('retention_days', 'check_retention_days', 'aggregate_retention_days') as $key) { $merged[$key] = (int) $merged[$key]; }
        $saved = update_option(self::OPTION, $merged, false);
        if (! $saved && get_option(self::OPTION, array()) !== $merged) { throw new \RuntimeException('설정을 저장하지 못했습니다. 저장소 상태를 확인하고 다시 시도해 주세요.'); }
        $changed = array_keys(array_filter($merged, static fn ($value, $key): bool => ! array_key_exists($key, $current) || $current[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== array()) { Audit_Log::record('settings', 0, $changed); }
    }

    /** A finite summary period must outlive both sources by a full local day. */
    public static function retention_error(array $values): ?string {
        foreach (array('retention_days' => 1, 'check_retention_days' => 1, 'aggregate_retention_days' => 0) as $key => $minimum) {
            if (false === filter_var($values[$key] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => $minimum, 'max_range' => 36500)))) {
                return '보관 기간은 원시 기록·확인 문항 1~36500일, 집계 0~36500일의 정수로 입력해 주세요.';
            }
        }
        $aggregate = (int) $values['aggregate_retention_days'];
        if ($aggregate !== 0 && $aggregate <= max((int) $values['retention_days'], (int) $values['check_retention_days'])) {
            return '집계 보관 기간은 원시 기록·확인 문항 보관 기간보다 최소 1일 길게 지정하거나 0(무제한)으로 두세요.';
        }
        return null;
    }

    /** Complete local dates before this boundary have expired summaries. */
    public static function aggregate_cutoff(?int $now_ms = null): ?\DateTimeImmutable {
        $days = (int) self::get('aggregate_retention_days');
        if ($days <= 0) { return null; }
        return (new \DateTimeImmutable('@' . intdiv($now_ms ?? Clock::now_ms(), 1000)))->setTimezone(wp_timezone())->setTime(0, 0)->modify('-' . $days . ' days');
    }

    /** @return array<string,mixed> */
    public static function defaults(): array {
        return array(
            'weight_focus' => 8.0,
            'weight_selfreg' => 6.0,
            'weight_activity' => 6.0,
            'retention_days' => 60,
            'check_retention_days' => 60,
            'aggregate_retention_days' => 0,
            'tracked_url_patterns' => array('#^/(problems|submit)(/|$)#'),
            'activity_window_seconds' => 30,
            'checks_enabled' => true,
            'check_cooldown_seconds' => 300,
            'check_daily_limit' => 3,
        );
    }
}
