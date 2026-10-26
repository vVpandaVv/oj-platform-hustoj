<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

interface RoleSynchronizer
{
    /**
     * @param list<RoleDefinition> $roles
     * @param list<string> $administratorCapabilities
     */
    public function synchronize(array $roles, array $administratorCapabilities): RoleSyncReport;
}
