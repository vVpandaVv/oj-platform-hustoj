<?php
declare(strict_types=1);

namespace EduGames\Support;

/**
 * The calendar day for daily challenges, in the site's time zone
 * (Settings → General). With gmdate() a school in Korea got a new "today"
 * at 09:00 KST — in the middle of first period. Falls back to UTC where
 * WordPress' date functions are not loaded (unit tests).
 */
final class Clock {
    public static function today(): string {
        if (function_exists('wp_date')) {
            $day = wp_date('Y-m-d');
            if (is_string($day) && '' !== $day) {
                return $day;
            }
        }
        return gmdate('Y-m-d');
    }
}
