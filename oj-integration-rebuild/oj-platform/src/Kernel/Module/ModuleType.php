<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

enum ModuleType: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Locked = 'locked';
}
