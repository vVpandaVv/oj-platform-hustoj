<?php
declare(strict_types=1);
// Keep fixture-specific ABSPATH values. The production autoloader itself requires
// WordPress bootstrapping, so standalone tests use this narrowly scoped loader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OJML\\Playgrounds\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});
