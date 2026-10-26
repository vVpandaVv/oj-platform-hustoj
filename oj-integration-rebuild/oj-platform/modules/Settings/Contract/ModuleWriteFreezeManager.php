<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface ModuleWriteFreezeManager
{
    public function acquireMigrationFreeze(string $moduleKey, string $freezeKey): void;

    public function releaseMigrationFreeze(string $moduleKey, string $freezeKey): void;
}
