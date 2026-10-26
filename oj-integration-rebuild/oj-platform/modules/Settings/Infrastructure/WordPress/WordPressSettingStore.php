<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;

final class WordPressSettingStore implements SettingStore
{
    public function read(string $optionName, mixed $default = null): mixed
    {
        return function_exists('get_option') ? get_option($optionName, $default) : $default;
    }

    public function write(string $optionName, mixed $value): bool
    {
        return function_exists('update_option') && update_option($optionName, $value, false);
    }
}
