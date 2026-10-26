<?php
/** PHP development-server front controller; all application handling is WordPress. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { exit(1); }
$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$candidate = realpath($root . $path);
if (is_string($candidate) && str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $root) . '/') && is_file($candidate)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
