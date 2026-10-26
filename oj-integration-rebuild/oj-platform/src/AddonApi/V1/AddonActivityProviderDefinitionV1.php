<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonActivityProviderDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly AddonActivityProviderV1 $provider
    ) {
        ValueValidator::contributionKey($this->key);
    }

    public function key(): string { return $this->key; }
    public function provider(): AddonActivityProviderV1 { return $this->provider; }
}
