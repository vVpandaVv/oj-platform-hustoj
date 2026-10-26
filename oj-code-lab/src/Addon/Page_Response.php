<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Page responses for the D-010 shared UI. The pages use the platform's
 * ojp-* parts and only --ojp-* tokens, so they opt into the platform dark
 * mode and layouts where Addon API 1.3 (OJ Platform 2.1.0) provides them,
 * and fall back to the plain html() response on older platforms.
 */
final class Page_Response {
    /** Console screens: standard width, dark mode supported. */
    public static function console(string $title, string $body): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'standard', true);
        }

        return AddonPageResponseV1::html($title, $body);
    }

    /** The notebook: immersive layout (sidebar folded away), dark mode supported. */
    public static function notebook(string $title, string $body): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'immersive', true);
        }

        return AddonPageResponseV1::html($title, $body);
    }
}
