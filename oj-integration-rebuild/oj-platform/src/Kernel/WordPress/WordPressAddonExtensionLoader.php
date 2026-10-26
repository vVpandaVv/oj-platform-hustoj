<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Addon\AddonExtensionRegistry;

final class WordPressAddonExtensionLoader
{
    public function load(string $apiVersion): AddonExtensionRegistry
    {
        $registry = new AddonExtensionRegistry($apiVersion);
        try {
            if (function_exists('do_action')) {
                do_action('oj_platform_register_extensions', $registry);
            }
        } finally {
            // Do not leave a captured registration handle open after a failed callback.
            $registry->close();
        }
        return $registry;
    }
}
