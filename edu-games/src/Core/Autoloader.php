<?php
declare(strict_types=1);

namespace EduGames\Core;

final class Autoloader {
    public static function register(): void {
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload(string $class): void {
        $prefix = 'EduGames\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if (false === $relative) {
            return;
        }

        $path = EDU_GAMES_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
