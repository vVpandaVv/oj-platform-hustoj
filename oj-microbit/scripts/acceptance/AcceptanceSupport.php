<?php
declare(strict_types=1);
namespace OjMicrobit\Acceptance;

use RuntimeException;

final class AcceptanceSupport
{
    public static function requireCli(): void
    {
        if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80300 || !function_exists('posix_geteuid')) { throw new RuntimeException('php_posix_cli_required'); }
        if (posix_geteuid() === 0) { throw new RuntimeException('root_execution_refused'); }
    }
    public static function arguments(array $args, array $values, array $flags): array
    {
        $result = [];
        for ($index = 0; $index < count($args); $index++) {
            $key = $args[$index];
            if (in_array($key, $flags, true)) { if (isset($result[$key])) { throw new RuntimeException('invalid_arguments'); } $result[$key] = true; continue; }
            if (!in_array($key, $values, true) || !isset($args[$index + 1]) || str_starts_with($args[$index + 1], '--')) { throw new RuntimeException('invalid_arguments'); }
            $value = $args[++$index];
            if (in_array($key, ['--class-id', '--group-id'], true)) { $result[$key][] = $value; }
            else { if (isset($result[$key])) { throw new RuntimeException('invalid_arguments'); } $result[$key] = $value; }
        }
        return $result;
    }
    public static function privateJson(string $path): array
    {
        $before = lstat($path);
        if ($before === false || is_link($path) || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0777) !== 0600
            || $before['uid'] !== posix_geteuid() || $before['nlink'] !== 1 || $before['size'] > 65536) { throw new RuntimeException('unsafe_private_config'); }
        $file = fopen($path, 'rb');
        if ($file === false) { throw new RuntimeException('private_config_read_failed'); }
        try {
            $opened = fstat($file);
            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0777) !== 0600 || $opened['uid'] !== posix_geteuid() || $opened['nlink'] !== 1) { throw new RuntimeException('unsafe_private_config'); }
            $text = stream_get_contents($file, 65537);
            if ($text === false || strlen($text) > 65536) { throw new RuntimeException('invalid_config'); }
            return self::json($text);
        } finally { fclose($file); }
    }
    public static function json(string $text): array
    {
        try { $value = json_decode($text, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new RuntimeException('invalid_config'); }
        if (!is_array($value) || !str_starts_with(ltrim($text), '{') || ($value !== [] && array_is_list($value))) { throw new RuntimeException('invalid_config'); }
        return $value;
    }
    public static function writeNewPrivate(string $path, array $value): void
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) { throw new RuntimeException('invalid_output_path'); }
        $parent = realpath(dirname($path)); $stat = $parent === false ? false : lstat($parent);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022) !== 0) { throw new RuntimeException('unsafe_output_directory'); }
        if (file_exists($path) || is_link($path)) { throw new RuntimeException('output_already_exists'); }
        $oldMask = umask(0077); $file = fopen($path, 'x+b'); umask($oldMask);
        if ($file === false) { throw new RuntimeException('private_output_create_failed'); }
        $created = fstat($file); $complete = false;
        try {
            if ($created === false || ($created['mode'] & 0170000) !== 0100000 || $created['uid'] !== posix_geteuid() || $created['nlink'] !== 1 || ($created['mode'] & 0777) !== 0600) { throw new RuntimeException('unsafe_private_output'); }
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($file, $text) !== strlen($text) || !fflush($file)) { throw new RuntimeException('private_output_write_failed'); }
            $complete = true;
        } finally {
            fclose($file);
            if (!$complete && $created !== false) {
                $now = lstat($path);
                if ($now !== false && !is_link($path) && $now['dev'] === $created['dev'] && $now['ino'] === $created['ino']) { unlink($path); }
            }
        }
    }
    public static function wordpressRoot(mixed $path): string
    {
        if (!is_string($path) || preg_match('/[\x00-\x1f\x7f]/', $path)) { throw new RuntimeException('invalid_wordpress_root'); }
        $root = realpath($path);
        if ($root === false || !is_file($root . '/wp-load.php') || !is_readable($root . '/wp-load.php')) { throw new RuntimeException('wordpress_not_found'); }
        return $root;
    }
    public static function requireOutsideWebRoot(string $root, string $path, string $error): void
    {
        $target = realpath($path);
        if ($target === false) {
            $parent = realpath(dirname($path));
            if ($parent === false) { throw new RuntimeException($error); }
            $target = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/'); $target = str_replace('\\', '/', $target);
        if (PHP_OS_FAMILY === 'Windows') { $root = strtolower($root); $target = strtolower($target); }
        if ($target === $root || str_starts_with($target, $root . '/')) { throw new RuntimeException($error); }
    }
    public static function baseUrl(mixed $url): string
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new RuntimeException('invalid_base_url'); }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { throw new RuntimeException('invalid_base_url'); }
        $host = strtolower($parts['host']);
        if (preg_match('/[^\x21-\x7e]/', $host)) { throw new RuntimeException('invalid_base_url'); }
        if ($parts['scheme'] === 'http' && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) { throw new RuntimeException('https_or_loopback_required'); }
        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $path)) { throw new RuntimeException('invalid_base_url'); }
        $port = $parts['port'] ?? null;
        $suffix = $port !== null && !(($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80)) ? ':' . $port : '';
        return $parts['scheme'] . '://' . $host . $suffix . $path;
    }
    public static function marker(string $root, array $config): void
    {
        if (($config['testServer'] ?? null) !== true) { throw new RuntimeException('test_server_confirmation_required'); }
        $path = $root . '/wp-content/oj-microbit-acceptance.json'; $stat = lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0022) !== 0 || !in_array($stat['uid'], [0, posix_geteuid()], true) || $stat['size'] > 4096) { throw new RuntimeException('test_server_marker_required'); }
        $file = fopen($path, 'rb');
        if ($file === false) { throw new RuntimeException('test_server_marker_required'); }
        try {
            $opened = fstat($file);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0022) !== 0) { throw new RuntimeException('test_server_marker_required'); }
            $text = stream_get_contents($file, 4097);
            if ($text === false || strlen($text) > 4096) { throw new RuntimeException('test_server_marker_required'); }
            $marker = self::json($text);
        } finally { fclose($file); }
        if (($marker['testServer'] ?? null) !== true || ($marker['marker'] ?? null) !== 'oj-microbit-acceptance-v1'
            || self::baseUrl($marker['baseUrl'] ?? null) !== self::baseUrl($config['baseUrl'] ?? null)) { throw new RuntimeException('test_server_marker_mismatch'); }
    }
    public static function error(string $code, string $format): array
    {
        $known = ['php_posix_cli_required', 'root_execution_refused', 'invalid_arguments', 'unsafe_private_config', 'private_config_read_failed', 'invalid_config',
            'invalid_wordpress_root', 'wordpress_not_found', 'wordpress_bootstrap_aborted', 'wordpress_bootstrap_failed', 'multisite_not_supported', 'tool_plugin_location_mismatch',
            'invalid_probe_options', 'database_probe_failed', 'invalid_output_path', 'unsafe_output_directory', 'output_already_exists', 'private_output_create_failed', 'unsafe_private_output', 'private_output_write_failed',
            'invalid_base_url', 'https_or_loopback_required', 'test_server_confirmation_required', 'test_server_marker_required', 'test_server_marker_mismatch',
            'full_wordpress_bootstrap_not_confirmed', 'site_url_mismatch', 'invalid_accounts', 'accounts_must_be_distinct', 'account_role_mismatch', 'nonce_generation_failed'];
        return ['format' => $format, 'ok' => false, 'error' => in_array($code, $known, true) ? $code : 'acceptance_check_failed', 'message' => '검사를 완료하지 못했습니다. 비밀값 없이 오류 코드와 체크리스트를 확인해 주세요.'];
    }
}
