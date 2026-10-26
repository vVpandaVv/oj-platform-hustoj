<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;

final class Module implements ModuleContract
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('public_ui'),
            ModuleType::Required,
            ['auth', 'access_router']
        );
    }

    public function register(): void
    {
    }

    public function connect(): void
    {
    }
}
