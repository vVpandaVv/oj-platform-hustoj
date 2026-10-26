<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional Addon API 1.4 contributions shared through OJ Platform. */
interface AddonIntegrationManifestBuilderV1 extends AddonManifestBuilderV1
{
    public function activityProvider(AddonActivityProviderDefinitionV1 $definition): void;

    public function siteAsset(AddonSiteAssetDefinitionV1 $definition): void;

    public function privacyData(AddonPrivacyDataDefinitionV1 $definition): void;
}
