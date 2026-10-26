<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class SiteDateFormatter
{
    /**
     * DB에 저장된 UTC 'Y-m-d H:i:s' 문자열을 사이트에 설정된 시간대로 변환해 표시합니다.
     */
    public function format(?string $utcDateTime, string $format = 'Y-m-d H:i'): string
    {
        $utcDateTime = trim((string) $utcDateTime);
        if ($utcDateTime === '') {
            return '-';
        }
        $date = $this->parseUtc($utcDateTime);
        if ($date === null) {
            return $utcDateTime;
        }

        return $date->setTimezone($this->timezone())->format($format);
    }

    public function formatTimestamp(
        int|false|null $timestamp,
        string $format = 'Y-m-d H:i'
    ): string {
        if (! is_int($timestamp) || $timestamp < 1) {
            return '-';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->timezone())
            ->format($format);
    }

    public function export(?string $utcDateTime): string
    {
        return trim((string) $utcDateTime) === ''
            ? ''
            : $this->format($utcDateTime, 'Y-m-d\TH:i:sP');
    }

    public function timezoneLabel(?string $utcDateTime = null): string
    {
        $timezone = $this->timezone();
        $date = $utcDateTime === null ? null : $this->parseUtc($utcDateTime);
        $date = $date?->setTimezone($timezone) ?? new DateTimeImmutable('now', $timezone);

        return $timezone->getName() . ' (UTC' . $date->format('P') . ')';
    }

    private function parseUtc(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i:s.u'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $utc);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                $date instanceof DateTimeImmutable
                && (
                    $errors === false
                    || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)
                )
            ) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value, $utc);
        } catch (Throwable) {
            return null;
        }
    }

    private function timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
            if ($timezone instanceof DateTimeZone) {
                return $timezone;
            }
        }

        return new DateTimeZone('UTC');
    }
}
