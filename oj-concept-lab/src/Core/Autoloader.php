<?php
declare(strict_types=1);
namespace OjConceptLab\Core;
final class Autoloader {
    public static function register(): void {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'OjConceptLab\\';
            if (!str_starts_with($class, $prefix)) return;
            $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) require_once $path;
        });
    }
}
