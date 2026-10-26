<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SiteDateBoundaryConverter
{
    public function __construct(private readonly Clock $siteClock)
    {
    }

    public function startUtc(?string $value): ?string
    {
        return $this->boundaryUtc($value, false);
    }

    public function endUtc(?string $value): ?string
    {
        return $this->boundaryUtc($value, true);
    }

    private function boundaryUtc(?string $value, bool $end): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            $this->siteClock->now()->getTimezone()
        );
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('날짜는 Y-m-d 형식이어야 합니다.');
        }
        $date = $date->setTime($end ? 23 : 0, $end ? 59 : 0, $end ? 59 : 0);

        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
