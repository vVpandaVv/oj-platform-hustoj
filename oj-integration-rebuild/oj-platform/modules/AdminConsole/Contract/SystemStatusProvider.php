<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

interface SystemStatusProvider
{
    public function key(): string;

    public function current(): SystemStatusItem;
}
