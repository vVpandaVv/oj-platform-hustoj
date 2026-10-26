<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface ManagedUserNameUpdater
{
    /** The caller must authorize the target within its management scope. */
    public function update(int $userId, string $name, string $expectedName): string;
}
