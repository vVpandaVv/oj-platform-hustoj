<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;

/** @internal */
final class RegistryCoreContributionSnapshotProvider implements CoreContributionSnapshotProvider
{
    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     * @param DefinitionRegistry<MenuDefinition> $menus
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param list<string> $assetHandles
     * @param list<string> $aiTaskKeys
     */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        private readonly DefinitionRegistry $menus,
        private readonly DefinitionRegistry $capabilities,
        private readonly PublicMenuSettingsService $publicMenus,
        private readonly array $assetHandles,
        private readonly array $aiTaskKeys
    ) {
    }

    public function snapshot(): CoreContributionSnapshot
    {
        $routeKeys = [];
        $routePaths = [];
        foreach ($this->routes->all() as $route) {
            if ($route instanceof RouteDefinition) {
                $routeKeys[] = $route->key();
                $routePaths[] = $route->path();
            }
        }
        $menuKeys = [];
        $anchors = [];
        foreach ($this->menus->all() as $menu) {
            if (! $menu instanceof MenuDefinition) {
                continue;
            }
            $menuKeys[] = $menu->key();
            if ($menu->parent() === null) {
                $anchors[] = $menu->key();
            }
        }
        $publicRoots = [];
        foreach ($this->publicMenus->defaults() as $entry) {
            if ($entry->level() === 1) {
                $publicRoots[] = $entry->key();
            }
        }

        return new CoreContributionSnapshot(
            self::uniqueSorted($routeKeys),
            self::uniqueSorted($routePaths),
            self::uniqueSorted($menuKeys),
            self::uniqueSorted($anchors),
            self::uniqueSorted($publicRoots),
            self::uniqueSorted(array_keys($this->capabilities->all())),
            self::uniqueSorted($this->assetHandles),
            self::uniqueSorted($this->aiTaskKeys)
        );
    }

    /** @param list<string> $values @return list<string> */
    private static function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
