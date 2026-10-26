<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Kernel\Module\ModuleManifest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeProfileDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionPresetDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;

final class FoundationContractValidator implements RegistrySetValidator
{
    private const WORDPRESS_CAPABILITIES = [
        'read',
        'upload_files',
    ];

    /**
     * Synthetic owner used for every registry definition contributed by an
     * accepted addon (see AddonCapabilityKeys::MODULE_OWNER). It never
     * appears in the target module manifest since it names no real module —
     * addon contributions are validated at accept-time instead.
     */
    private const ADDON_BRIDGE_OWNER = 'addon_bridge';

    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param DefinitionRegistry<RoleDefinition> $roles
     * @param DefinitionRegistry<PermissionPresetDefinition> $presets
     * @param DefinitionRegistry<FeatureDefinition> $features
     * @param DefinitionRegistry<SettingDefinition> $settings
     * @param DefinitionRegistry<TargetTableDefinition> $targetTables
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     * @param DefinitionRegistry<JudgeProfileDefinition> $judgeProfiles
     * @param DefinitionRegistry<JobDefinition> $jobs
     * @param DefinitionRegistry<RouteDefinition> $routes
     * @param DefinitionRegistry<ActionDefinition> $actions
     * @param DefinitionRegistry<MenuDefinition> $menus
     */
    public function __construct(
        private readonly ModuleManifest $modules,
        private readonly DefinitionRegistry $capabilities,
        private readonly DefinitionRegistry $roles,
        private readonly DefinitionRegistry $presets,
        private readonly DefinitionRegistry $features,
        private readonly DefinitionRegistry $settings,
        private readonly DefinitionRegistry $targetTables,
        private readonly DefinitionRegistry $schemas,
        private readonly DefinitionRegistry $judgeProfiles,
        private readonly DefinitionRegistry $jobs,
        private readonly DefinitionRegistry $routes,
        private readonly DefinitionRegistry $actions,
        private readonly DefinitionRegistry $menus
    ) {
    }

    public function validate(): void
    {
        $this->validateFeatures();
        $this->validatePermissions();
        $this->validateSettings();
        $this->validatePlatformTables();
        $this->validateJudgeProfile();
        $this->validateJobs();
        $this->validateRoutesAndActions();
        $this->validateMenus();
    }

    private function validateFeatures(): void
    {
        foreach ($this->features->all() as $feature) {
            foreach ($feature->dependencies() as $dependency) {
                $this->requireDefinition($this->features, $dependency, '기능 의존 관계');
            }
        }

        $this->assertFeatureGraphIsAcyclic();
    }

    private function validatePermissions(): void
    {
        foreach ($this->capabilities->all() as $capability) {
            $this->requireModule($capability->owner()->value(), '권한 소유 모듈');
            $this->requireDefinition(
                $this->features,
                $capability->featureKey(),
                '권한 기능'
            );
        }

        foreach (array_merge($this->roles->all(), $this->presets->all()) as $definition) {
            if (! $definition instanceof RoleDefinition && ! $definition instanceof PermissionPresetDefinition) {
                continue;
            }

            foreach ($definition->capabilities() as $capability) {
                if (in_array($capability, self::WORDPRESS_CAPABILITIES, true)) {
                    continue;
                }

                $this->requireDefinition($this->capabilities, $capability, '역할·권한 묶음');
            }
        }
    }

    private function validateSettings(): void
    {
        $optionNames = [];
        foreach ($this->settings->all() as $setting) {
            $this->requireModule($setting->owner()->value(), '설정 소유 모듈');

            if (isset($optionNames[$setting->optionName()])) {
                throw KernelException::invalidRegistry(sprintf(
                    'WordPress 옵션 이름이 중복되었습니다: %s',
                    $setting->optionName()
                ));
            }
            $optionNames[$setting->optionName()] = true;
        }
    }

    private function validatePlatformTables(): void
    {
        foreach ($this->targetTables->all() as $target) {
            $this->requireModule($target->owner()->value(), '테이블 소유 모듈');
        }

        foreach ($this->schemas->all() as $schema) {
            $target = $this->targetTables->find($schema->key());
            if (! $target instanceof TargetTableDefinition) {
                throw KernelException::invalidRegistry(sprintf(
                    '목표 계약에 없는 테이블 스키마입니다: %s',
                    $schema->key()
                ));
            }

            if (! $schema->owner()->equals($target->owner())) {
                throw KernelException::invalidRegistry(sprintf(
                    '테이블 소유 모듈이 일치하지 않습니다: %s',
                    $schema->key()
                ));
            }

            foreach ($schema->foreignKeys() as $foreignKey) {
                $referencedSchema = $this->schemas->find($foreignKey->referencedTable());
                if (! $referencedSchema instanceof SchemaTableDefinition) {
                    throw KernelException::invalidRegistry(sprintf(
                        '외래 키 대상 스키마가 등록되지 않았습니다: %s',
                        $foreignKey->referencedTable()
                    ));
                }

                $referencedColumns = array_map(
                    static fn ($column): string => $column->name(),
                    $referencedSchema->columns()
                );
                if (! in_array($foreignKey->referencedColumn(), $referencedColumns, true)) {
                    throw KernelException::invalidRegistry(sprintf(
                        '외래 키 대상 열이 없습니다: %s.%s',
                        $foreignKey->referencedTable(),
                        $foreignKey->referencedColumn()
                    ));
                }
            }
        }
    }

    private function validateJudgeProfile(): void
    {
        if ($this->judgeProfiles->count() !== 1) {
            throw KernelException::invalidRegistry('Judge 프로필은 정확히 하나여야 합니다.');
        }

        $profile = $this->judgeProfiles->find('default');
        if (! $profile instanceof JudgeProfileDefinition) {
            throw KernelException::invalidRegistry('기본 Judge 프로필이 없습니다.');
        }
    }

    private function validateJobs(): void
    {
        foreach ($this->jobs->all() as $job) {
            $this->requireModule($job->owner()->value(), '작업 소유 모듈');
        }
    }

    private function validateRoutesAndActions(): void
    {
        $pathMethods = [];
        foreach ($this->routes->all() as $route) {
            $this->requireModule($route->owner()->value(), 'Route 소유 모듈');
            $this->requireDefinition($this->features, $route->featureKey(), 'Route 기능');

            foreach ($route->methods() as $method) {
                $signature = $method . ' ' . $route->path();
                if (isset($pathMethods[$signature])) {
                    throw KernelException::invalidRegistry(sprintf(
                        'Route 경로와 메서드가 중복되었습니다: %s',
                        $signature
                    ));
                }
                $pathMethods[$signature] = true;
            }
        }

        foreach ($this->actions->all() as $action) {
            $this->requireModule($action->owner()->value(), 'Action 소유 모듈');
        }
    }

    private function validateMenus(): void
    {
        foreach ($this->menus->all() as $menu) {
            if ($menu->parent() !== null) {
                $this->requireDefinition($this->menus, $menu->parent(), '상위 관리 메뉴');
            }

            if ($menu->route() !== 'first_accessible_child') {
                $this->requireDefinition($this->routes, $menu->route(), '관리 메뉴 Route');
            }

            if ($menu->featureKey() !== null) {
                $this->requireDefinition($this->features, $menu->featureKey(), '관리 메뉴 기능');
            }
            foreach ($menu->anyFeatureKeys() as $featureKey) {
                $this->requireDefinition($this->features, $featureKey, '관리 메뉴 선택 기능');
            }

            foreach ($menu->capabilities() as $capability) {
                $this->requireDefinition($this->capabilities, $capability, '관리 메뉴 권한');
            }
        }

        $this->assertMenuGraphIsAcyclic();
    }

    private function assertFeatureGraphIsAcyclic(): void
    {
        $states = [];
        $visit = function (string $key) use (&$visit, &$states): void {
            if (($states[$key] ?? 0) === 2) {
                return;
            }

            if (($states[$key] ?? 0) === 1) {
                throw KernelException::invalidRegistry('기능 의존 관계에 순환이 있습니다.');
            }

            $states[$key] = 1;
            $feature = $this->features->find($key);
            if ($feature instanceof FeatureDefinition) {
                foreach ($feature->dependencies() as $dependency) {
                    $visit($dependency);
                }
            }
            $states[$key] = 2;
        };

        foreach (array_keys($this->features->all()) as $key) {
            $visit($key);
        }
    }

    private function assertMenuGraphIsAcyclic(): void
    {
        foreach ($this->menus->all() as $menu) {
            $visited = [];
            $current = $menu;

            while ($current->parent() !== null) {
                if (isset($visited[$current->key()])) {
                    throw KernelException::invalidRegistry('관리 메뉴 상위 관계에 순환이 있습니다.');
                }
                $visited[$current->key()] = true;

                $parent = $this->menus->find($current->parent());
                if (! $parent instanceof MenuDefinition) {
                    break;
                }
                $current = $parent;
            }
        }
    }

    private function requireModule(string $moduleId, string $context): void
    {
        if ($moduleId === self::ADDON_BRIDGE_OWNER) {
            return;
        }

        if (! $this->modules->has($moduleId)) {
            throw KernelException::invalidRegistry(sprintf(
                '%s이 목표 모듈에 없습니다: %s',
                $context,
                $moduleId
            ));
        }
    }

    private function requireDefinition(
        DefinitionRegistry $registry,
        string $key,
        string $context
    ): void {
        if ($registry->find($key) === null) {
            throw KernelException::invalidRegistry(sprintf(
                '%s 참조가 없습니다: %s',
                $context,
                $key
            ));
        }
    }
}
