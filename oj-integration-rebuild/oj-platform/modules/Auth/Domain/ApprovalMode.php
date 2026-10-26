<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

enum ApprovalMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
