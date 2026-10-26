<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface CurrentAccountProvider
{
    public function current(): CurrentAccount;
}
