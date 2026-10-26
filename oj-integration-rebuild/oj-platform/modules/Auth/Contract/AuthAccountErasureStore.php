<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AuthAccountErasureStore
{
    public function lock(int $userId): void;

    public function erasePrivateData(int $userId): void;

    public function verify(int $userId): bool;
}
