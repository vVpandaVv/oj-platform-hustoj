<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;

/** @internal */
final class AddonManifest
{
    /**
     * @param list<AddonCapabilityDefinitionV1> $capabilities
     * @param list<AddonConsolePageDefinitionV1> $consolePages
     * @param list<AddonPublicPageDefinitionV1> $publicPages
     * @param list<AddonConsoleMenuDefinitionV1> $consoleMenus
     * @param list<AddonPublicMenuDefinitionV1> $publicMenus
     * @param list<AddonAssetBundleDefinitionV1> $assetBundles
     * @param list<AddonAiTaskDefinitionV1> $aiTasks
     * @param list<AddonConsoleLocalNavItemV1> $consoleLocalNav
     * @param list<AddonActivityProviderDefinitionV1> $activityProviders
     * @param list<AddonSiteAssetDefinitionV1> $siteAssets
     * @param list<AddonPrivacyDataDefinitionV1> $privacyData
     */
    public function __construct(
        private readonly string $namespace,
        private readonly string $version,
        private readonly string $pluginFile,
        private readonly string $requiresPlatform,
        private readonly string $requiresAddonApi,
        private readonly string $diagnosticLabel,
        private readonly string $supportReference,
        private readonly array $capabilities,
        private readonly array $consolePages,
        private readonly array $publicPages,
        private readonly array $consoleMenus,
        private readonly array $publicMenus,
        private readonly array $assetBundles,
        private readonly array $aiTasks,
        private readonly array $consoleLocalNav = [],
        private readonly array $activityProviders = [],
        private readonly array $siteAssets = [],
        private readonly array $privacyData = []
    ) {
    }

    public function namespace(): string { return $this->namespace; }
    public function version(): string { return $this->version; }
    public function pluginFile(): string { return $this->pluginFile; }
    public function requiresPlatform(): string { return $this->requiresPlatform; }
    public function requiresAddonApi(): string { return $this->requiresAddonApi; }
    public function diagnosticLabel(): string { return $this->diagnosticLabel; }
    public function supportReference(): string { return $this->supportReference; }
    /** @return list<AddonCapabilityDefinitionV1> */
    public function capabilities(): array { return $this->capabilities; }
    /** @return list<AddonConsolePageDefinitionV1> */
    public function consolePages(): array { return $this->consolePages; }
    /** @return list<AddonPublicPageDefinitionV1> */
    public function publicPages(): array { return $this->publicPages; }
    /** @return list<AddonConsoleMenuDefinitionV1> */
    public function consoleMenus(): array { return $this->consoleMenus; }
    /** @return list<AddonPublicMenuDefinitionV1> */
    public function publicMenus(): array { return $this->publicMenus; }
    /** @return list<AddonAssetBundleDefinitionV1> */
    public function assetBundles(): array { return $this->assetBundles; }
    /** @return list<AddonAiTaskDefinitionV1> */
    public function aiTasks(): array { return $this->aiTasks; }
    /** @return list<AddonConsoleLocalNavItemV1> */
    public function consoleLocalNav(): array { return $this->consoleLocalNav; }
    /** @return list<AddonActivityProviderDefinitionV1> */
    public function activityProviders(): array { return $this->activityProviders; }
    /** @return list<AddonSiteAssetDefinitionV1> */
    public function siteAssets(): array { return $this->siteAssets; }
    /** @return list<AddonPrivacyDataDefinitionV1> */
    public function privacyData(): array { return $this->privacyData; }

    /** @return array<string, int> */
    public function contributionCounts(): array
    {
        $counts = [
            'capability' => count($this->capabilities),
            'console_page' => count($this->consolePages),
            'public_page' => count($this->publicPages),
            'console_menu' => count($this->consoleMenus),
            'public_menu' => count($this->publicMenus),
            'asset_bundle' => count($this->assetBundles),
            'ai_task' => count($this->aiTasks),
        ];
        // Addon API 1.3: reported only when used, so addons that compare this
        // map (it reaches them through AddonRuntimeSnapshotV1) keep working.
        if ($this->consoleLocalNav !== []) {
            $counts['console_local_nav'] = count($this->consoleLocalNav);
        }
        if ($this->activityProviders !== []) {
            $counts['activity_provider'] = count($this->activityProviders);
        }
        if ($this->siteAssets !== []) {
            $counts['site_asset'] = count($this->siteAssets);
        }
        if ($this->privacyData !== []) {
            $counts['privacy_data'] = count($this->privacyData);
        }

        return $counts;
    }
}
