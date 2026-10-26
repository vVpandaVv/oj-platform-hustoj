<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AuthTransaction
{
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;
}
