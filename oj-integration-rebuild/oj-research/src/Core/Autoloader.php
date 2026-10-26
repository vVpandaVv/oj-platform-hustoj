<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Core;

final class Autoloader
{
    private static bool $registered = false;
    public static function register(string $root): void
    {
        if (self::$registered) { return; }
        self::$registered = true;
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'CodingPanda\\OjResearch\\';
            if (! str_starts_with($class, $prefix)) { return; }
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        });
    }
}
