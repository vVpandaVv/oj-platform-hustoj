<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchExtensionV1;

/**
 * Optional early composition phase of the common Addon API, available since 1.2.0.
 * Published through oj_platform_register_extensions before host registries are built.
 * Existing addons continue to use oj_platform_register_addons unchanged.
 */
interface AddonExtensionRegistrationGatewayV1
{
    public function apiVersion(): string;

    /** A single research provider may own the site's research policies. */
    public function registerResearch(ResearchExtensionV1 $extension): void;
}
