<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

final class RoleSyncReport
{
    /**
     * @param list<string> $createdRoles
     * @param list<string> $updatedRoles
     */
    public function __construct(
        private readonly array $createdRoles,
        private readonly array $updatedRoles
    ) {
    }

    /**
     * @return list<string>
     */
    public function createdRoles(): array
    {
        return $this->createdRoles;
    }

    /**
     * @return list<string>
     */
    public function updatedRoles(): array
    {
        return $this->updatedRoles;
    }
}
