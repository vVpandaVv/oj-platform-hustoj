<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

enum ActionTransport: string
{
    case AdminPost = 'admin_post';
    case Ajax = 'ajax';
}
