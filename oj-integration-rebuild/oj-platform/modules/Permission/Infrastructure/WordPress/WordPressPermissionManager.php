<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use RuntimeException;
use Throwable;

final class WordPressPermissionManager
{
    private const ADDON_CAPABILITY_PREFIX = 'oj_addon_';

    private const SIGNATURE_OPTION = 'oj_platform_permission_signature';
    private const LOCK_OPTION = 'oj_platform_permission_lock';
    private const LOCK_TTL_SECONDS = 120;

    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly PermissionSetup $setup,
        private readonly DefinitionRegistry $capabilities,
        private readonly DefinitionRegistry $roles,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    public function registerHooks(HookRegistrar $hooks): void
    {
        $hooks->addAction('init', [$this, 'synchronizeOnInit'], 2);
    }

    public function synchronizeOnInit(): void
    {
        try {
            $this->synchronizeIfNeeded();
        } catch (Throwable $error) {
            $this->errorReporter->report($error);
        }
    }

    public function synchronizeIfNeeded(): bool
    {
        if (
            ! function_exists('get_option')
            || ! function_exists('update_option')
            || ! function_exists('add_option')
            || ! function_exists('delete_option')
        ) {
            throw new RuntimeException('WordPress 권한 동기화 API를 사용할 수 없습니다.');
        }

        $signature = $this->signature();
        if (hash_equals((string) get_option(self::SIGNATURE_OPTION, ''), $signature)) {
            return false;
        }

        if (! $this->acquireLock()) {
            return false;
        }

        try {
            if (hash_equals((string) get_option(self::SIGNATURE_OPTION, ''), $signature)) {
                return false;
            }

            $this->setup->synchronizeRoles();
            update_option(self::SIGNATURE_OPTION, $signature, false);

            return true;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private function acquireLock(): bool
    {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $lockedAt = get_option(self::LOCK_OPTION, 0);
        if (is_numeric($lockedAt) && $now - (int) $lockedAt > self::LOCK_TTL_SECONDS) {
            delete_option(self::LOCK_OPTION);

            return add_option(self::LOCK_OPTION, $now, '', false);
        }

        return false;
    }

    private function signature(): string
    {
        $capabilities = array_filter(
            array_keys($this->capabilities->all()),
            static fn (string $key): bool => ! str_starts_with($key, self::ADDON_CAPABILITY_PREFIX)
        );
        sort($capabilities);

        $roles = [];
        foreach ($this->roles->all() as $role) {
            $roleCapabilities = $role->capabilities();
            sort($roleCapabilities);
            $roles[] = [
                'key' => $role->key(),
                'capabilities' => $roleCapabilities,
            ];
        }
        usort(
            $roles,
            static fn (array $left, array $right): int => $left['key'] <=> $right['key']
        );

        $encoded = json_encode(
            ['capabilities' => $capabilities, 'roles' => $roles],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $encoded);
    }
}
