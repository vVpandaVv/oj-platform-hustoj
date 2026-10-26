<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;

final class WordPressAceEditorAssets implements SubmissionCodeEditorAssets
{
    private const DIRECTORY = 'resources/features/ace/vendor/';

    public function __construct(
        private readonly PluginEnvironment $environment,
        private readonly SubmissionLanguageCatalog $languages
    ) {
    }

    public function isReady(): bool
    {
        foreach ($this->requiredFiles() as $relativePath) {
            if (! is_file($this->absolutePath($relativePath))) {
                return false;
            }
        }

        return true;
    }

    public function urls(): array
    {
        $base = $this->url(self::DIRECTORY);

        return [
            'core' => $base . 'ace.js',
            'base' => $base,
        ];
    }

    /** @return list<string> */
    private function requiredFiles(): array
    {
        $files = [self::DIRECTORY . 'ace.js'];
        foreach (AceEditorThemeCatalog::slugs() as $theme) {
            $files[] = self::DIRECTORY . 'theme-' . $theme . '.js';
        }
        foreach ($this->languages->editorModes() as $mode) {
            $files[] = self::DIRECTORY . 'mode-' . $mode . '.js';
        }

        return array_values(array_unique($files));
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->environment->pluginDirectory()
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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
