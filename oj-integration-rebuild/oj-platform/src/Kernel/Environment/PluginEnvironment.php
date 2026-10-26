<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Environment;

use InvalidArgumentException;

final class PluginEnvironment
{
    public function __construct(
        private readonly string $pluginFile,
        private readonly string $pluginDirectory,
        private readonly string $version,
        private readonly string $minimumWordPressVersion,
        private readonly string $minimumPhpVersion
    ) {
        if ($this->pluginFile === '' || $this->pluginDirectory === '') {
            throw new InvalidArgumentException('Plugin file and directory are required.');
        }

        if (
            $this->version === ''
            || $this->minimumWordPressVersion === ''
            || $this->minimumPhpVersion === ''
        ) {
            throw new InvalidArgumentException('Plugin version requirements are required.');
        }
    }

    public static function fromPluginFile(
        string $pluginFile,
        string $version,
        string $minimumWordPressVersion,
        string $minimumPhpVersion
    ): self {
        return new self(
            $pluginFile,
            dirname($pluginFile) . DIRECTORY_SEPARATOR,
            $version,
            $minimumWordPressVersion,
            $minimumPhpVersion
        );
    }

    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    public function pluginDirectory(): string
    {
        return $this->pluginDirectory;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function minimumWordPressVersion(): string
    {
        return $this->minimumWordPressVersion;
    }

    public function minimumPhpVersion(): string
    {
        return $this->minimumPhpVersion;
    }
}
