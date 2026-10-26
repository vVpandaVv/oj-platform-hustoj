<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSyncReport;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSynchronizer;
use RuntimeException;

final class WordPressRoleSynchronizer implements RoleSynchronizer
{
    private const ADDON_CAPABILITY_PREFIX = 'oj_addon_';

    public function synchronize(array $roles, array $administratorCapabilities): RoleSyncReport
    {
        if (! function_exists('get_role') || ! function_exists('add_role')) {
            throw new RuntimeException('WordPress 역할 API를 사용할 수 없습니다.');
        }

        $created = [];
        $updated = [];

        foreach ($roles as $definition) {
            if (! $definition instanceof RoleDefinition) {
                throw new RuntimeException('역할 동기화 계약 타입이 올바르지 않습니다.');
            }

            $role = get_role($definition->key());
            if ($role === null) {
                $role = add_role(
                    $definition->key(),
                    $definition->label(),
                    array_fill_keys($definition->capabilities(), true)
                );
                if ($role === null) {
                    throw new RuntimeException(sprintf(
                        'WordPress 역할을 만들지 못했습니다: %s',
                        $definition->key()
                    ));
                }
                $created[] = $definition->key();
                continue;
            }

            $this->synchronizeCapabilities($role, $definition->capabilities());

            $updated[] = $definition->key();
        }

        $administrator = get_role('administrator');
        if (! is_object($administrator)) {
            throw new RuntimeException('WordPress administrator 역할을 찾지 못했습니다.');
        }
        foreach ($administratorCapabilities as $capability) {
            if (! is_string($capability) || preg_match('/^oj_[a-z0-9_]+$/', $capability) !== 1) {
                throw new RuntimeException('관리자에게 동기화할 OJ 권한 키가 올바르지 않습니다.');
            }
        }
        $this->synchronizeCapabilities($administrator, $administratorCapabilities);
        $updated[] = 'administrator';

        return new RoleSyncReport($created, $updated);
    }

    /** @param list<string> $capabilities */
    private function synchronizeCapabilities(object $role, array $capabilities): void
    {
        if (! method_exists($role, 'add_cap') || ! method_exists($role, 'remove_cap')) {
            throw new RuntimeException('WordPress 역할 객체가 capability API를 제공하지 않습니다.');
        }

        $desired = array_fill_keys($capabilities, true);
        $current = isset($role->capabilities) && is_array($role->capabilities)
            ? $role->capabilities
            : [];

        foreach ($current as $capability => $granted) {
            if (
                $granted
                && str_starts_with((string) $capability, 'oj_')
                && ! str_starts_with((string) $capability, self::ADDON_CAPABILITY_PREFIX)
                && ! isset($desired[$capability])
            ) {
                $role->remove_cap((string) $capability);
            }
        }

        foreach (array_keys($desired) as $capability) {
            $role->add_cap((string) $capability, true);
        }
    }
}
