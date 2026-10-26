<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

/**
 * Grants/revokes an addon-declared capability directly on native WordPress
 * role capability storage — deliberately bypassing RoleSynchronizer so a
 * grant is never auto-created and never stripped by the periodic role sync
 * (see WordPressRoleSynchronizer's oj_addon_* exclusion).
 */
interface AddonCapabilityAssignmentGateway
{
    /**
     * @return list<string> role keys (including 'administrator') currently granted this capability
     */
    public function rolesGrantedTo(string $capabilityKey): array;

    public function grantToRole(string $roleKey, string $capabilityKey): void;

    public function revokeFromRole(string $roleKey, string $capabilityKey): void;
}
