<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

enum AuthenticationRequirement: string
{
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';
}
