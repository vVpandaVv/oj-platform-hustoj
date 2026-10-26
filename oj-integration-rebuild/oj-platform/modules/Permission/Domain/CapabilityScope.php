<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Domain;

enum CapabilityScope: string
{
    case Global = 'global';
    case Creation = 'creation';
    case GlobalOrContext = 'global_or_context';
    case ContextOnly = 'context_only';
}
