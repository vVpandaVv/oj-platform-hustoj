<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;

final class WordPressUiAssetLocator implements UiAssetLocator
{
    public function __construct(private readonly PluginEnvironment $environment)
    {
    }

    public function stylesheetUrl(): string
    {
        return $this->versionedUrl('resources/ui/css/oj-ui-system.css');
    }

    public function scriptUrl(): string
    {
        return $this->versionedUrl('resources/ui/js/oj-ui.js');
    }

    public function mathJaxLoaderUrl(): string
    {
        return $this->versionedUrl('resources/features/mathjax/js/loader.js');
    }

    public function iconSpriteUrl(): string
    {
        return $this->versionedUrl('resources/ui/icons/lucide.svg');
    }

    public function faviconUrl(): string
    {
        return $this->versionedUrl('resources/ui/icons/favicon.svg');
    }

    private function versionedUrl(string $path): string
    {
        $url = plugins_url($path, $this->environment->pluginFile());
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url
            . $separator
            . 'ver='
            . rawurlencode($this->environment->version());
    }
}
