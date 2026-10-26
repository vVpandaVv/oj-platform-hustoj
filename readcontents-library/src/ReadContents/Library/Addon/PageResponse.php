<?php
/**
 * Page responses for the D-010 shared UI.
 *
 * @package ReadContentsLibrary\Addon
 */

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Wraps AddonPageResponseV1 so the pages use the Addon API 1.3 layouts
 * (OJ Platform 2.1.0+) when present and fall back to html() on 2.0.x.
 */
final class PageResponse
{
    /**
     * Library index, topic overview and not-found pages. Their CSS uses only
     * --ojp-* tokens, so they opt into the platform dark mode.
     */
    public static function reader(string $title, string $body, int $status = 200): AddonPageResponseV1
    {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'standard', true, $status);
        }

        return AddonPageResponseV1::html($title, $body, $status);
    }

    /** Lessons use the available width for the outline and follow the platform colour scheme. */
    public static function lesson(string $title, string $body): AddonPageResponseV1
    {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'wide', true);
        }

        return AddonPageResponseV1::html($title, $body);
    }

    /** Editor workspace: the shell collapses its sidebar and uses the full width. */
    public static function immersive(string $title, string $body): AddonPageResponseV1
    {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, 'immersive');
        }

        return AddonPageResponseV1::html($title, $body);
    }

    /** One-sentence empty or missing state with an optional way back. */
    public static function emptyState(string $title, string $message, string $linkUrl = '', string $linkLabel = ''): string
    {
        $html = '<div class="ojp-empty-state"><div><strong>' . esc_html($title) . '</strong>'
            . '<p>' . esc_html($message) . '</p>';

        if ('' !== $linkUrl) {
            $html .= '<a class="ojp-button ojp-button--small" href="' . esc_url($linkUrl) . '">' . esc_html($linkLabel) . '</a>';
        }

        return $html . '</div></div>';
    }

    /** Learner page header; the eyebrow names the real menu group. */
    public static function header(string $title, string $description): string
    {
        return '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">학습 활동</p>'
            . '<h1>' . esc_html($title) . '</h1>'
            . ('' !== $description ? '<p>' . esc_html($description) . '</p>' : '')
            . '</div></header>';
    }

    /** Public URL of the library index. */
    public static function libraryUrl(): string
    {
        return home_url(AddonRoutes::BASE_PATH);
    }

    private function __construct()
    {
    }
}
