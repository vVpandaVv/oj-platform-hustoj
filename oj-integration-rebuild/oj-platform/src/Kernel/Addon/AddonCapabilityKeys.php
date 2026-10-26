<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/** @internal */
final class AddonCapabilityKeys
{
    public const MODULE_OWNER = 'addon_bridge';

    public const FEATURE_KEY = 'addon_contribution';

    private const CAPABILITY_PREFIX = 'oj_addon_';

    private function __construct()
    {
    }

    public static function capabilityKey(string $addonCapabilityKey): string
    {
        return AddonCapabilityKeyV1::wordpress($addonCapabilityKey);
    }

    public static function isAddonCapability(string $capabilityKey): bool
    {
        return str_starts_with($capabilityKey, self::CAPABILITY_PREFIX);
    }
}
