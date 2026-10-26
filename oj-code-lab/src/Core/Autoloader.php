<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Core;

final class Autoloader {
    public static function register(): void {
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload(string $class): void {
        $prefix = 'OjCodeLab\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if (false === $relative) {
            return;
        }

        $path = OJ_CODE_LAB_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
