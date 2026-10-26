<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

interface SystemStatusReader
{
    /**
     * @return list<SystemStatusItem>
     */
    public function current(): array;
}
