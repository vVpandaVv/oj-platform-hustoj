<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SettingsManager
{
    public function read(string $key, mixed $default = null): SettingsSnapshot;

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot;
}
