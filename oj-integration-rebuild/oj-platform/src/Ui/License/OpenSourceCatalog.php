<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\License;

use RuntimeException;

final class OpenSourceCatalog
{
    public function addons(?string $pluginRoot = null): array
    {
        $root = dirname(__DIR__, 3);
        $pluginRoot ??= defined('WP_PLUGIN_DIR') ? (string) constant('WP_PLUGIN_DIR')
            : (basename(dirname($root)) === 'oj-integration-rebuild' ? dirname($root, 2) : dirname($root));
        $definitions = require $root . '/config/addon-open-source.php';
        $addons = [];
        foreach ($definitions as $id => $definition) {
            $base = $pluginRoot . '/' . $id;
            if (! is_file($base . '/' . $definition['main']) || ! is_readable($base . '/' . $definition['main'])) {
                continue;
            }
            $header = (string) file_get_contents($base . '/' . $definition['main'], false, null, 0, 8192);
            preg_match('/^\s*\*\s*License:\s*([^\r\n]+)/mi', $header, $license);
            $notices = [];
            foreach ($definition['files'] as $path) {
                if (is_file($base . '/' . $path) && is_readable($base . '/' . $path)) {
                    $notices[] = ['path' => $path, 'text' => (string) file_get_contents($base . '/' . $path)];
                }
            }
            $addons[] = ['id' => $id, 'name' => $definition['name'], 'license' => trim($license[1] ?? '자체 코드 라이선스 미선언'), 'notices' => $notices];
        }
        return $addons;
    }

    public function platformLicense(): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/LICENSE.txt');
        if ($text === false) {
            throw new RuntimeException('플랫폼 라이선스 파일을 읽지 못했습니다.');
        }

        return $text;
    }

    public function components(): array
    {
        $root = dirname(__DIR__, 3);
        $components = require $root . '/config/open-source.php';
        foreach ($components as &$component) {
            $component['notices'] = [];
            foreach ($component['files'] as $file) {
                $text = file_get_contents($root . '/' . $file);
                if ($text === false) {
                    throw new RuntimeException('오픈소스 고지 파일을 읽지 못했습니다.');
                }
                $component['notices'][] = ['path' => $file, 'text' => $text];
            }
        }
        unset($component);

        return $components;
    }
}
