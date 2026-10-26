<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum VisualGradingMode: string
{
    case None = 'none';
    case Matplotlib = 'matplotlib';
    case Turtle = 'turtle';

    public function isEnabled(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => '사용 안 함',
            self::Matplotlib => 'Matplotlib 그래프 이미지 채점',
            self::Turtle => 'turtle 그림 이미지 채점',
        };
    }

    public static function fromValue(string $value): self
    {
        return self::tryFrom(trim($value)) ?? self::None;
    }
}
