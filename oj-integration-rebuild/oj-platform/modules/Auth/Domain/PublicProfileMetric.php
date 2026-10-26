<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class PublicProfileMetric
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $value
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->key) !== 1
            || trim($this->label) === ''
            || trim($this->value) === ''
        ) {
            throw new InvalidArgumentException('프로필 지표가 올바르지 않습니다.');
        }
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function value(): string { return $this->value; }
}
