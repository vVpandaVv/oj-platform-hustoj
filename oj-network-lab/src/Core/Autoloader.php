<?php
declare(strict_types=1);
namespace OjNetworkLab\Core;
final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'OjNetworkLab\\';
            if (!str_starts_with($class, $prefix)) { return; }
            $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        });
    }
}
