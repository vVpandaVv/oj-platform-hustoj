<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Page responses for the D-010 shared UI. The markup uses only the
 * platform's ojp-* parts and --ojp-* tokens, so the pages opt into the
 * platform dark mode where it exists (OJ Platform 2.1.0, Addon API 1.3).
 */
final class Page_Response {
    public static function make(string $title, string $body, int $status = 200): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'standard', true, $status);
        }

        return AddonPageResponseV1::html($title, $body, $status);
    }

    /** One-sentence empty or missing state with a way back. */
    public static function empty_state(string $title, string $message, string $link_url = '', string $link_label = ''): string {
        $html = '<div class="ojp-empty-state"><div><strong>' . esc_html($title) . '</strong>'
            . '<p>' . esc_html($message) . '</p>';
        if ('' !== $link_url) {
            $html .= '<a class="ojp-button ojp-button--small" href="' . esc_url($link_url) . '">' . esc_html($link_label) . '</a>';
        }

        return $html . '</div></div>';
    }
}
