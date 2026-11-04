<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
/** Optional Addon API 1.9 catalog contributions in native problem and course menus. */
interface AddonNativeLearningManifestBuilderV1 extends AddonManifestBuilderV1
{
    public function nativeLearningProvider(AddonNativeLearningProviderDefinitionV1 $definition): void;
}
