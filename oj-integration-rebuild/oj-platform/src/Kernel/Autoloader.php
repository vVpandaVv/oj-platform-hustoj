<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel;

final class Autoloader
{
    private static bool $registered = false;

    public static function register(string $pluginRoot): void
    {
        if (self::$registered) {
            return;
        }

        $pluginRoot = rtrim($pluginRoot, '/\\') . DIRECTORY_SEPARATOR;
        $prefixes = [
            'CodingPanda\\OjPlatform\\Modules\\' => $pluginRoot . 'modules' . DIRECTORY_SEPARATOR,
            'CodingPanda\\OjPlatform\\' => $pluginRoot . 'src' . DIRECTORY_SEPARATOR,
        ];

        spl_autoload_register(static function (string $className) use ($prefixes): void {
            foreach ($prefixes as $prefix => $baseDirectory) {
                if (! str_starts_with($className, $prefix)) {
                    continue;
                }

                $relativeClass = substr($className, strlen($prefix));
                $path = $baseDirectory
                    . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                    . '.php';

                if (is_file($path)) {
                    require_once $path;
                }

                return;
            }
        });

        self::$registered = true;
    }
}
