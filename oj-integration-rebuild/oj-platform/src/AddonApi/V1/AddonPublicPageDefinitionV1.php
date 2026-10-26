<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonPublicPageDefinitionV1
{
    /** @param list<string> $assetBundles */
    public function __construct(
        private readonly string $key,
        private readonly string $path,
        private readonly AddonPageHandlerV1 $handler,
        private readonly string $capability,
        private readonly array $assetBundles = []
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::routePath($this->path);
        ValueValidator::capabilityKey($this->capability);
        ValueValidator::stringList($this->assetBundles, 'assetBundles', 4);
        foreach ($this->assetBundles as $assetBundle) {
            ValueValidator::contributionKey($assetBundle, 'assetBundles');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): AddonPageHandlerV1
    {
        return $this->handler;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    /** @return list<string> */
    public function assetBundles(): array
    {
        return $this->assetBundles;
    }
}
