<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Domain;

enum MessagingScope: string
{
    case Open = 'open';
    case GroupOnly = 'group_only';
}
