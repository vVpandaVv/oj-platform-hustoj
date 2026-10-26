<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Pages that follow platform color tokens can opt into light/dark mode.
 * Older Addon API versions retain their existing HTML response contract.
 */
final class Page_Response {
    public static function console(string $title, string $body, int $status = 200): AddonPageResponseV1 {
        return self::themed($title, $body, $status);
    }

    public static function themed(string $title, string $body, int $status = 200): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'standard', true, $status);
        }

        return AddonPageResponseV1::html($title, $body, $status);
    }

    /** Page header in the shared shell style: eyebrow, h1, one-line description, optional actions. */
    public static function header(string $eyebrow, string $title, string $description, string $actions_html = ''): string {
        $html = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">' . esc_html($eyebrow) . '</p>'
            . '<h1>' . esc_html($title) . '</h1>';
        if ('' !== $description) {
            $html .= '<p>' . esc_html($description) . '</p>';
        }
        $html .= '</div>';
        if ('' !== $actions_html) {
            $html .= '<div class="ojp-heading-actions">' . $actions_html . '</div>';
        }

        return $html . '</header>';
    }
}
