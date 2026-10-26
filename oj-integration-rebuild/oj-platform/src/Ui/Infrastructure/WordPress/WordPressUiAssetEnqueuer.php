<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetEnqueuer;

final class WordPressUiAssetEnqueuer implements UiAssetEnqueuer
{
    private readonly WordPressUiAssetLocator $assets;

    public function __construct(PluginEnvironment $environment)
    {
        $this->assets = new WordPressUiAssetLocator($environment);
    }

    public function enqueue(): void
    {
        if (
            ! function_exists('wp_enqueue_style')
            || ! function_exists('wp_enqueue_script')
            || ! function_exists('plugins_url')
        ) {
            return;
        }

        wp_enqueue_style(
            'oj-platform-ui-system',
            $this->assets->stylesheetUrl(),
            [],
            null
        );
        wp_enqueue_script(
            'oj-platform-ui',
            $this->assets->scriptUrl(),
            [],
            null,
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_enqueue_script(
            'oj-platform-mathjax-loader',
            $this->assets->mathJaxLoaderUrl(),
            ['oj-platform-ui'],
            null,
            ['in_footer' => true, 'strategy' => 'defer']
        );
    }
}
