<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Permission\Contract\AddonCapabilityAssignmentGateway;

final class WordPressAddonCapabilityAssignmentGateway implements AddonCapabilityAssignmentGateway
{
    public function rolesGrantedTo(string $capabilityKey): array
    {
        if (! function_exists('wp_roles')) {
            return [];
        }

        $granted = [];
        foreach (wp_roles()->roles as $roleKey => $role) {
            if (is_array($role) && ! empty($role['capabilities'][$capabilityKey])) {
                $granted[] = (string) $roleKey;
            }
        }
        sort($granted, SORT_STRING);

        return $granted;
    }

    public function grantToRole(string $roleKey, string $capabilityKey): void
    {
        $role = function_exists('get_role') ? get_role($roleKey) : null;
        if ($role !== null && method_exists($role, 'add_cap')) {
            $role->add_cap($capabilityKey, true);
        }
    }

    public function revokeFromRole(string $roleKey, string $capabilityKey): void
    {
        $role = function_exists('get_role') ? get_role($roleKey) : null;
        if ($role !== null && method_exists($role, 'remove_cap')) {
            $role->remove_cap($capabilityKey);
        }
    }
}
