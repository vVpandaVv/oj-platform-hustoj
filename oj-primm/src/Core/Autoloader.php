<?php
declare(strict_types=1);
namespace OjPrimm\Core;

final class Autoloader
{
    public static function register(): void { spl_autoload_register([self::class, 'autoload']); }
    public static function autoload(string $class): void
    {
        if (! str_starts_with($class, 'OjPrimm\\')) { return; }
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
}
