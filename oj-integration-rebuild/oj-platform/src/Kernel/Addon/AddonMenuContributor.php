<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;

/** @internal */
final class AddonMenuContributor
{
    private const PUBLIC_MENU_LEVEL = 2;

    /**
     * @param DefinitionRegistry<MenuDefinition> $menus
     */
    public function __construct(
        private readonly DefinitionRegistry $menus,
        private readonly AddonPublicMenuContributions $publicMenus
    ) {
    }

    /**
     * D-010: sidebar heading for addon items under core groups that also hold
     * core items, so addon tools read as their own group.
     */
    private const SECTIONS = [
        'classes' => '수업 도구',
        'operations' => '애드온',
    ];

    public function contribute(AddonManifest $manifest): void
    {
        foreach ($manifest->consoleMenus() as $menu) {
            $this->menus->register(new MenuDefinition(
                $menu->key(),
                $menu->label(),
                $menu->anchor(),
                $menu->route(),
                null,
                [
                    'oj_access_console',
                    AddonCapabilityKeys::capabilityKey($menu->capability()),
                ],
                $menu->order(),
                'all_capabilities',
                [],
                self::SECTIONS[$menu->anchor()] ?? null,
                $menu->icon()
            ));
        }

        foreach ($manifest->publicMenus() as $menu) {
            $this->publicMenus->add(new PublicMenuEntry(
                $menu->key(),
                $menu->label(),
                self::PUBLIC_MENU_LEVEL,
                $menu->parent(),
                $menu->route(),
                null,
                $menu->scope(),
                $menu->order(),
                $menu->mobile() ? 'primary' : 'more',
                $menu->defaultEnabled()
            ), AddonCapabilityKeys::capabilityKey($menu->capability()));
        }
    }
}
