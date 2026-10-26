<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Ui\Editor\AceCoreAssetLocator;

final class WordPressAceCoreAssetLocator implements AceCoreAssetLocator
{
    private const DIRECTORY = 'resources/features/ace/vendor/';

    public function __construct(private readonly PluginEnvironment $environment)
    {
    }

    public function urls(): array
    {
        $base = $this->url(self::DIRECTORY);

        return [
            'core' => $base . 'ace.js',
            'base' => $base,
        ];
    }

    private function url(string $relativePath): string
    {
        $url = function_exists('plugins_url')
            ? (string) plugins_url(
                $relativePath,
                $this->environment->pluginFile()
            )
            : '/' . ltrim($relativePath, '/');

        return rtrim($url, '/') . '/';
    }
}
