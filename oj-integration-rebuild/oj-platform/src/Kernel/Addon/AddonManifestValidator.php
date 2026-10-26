<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use Throwable;

/** @internal */
final class AddonManifestValidator
{
    public function __construct(
        private readonly string $platformVersion,
        private readonly string $addonApiVersion,
        private readonly CoreContributionSnapshotProvider $core,
        private readonly AddonNamespaceLedger $namespaceLedger
    ) {
    }

    /**
     * @param list<AddonCandidate> $candidates
     * @return list<AddonCatalogEntry>
     */
    public function validate(array $candidates): array
    {
        $core = $this->core->snapshot();
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate->addonId()][] = $candidate;
        }
        ksort($groups, SORT_STRING);

        $entries = [];
        $valid = [];
        $validCandidates = [];
        foreach ($groups as $addonId => $group) {
            if (count($group) !== 1) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    'rejected',
                    'addon_contribution_conflict'
                );
                continue;
            }
            $candidate = $group[0];
            if ($candidate->failedRegistration()) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    'rejected',
                    $candidate->failureCode()
                );
                continue;
            }
            $manifest = $candidate->manifestValue();
            if (! $manifest instanceof AddonManifest) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    'rejected',
                    'addon_manifest_invalid'
                );
                continue;
            }
            try {
                $this->validateManifest($addonId, $manifest, $core);
                $valid[$addonId] = $manifest;
                $validCandidates[$addonId] = $candidate;
            } catch (AddonValidationException $error) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    $error->runtimeStatus(),
                    $error->problemCode()
                );
            } catch (Throwable) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    'rejected',
                    'addon_manifest_invalid'
                );
            }
        }

        $conflicts = $this->conflictingAddons($valid, $core);
        foreach ($valid as $addonId => $manifest) {
            if (isset($conflicts[$addonId])) {
                $entries[$addonId] = new AddonCatalogEntry(
                    $addonId,
                    null,
                    'rejected',
                    'addon_contribution_conflict'
                );
                continue;
            }
            $this->namespaceLedger->bind($manifest->namespace(), $addonId);
            $entries[$addonId] = new AddonCatalogEntry(
                $addonId,
                $manifest,
                'accepted',
                '',
                $validCandidates[$addonId]->onReadyCallback()
            );
        }
        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    private function validateManifest(
        string $addonId,
        AddonManifest $manifest,
        CoreContributionSnapshot $core
    ): void {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $manifest->namespace()) !== 1) {
            $this->invalid();
        }
        $namespaceOwner = $this->namespaceLedger->ownerOf($manifest->namespace());
        if ($namespaceOwner !== '' && $namespaceOwner !== $addonId) {
            throw new AddonValidationException('rejected', 'addon_namespace_reserved');
        }
        ValueValidator::semanticVersion($manifest->version(), 'version');
        ValueValidator::label($manifest->diagnosticLabel(), 'diagnosticLabel');
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,159}$/D', $manifest->supportReference()) !== 1
            || str_contains($manifest->supportReference(), '..')
        ) {
            $this->invalid();
        }
        $this->validatePluginFile($addonId, $manifest->pluginFile());

        try {
            if (! SemverRange::matches($this->platformVersion, $manifest->requiresPlatform())) {
                throw new AddonValidationException(
                    'incompatible',
                    'addon_platform_incompatible'
                );
            }
            if (! SemverRange::matches($this->addonApiVersion, $manifest->requiresAddonApi())) {
                throw new AddonValidationException(
                    'incompatible',
                    'addon_api_incompatible'
                );
            }
        } catch (AddonValidationException $error) {
            throw $error;
        } catch (Throwable) {
            $this->invalid();
        }

        $counts = $manifest->contributionCounts();
        $limits = [
            'capability' => 32,
            'console_page' => 32,
            'public_page' => 32,
            'console_menu' => 8,
            'public_menu' => 8,
            'asset_bundle' => 32,
            'ai_task' => 16,
            'activity_provider' => 8,
            'site_asset' => 4,
            'privacy_data' => 16,
        ];
        foreach ($limits as $kind => $limit) {
            if (($counts[$kind] ?? 0) > $limit) {
                $this->invalid();
            }
        }
        if (array_sum($counts) > 128) {
            $this->invalid();
        }

        $prefix = 'addon.' . $manifest->namespace() . '.';
        $contributionKeys = [];
        foreach ($this->contributionDefinitions($manifest) as $definition) {
            $key = $definition->key();
            if (! str_starts_with($key, $prefix) || isset($contributionKeys[$key])) {
                $this->invalid();
            }
            $contributionKeys[$key] = true;
        }

        $capabilities = [];
        foreach ($manifest->capabilities() as $definition) {
            if (
                ! str_starts_with($definition->key(), $manifest->namespace() . '_')
                || isset($capabilities[$definition->key()])
            ) {
                $this->invalid();
            }
            $capabilities[$definition->key()] = true;
        }
        $assets = [];
        $handles = [];
        foreach ($manifest->assetBundles() as $definition) {
            if (isset($assets[$definition->key()]) || isset($handles[$definition->handle()])) {
                $this->invalid();
            }
            $assets[$definition->key()] = true;
            $handles[$definition->handle()] = true;
            $this->validateAssetFiles($manifest->pluginFile(), $definition);
        }
        $siteAssetConfigIds = [];
        foreach ($manifest->siteAssets() as $definition) {
            if (
                ! isset($assets[$definition->assetBundle()])
                || isset($siteAssetConfigIds[$definition->configElementId()])
            ) {
                $this->invalid();
            }
            $siteAssetConfigIds[$definition->configElementId()] = true;
        }

        $consoleRoutes = [];
        foreach ($manifest->consolePages() as $definition) {
            $this->validatePage(
                $definition,
                '/oj-console/addons/' . $addonId,
                $capabilities,
                $assets
            );
            $consoleRoutes[$definition->key()] = $definition;
        }
        $publicRoutes = [];
        foreach ($manifest->publicPages() as $definition) {
            $this->validatePage(
                $definition,
                '/oj-addons/' . $addonId,
                $capabilities,
                $assets
            );
            $publicRoutes[$definition->key()] = $definition;
        }

        $consoleMenuRoutes = [];
        foreach ($manifest->consoleMenus() as $menu) {
            $route = $consoleRoutes[$menu->route()] ?? null;
            if (
                ! $route instanceof AddonConsolePageDefinitionV1
                || $route->capability() !== $menu->capability()
                || ! isset($capabilities[$menu->capability()])
                || ! in_array($menu->anchor(), $core->consoleAnchors(), true)
                || isset($consoleMenuRoutes[$menu->route()])
            ) {
                $this->invalid();
            }
            $consoleMenuRoutes[$menu->route()] = true;
        }

        // Local navigation: each segment opens one console page of this
        // addon, once, under one of this addon's menus; a menu with segments
        // must list its own page among them.
        $menusByKey = [];
        foreach ($manifest->consoleMenus() as $menu) {
            $menusByKey[$menu->key()] = $menu;
        }
        $localNavRoutes = [];
        $localNavMenus = [];
        foreach ($manifest->consoleLocalNav() as $item) {
            if (
                ! isset($menusByKey[$item->menu()])
                || ! isset($consoleRoutes[$item->route()])
                || isset($localNavRoutes[$item->route()])
            ) {
                $this->invalid();
            }
            $localNavRoutes[$item->route()] = $item->menu();
            $localNavMenus[$item->menu()] = true;
        }
        foreach (array_keys($localNavMenus) as $menuKey) {
            if (($localNavRoutes[$menusByKey[$menuKey]->route()] ?? null) !== $menuKey) {
                $this->invalid();
            }
        }
        $publicMenuRoutes = [];
        foreach ($manifest->publicMenus() as $menu) {
            $route = $publicRoutes[$menu->route()] ?? null;
            if (
                ! $route instanceof AddonPublicPageDefinitionV1
                || $route->capability() !== $menu->capability()
                || ! isset($capabilities[$menu->capability()])
                || ! in_array($menu->parent(), $core->publicRoots(), true)
                || $this->parameterNames($route->path()) !== []
                || isset($publicMenuRoutes[$menu->route()])
            ) {
                $this->invalid();
            }
            $publicMenuRoutes[$menu->route()] = true;
        }
    }

    /**
     * @param array<string, AddonManifest> $valid
     * @return array<string, true>
     */
    private function conflictingAddons(
        array $valid,
        CoreContributionSnapshot $core
    ): array {
        $conflicts = [];
        $indexes = [
            'namespace' => [],
            'key' => [],
            'path' => [],
            'capability' => [],
            'handle' => [],
            'config_element_id' => [],
        ];
        $coreKeys = array_fill_keys(array_merge(
            $core->routeKeys(),
            $core->consoleMenuKeys(),
            $core->aiTaskKeys()
        ), true);
        $corePaths = array_fill_keys($core->routePaths(), true);
        $coreCapabilities = array_fill_keys($core->capabilityKeys(), true);
        $coreHandles = array_fill_keys($core->assetHandles(), true);

        foreach ($valid as $addonId => $manifest) {
            $indexes['namespace'][$manifest->namespace()][] = $addonId;
            foreach ($this->contributionDefinitions($manifest) as $definition) {
                $key = $definition->key();
                $indexes['key'][$key][] = $addonId;
                if (isset($coreKeys[$key])) {
                    $conflicts[$addonId] = true;
                }
            }
            foreach (array_merge($manifest->consolePages(), $manifest->publicPages()) as $page) {
                $indexes['path'][$page->path()][] = $addonId;
                if (isset($corePaths[$page->path()])) {
                    $conflicts[$addonId] = true;
                }
            }
            foreach ($manifest->capabilities() as $capability) {
                $indexes['capability'][$capability->key()][] = $addonId;
                if (isset($coreCapabilities[$capability->key()])) {
                    $conflicts[$addonId] = true;
                }
            }
            foreach ($manifest->assetBundles() as $asset) {
                $indexes['handle'][$asset->handle()][] = $addonId;
                if (isset($coreHandles[$asset->handle()])) {
                    $conflicts[$addonId] = true;
                }
            }
            foreach ($manifest->siteAssets() as $siteAsset) {
                $indexes['config_element_id'][$siteAsset->configElementId()][] = $addonId;
            }
        }
        foreach ($indexes as $values) {
            foreach ($values as $owners) {
                $owners = array_values(array_unique($owners));
                if (count($owners) < 2) {
                    continue;
                }
                foreach ($owners as $owner) {
                    $conflicts[$owner] = true;
                }
            }
        }

        return $conflicts;
    }

    /** @param array<string, true> $capabilities @param array<string, true> $assets */
    private function validatePage(
        AddonConsolePageDefinitionV1|AddonPublicPageDefinitionV1 $page,
        string $requiredPrefix,
        array $capabilities,
        array $assets
    ): void {
        if (
            ($page->path() !== $requiredPrefix && ! str_starts_with($page->path(), $requiredPrefix . '/'))
            || ! isset($capabilities[$page->capability()])
        ) {
            $this->invalid();
        }
        $parameters = $this->parameterNames($page->path());
        if (count($parameters) > 8 || count($parameters) !== count(array_unique($parameters))) {
            $this->invalid();
        }
        foreach ($page->assetBundles() as $assetKey) {
            if (! isset($assets[$assetKey])) {
                $this->invalid();
            }
        }
    }

    private function validatePluginFile(string $addonId, string $pluginFile): void
    {
        $realFile = realpath($pluginFile);
        if (
            $realFile === false
            || ! is_file($realFile)
            || basename(dirname($realFile)) !== $addonId
            || strtolower(pathinfo($realFile, PATHINFO_EXTENSION)) !== 'php'
        ) {
            $this->invalid();
        }
    }

    private function validateAssetFiles(
        string $pluginFile,
        AddonAssetBundleDefinitionV1 $bundle
    ): void {
        $root = realpath(dirname($pluginFile));
        if ($root === false) {
            $this->invalid();
        }
        foreach (array_merge($bundle->styles(), $bundle->scripts()) as $relative) {
            $asset = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (
                $asset === false
                || ! is_file($asset)
                || ($asset !== $root && ! str_starts_with($asset, $root . DIRECTORY_SEPARATOR))
            ) {
                $this->invalid();
            }
        }
    }

    /** @return list<object> */
    private function contributionDefinitions(AddonManifest $manifest): array
    {
        return array_merge(
            $manifest->consolePages(),
            $manifest->publicPages(),
            $manifest->consoleMenus(),
            $manifest->consoleLocalNav(),
            $manifest->publicMenus(),
            $manifest->assetBundles(),
            $manifest->aiTasks(),
            $manifest->activityProviders(),
            $manifest->siteAssets(),
            $manifest->privacyData()
        );
    }

    /** @return list<string> */
    private function parameterNames(string $path): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $path, $matches);

        return array_values($matches[1] ?? []);
    }

    private function invalid(): never
    {
        throw new AddonValidationException('rejected', 'addon_manifest_invalid');
    }
}
