<?php
declare(strict_types=1);
namespace OjMicrobit\Core;
final class Autoloader
{
    public static function register(): void { spl_autoload_register([self::class, 'autoload']); }
    public static function autoload(string $class): void
    {
        if (!str_starts_with($class, 'OjMicrobit\\')) { return; }
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, 11)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
}
