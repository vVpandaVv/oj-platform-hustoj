<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

/**
 * Converts a manifest capability key to the WordPress capability stored by
 * OJ Platform. Addons must use this mapper for their own REST and command
 * authorization checks instead of duplicating the platform prefix.
 */
final class AddonCapabilityKeyV1
{
    private const WORDPRESS_PREFIX = 'oj_addon_';

    private function __construct()
    {
    }

    public static function wordpress(string $declaredKey): string
    {
        ValueValidator::capabilityKey($declaredKey, 'declaredKey');

        return self::WORDPRESS_PREFIX . $declaredKey;
    }
}
