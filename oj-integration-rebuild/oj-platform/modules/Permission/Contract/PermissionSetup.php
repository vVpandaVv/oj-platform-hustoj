<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

interface PermissionSetup
{
    public function synchronizeRoles(): RoleSyncReport;
}
