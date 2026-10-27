<?php

declare(strict_types=1);

namespace OjAddonStarter\Core;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'OjAddonStarter\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative) !== 1) {
                return;
            }
            $file = OJ_ADDON_STARTER_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
