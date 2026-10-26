<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use LogicException;

/** @internal */
final class AddonManifestBuilder implements AddonManifestBuilderV1, AddonConsoleLocalNavBuilderV1, AddonIntegrationManifestBuilderV1
{
    /** @var array<string, string>|null */
    private ?array $metadata = null;
    /** @var list<AddonCapabilityDefinitionV1> */
    private array $capabilities = [];
    /** @var list<AddonConsolePageDefinitionV1> */
    private array $consolePages = [];
    /** @var list<AddonPublicPageDefinitionV1> */
    private array $publicPages = [];
    /** @var list<AddonConsoleMenuDefinitionV1> */
    private array $consoleMenus = [];
    /** @var list<AddonConsoleLocalNavItemV1> */
    private array $consoleLocalNav = [];
    /** @var list<AddonPublicMenuDefinitionV1> */
    private array $publicMenus = [];
    /** @var list<AddonAssetBundleDefinitionV1> */
    private array $assetBundles = [];
    /** @var list<AddonAiTaskDefinitionV1> */
    private array $aiTasks = [];
    /** @var list<AddonActivityProviderDefinitionV1> */
    private array $activityProviders = [];
    /** @var list<AddonSiteAssetDefinitionV1> */
    private array $siteAssets = [];
    /** @var list<AddonPrivacyDataDefinitionV1> */
    private array $privacyData = [];
    /** @var callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void|null */
    private $onReady = null;
    private bool $closed = false;

    public function onReady(callable $callback): void
    {
        $this->assertOpen();
        if ($this->onReady !== null) {
            throw new LogicException('An addon ready callback may be declared only once.');
        }
        $this->onReady = $callback;
    }

    /** @return (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null */
    public function onReadyCallback(): ?callable
    {
        return $this->onReady;
    }

    public function manifest(
        string $namespace,
        string $version,
        string $pluginFile,
        string $requiresPlatform,
        string $requiresAddonApi,
        string $diagnosticLabel,
        string $supportReference
    ): void {
        $this->assertOpen();
        if ($this->metadata !== null) {
            throw new LogicException('An addon manifest may be declared only once.');
        }
        $this->metadata = compact(
            'namespace',
            'version',
            'pluginFile',
            'requiresPlatform',
            'requiresAddonApi',
            'diagnosticLabel',
            'supportReference'
        );
    }

    public function capability(AddonCapabilityDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->capabilities[] = $definition;
    }

    public function consolePage(AddonConsolePageDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->consolePages[] = $definition;
    }

    public function publicPage(AddonPublicPageDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->publicPages[] = $definition;
    }

    public function consoleMenu(AddonConsoleMenuDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->consoleMenus[] = $definition;
    }

    public function consoleLocalNav(AddonConsoleLocalNavItemV1 $definition): void
    {
        $this->assertOpen();
        $this->consoleLocalNav[] = $definition;
    }

    public function publicMenu(AddonPublicMenuDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->publicMenus[] = $definition;
    }

    public function assetBundle(AddonAssetBundleDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->assetBundles[] = $definition;
    }

    public function aiTask(AddonAiTaskDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->aiTasks[] = $definition;
    }

    public function activityProvider(AddonActivityProviderDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->activityProviders[] = $definition;
    }

    public function siteAsset(AddonSiteAssetDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->siteAssets[] = $definition;
    }

    public function privacyData(AddonPrivacyDataDefinitionV1 $definition): void
    {
        $this->assertOpen();
        $this->privacyData[] = $definition;
    }

    public function close(): AddonManifest
    {
        $this->assertOpen();
        $this->closed = true;
        if ($this->metadata === null) {
            throw new LogicException('The addon manifest declaration is missing.');
        }

        return new AddonManifest(
            $this->metadata['namespace'],
            $this->metadata['version'],
            $this->metadata['pluginFile'],
            $this->metadata['requiresPlatform'],
            $this->metadata['requiresAddonApi'],
            $this->metadata['diagnosticLabel'],
            $this->metadata['supportReference'],
            $this->capabilities,
            $this->consolePages,
            $this->publicPages,
            $this->consoleMenus,
            $this->publicMenus,
            $this->assetBundles,
            $this->aiTasks,
            $this->consoleLocalNav,
            $this->activityProviders,
            $this->siteAssets,
            $this->privacyData
        );
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The addon manifest builder is closed.');
        }
    }
}
