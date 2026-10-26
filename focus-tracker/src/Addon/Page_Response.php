<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Page responses for the D-010 shared UI. The markup uses only the
 * platform's ojp-* parts and --ojp-* tokens, so the pages opt into the
 * platform dark mode where it exists (OJ Platform 2.1.0, Addon API 1.3).
 */
final class Page_Response {
    public static function make(string $title, string $body): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'standard', true);
        }

        return AddonPageResponseV1::html($title, $body);
    }
}
