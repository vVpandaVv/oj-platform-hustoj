<?php
declare(strict_types=1);
namespace OjMicrobit\Domain;

use InvalidArgumentException;

final class SignalSchema
{
    public const OUTPUT_SIGNALS = ['digital', 'pwm', 'servo', 'servo_pulse'];

    public static function range(string $signal): array
    {
        return match ($signal) {
            'digital' => [0, 1],
            'analog', 'pwm' => [0, 1023],
            'servo' => [0, 180],
            'servo_pulse' => [1, 20000],
            default => throw new InvalidArgumentException('지원하지 않는 신호 종류입니다.'),
        };
    }
}
