<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;

interface RegistryDefinition
{
    public function key(): string;

    public function owner(): ModuleId;
}
