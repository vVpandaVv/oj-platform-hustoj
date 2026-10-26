<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class WordPressClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        if (function_exists('current_datetime')) {
            $current = current_datetime();
            if ($current instanceof DateTimeImmutable) {
                return $current;
            }
        }
        $timezone = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone('UTC');

        return new DateTimeImmutable('now', $timezone);
    }
}
