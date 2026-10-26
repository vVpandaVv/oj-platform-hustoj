<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Page responses for the D-010 shared UI. Console screens use only the
 * platform's ojp-* parts and --ojp-* tokens, so they opt into the platform
 * dark mode where it exists (OJ Platform 2.1.0, Addon API 1.3). The game
 * screens (play, board) are immersive and keep their own dark game art, so
 * they stay out of the platform colour-scheme switch.
 */
final class Page_Response {
    public static function make(string $title, string $body, string $layout = 'standard', bool $dark_mode = true, int $status = 200): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, $layout, $dark_mode, $status);
        }

        return AddonPageResponseV1::html($title, $body, $status);
    }

    /** Game screens: full width without the sidebar, own colours. */
    public static function immersive(string $title, string $body): AddonPageResponseV1 {
        return self::make($title, $body, 'immersive', false);
    }
}
