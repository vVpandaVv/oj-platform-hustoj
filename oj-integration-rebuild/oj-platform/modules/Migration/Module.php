<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;

final class Module implements ModuleContract
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('migration'),
            ModuleType::Locked,
            [
                new ModuleId('platform_db'),
                new ModuleId('judge_db'),
                new ModuleId('jobs'),
            ]
        );
    }

    public function register(): void
    {
    }

    public function connect(): void
    {
    }
}
