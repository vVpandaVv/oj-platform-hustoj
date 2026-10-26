<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

interface LoginThrottleControl
{
    public function snapshot(): SettingsSnapshot;

    public function save(bool $enabled, int $expectedRevision): void;

    public function reset(int $expectedRevision): void;
}
