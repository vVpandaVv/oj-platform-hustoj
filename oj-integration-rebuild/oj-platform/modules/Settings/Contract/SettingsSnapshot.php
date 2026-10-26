<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

final class SettingsSnapshot
{
    public function __construct(
        private readonly int $revision,
        private readonly mixed $value
    ) {
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
