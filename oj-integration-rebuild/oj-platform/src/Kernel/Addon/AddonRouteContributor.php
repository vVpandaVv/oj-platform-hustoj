<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

/** @internal */
final class AddonRouteContributor
{
    /** @var array<string, string> routeKey => registered capability key, console pages only */
    private array $consoleCapabilities = [];

    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        private readonly RouteHandlerCollection $handlers,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly RouteUrlProvider $routeUrls,
        private readonly ConsoleResponder $consoleResponder,
        private readonly PageResponder $publicResponder,
        private readonly ?AddonAssetRenderer $assets = null
    ) {
    }

    public function contribute(AddonManifest $manifest): void
    {
        $consoleMenuKeysByRoute = [];
        foreach ($manifest->consoleMenus() as $menu) {
            $consoleMenuKeysByRoute[$menu->route()] = $menu->key();
        }

        // Addon API 1.3 local navigation: every page listed under a menu
        // keeps that menu highlighted and shows the menu's segments.
        $pageCapabilities = [];
        foreach ($manifest->consolePages() as $page) {
            $pageCapabilities[$page->key()] = AddonCapabilityKeys::capabilityKey($page->capability());
        }
        $localNav = $manifest->consoleLocalNav();
        usort($localNav, static fn ($a, $b): int => [$a->order(), $a->key()] <=> [$b->order(), $b->key()]);
        $segmentsByMenu = [];
        foreach ($localNav as $item) {
            $consoleMenuKeysByRoute[$item->route()] ??= $item->menu();
            $segmentsByMenu[$item->menu()][] = [
                'label' => $item->label(),
                'route' => $item->route(),
                'capability' => $pageCapabilities[$item->route()] ?? '',
            ];
        }
        $segmentsByRoute = [];
        foreach ($localNav as $item) {
            $segmentsByRoute[$item->route()] = $segmentsByMenu[$item->menu()];
        }

        $assetBundles = $manifest->assetBundles();

        foreach ($manifest->consolePages() as $page) {
            $capabilityKey = AddonCapabilityKeys::capabilityKey($page->capability());

            $this->routes->register(new RouteDefinition(
                $page->key(),
                new ModuleId(AddonCapabilityKeys::MODULE_OWNER),
                AddonCapabilityKeys::FEATURE_KEY,
                $page->path(),
                RouteArea::Console,
                ['GET'],
                AuthenticationRequirement::Authenticated,
                'html'
            ));
            $this->handlers->register($page->key(), new AddonRouteHandler(
                $page->key(),
                $page->handler(),
                $capabilityKey,
                $this->accounts,
                $this->permissions,
                $this->correlations,
                $this->routeUrls,
                $this->consoleResponder,
                null,
                $consoleMenuKeysByRoute[$page->key()] ?? null,
                $this->assets,
                $manifest->pluginFile(),
                $assetBundles,
                $page->assetBundles(),
                $segmentsByRoute[$page->key()] ?? []
            ));
            $this->consoleCapabilities[$page->key()] = $capabilityKey;
        }

        foreach ($manifest->publicPages() as $page) {
            $capabilityKey = AddonCapabilityKeys::capabilityKey($page->capability());

            $this->routes->register(new RouteDefinition(
                $page->key(),
                new ModuleId(AddonCapabilityKeys::MODULE_OWNER),
                AddonCapabilityKeys::FEATURE_KEY,
                $page->path(),
                RouteArea::PublicArea,
                ['GET'],
                AuthenticationRequirement::Authenticated,
                'html'
            ));
            $this->handlers->register($page->key(), new AddonRouteHandler(
                $page->key(),
                $page->handler(),
                $capabilityKey,
                $this->accounts,
                $this->permissions,
                $this->correlations,
                $this->routeUrls,
                null,
                $this->publicResponder,
                null,
                $this->assets,
                $manifest->pluginFile(),
                $assetBundles,
                $page->assetBundles()
            ));
        }
    }

    /** @return array<string, string> routeKey => registered capability key, console pages only */
    public function consoleCapabilities(): array
    {
        return $this->consoleCapabilities;
    }
}
