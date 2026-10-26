<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SettingStore
{
    public function read(string $optionName, mixed $default = null): mixed;

    public function write(string $optionName, mixed $value): bool;
}
