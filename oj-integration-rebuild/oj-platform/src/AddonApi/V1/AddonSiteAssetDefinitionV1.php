<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonSiteAssetDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $assetBundle,
        private readonly string $configElementId,
        private readonly AddonSiteAssetConfigProviderV1 $configProvider
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::contributionKey($this->assetBundle, 'assetBundle');
        if (preg_match('/^[a-z][a-z0-9-]{2,79}$/D', $this->configElementId) !== 1) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: configElementId');
        }
    }

    public function key(): string { return $this->key; }
    public function assetBundle(): string { return $this->assetBundle; }
    public function configElementId(): string { return $this->configElementId; }
    public function configProvider(): AddonSiteAssetConfigProviderV1 { return $this->configProvider; }
}
