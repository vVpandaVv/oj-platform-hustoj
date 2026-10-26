<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonSiteAssetConfigProviderV1
{
    /** @return array<string, mixed> Empty means the asset is not rendered for this actor. */
    public function config(int $actorUserId): array;
}
