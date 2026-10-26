<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

enum RouteArea: string
{
    case PublicArea = 'public';
    case Console = 'console';
    case Response = 'response';
}
