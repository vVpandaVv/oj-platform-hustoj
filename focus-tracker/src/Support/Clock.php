<?php
declare(strict_types=1);

namespace FocusTracker\Support;

/**
 * Millisecond-epoch <-> MySQL datetime(3) conversions. Every table stores
 * both forms (see docs/DESIGN.md §4): the epoch-ms column is what
 * Scoring\Score_Calculator consumes directly (no float/timezone parsing),
 * the datetime(3) column stays for admin-facing queries and date-range
 * filtering (e.g. Jobs\Retention_Cleanup).
 */
final class Clock {
    public static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }

    public static function ms_to_mysql(int $ms): string {
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;

        if ($millis < 0) {
            $millis += 1000;
            $seconds -= 1;
        }

        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis);
    }

    public static function mysql_to_ms(string $mysql_datetime): int {
        $parts = explode('.', $mysql_datetime, 2);
        $seconds = strtotime($parts[0] . ' UTC');
        if (false === $seconds) {
            return 0;
        }

        $millis = isset($parts[1]) ? (int) str_pad(substr($parts[1], 0, 3), 3, '0') : 0;

        return $seconds * 1000 + $millis;
    }
}
