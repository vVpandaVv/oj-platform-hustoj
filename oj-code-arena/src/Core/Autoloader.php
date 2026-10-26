<?php
declare(strict_types=1);

namespace OjCodeArena\Core;

final class Autoloader {
    public static function register(): void {
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload(string $class): void {
        $prefix = 'OjCodeArena\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $path = OJ_CODE_ARENA_PATH . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
}
