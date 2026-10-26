<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface PrivateDataRootProvider
{
    public function root(): string;
}
