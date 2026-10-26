<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Modules\Blockly\Contract\BlocklyAssets;

final class WordPressBlocklyAssets implements BlocklyAssets
{
    /**
     * @var array<string, string>
     */
    private const FILES = [
        'core' => 'resources/features/blockly/vendor/blockly_compressed.js',
        'blocks' => 'resources/features/blockly/vendor/blocks_compressed.js',
        'messages' => 'resources/features/blockly/vendor/msg/ko.js',
        'python' => 'resources/features/blockly/vendor/python_compressed.js',
        'editor' => 'resources/features/blockly/js/editor.js',
    ];

    /**
     * @var array<string, string>
     */
    private const VENDOR_SHA256 = [
        'core' => '2496815c27b415707c98c808489cdeddf27b45431af35d275f67e8c2c36c903a',
        'blocks' => 'd81768b75d6557da6d6e5604d27ee13ef791ac45b87478e8cfa2cb0df319a2c6',
        'messages' => 'ba71d9a8aee7796c115381e595707ed96033ba2123b3adb68ecb664e510e6831',
        'python' => '5799539eb44e470425f4b2a184405520abd3ce41d89307eda6cafe587cd91894',
    ];

    public function __construct(
        private readonly PluginEnvironment $environment
    ) {
    }

    public function isReady(): bool
    {
        foreach ($this->diagnostics() as $diagnostic) {
            if (! $diagnostic['exists'] || ! $diagnostic['integrity']) {
                return false;
            }
        }

        return true;
    }

    public function urls(): array
    {
        $urls = [];
        foreach (self::FILES as $key => $relativePath) {
            $urls[$key] = function_exists('plugins_url')
                ? (string) plugins_url(
                    $relativePath,
                    $this->environment->pluginFile()
                )
                : '/' . ltrim($relativePath, '/');
        }

        return $urls;
    }

    public function diagnostics(): array
    {
        $result = [];
        foreach (self::FILES as $key => $relativePath) {
            $absolutePath = $this->absolutePath($relativePath);
            $exists = is_file($absolutePath);
            $sha256 = $exists
                ? (string) hash_file('sha256', $absolutePath)
                : '';
            $expected = self::VENDOR_SHA256[$key] ?? null;
            $result[$key] = [
                'path' => $relativePath,
                'exists' => $exists,
                'integrity' => $exists && (
                    $expected === null
                    || hash_equals($expected, $sha256)
                ),
                'bytes' => $exists ? (int) filesize($absolutePath) : 0,
                'sha256' => $sha256,
            ];
        }

        return $result;
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->environment->pluginDirectory()
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
