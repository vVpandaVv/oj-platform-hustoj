<?php
declare(strict_types=1);

namespace FocusTracker\Core;

final class Autoloader {
    public static function register(): void {
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload(string $class): void {
        $prefix = 'FocusTracker\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if (false === $relative) {
            return;
        }

        $path = FOCUS_TRACKER_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

        // Some Windows PHP builds report is_readable=false for readable
        // project files. require_once still enforces actual read permission.
        if (is_file($path)) {
            require_once $path;
        }
    }
}
