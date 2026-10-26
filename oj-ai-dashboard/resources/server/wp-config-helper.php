<?php
declare(strict_types=1);

// This file intentionally never loads WordPress or accepts HTTP requests.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function ojPrepareLiteral(string $literal): ?string
{
    $value = substr($literal, 1, -1);
    if ($literal[0] === '"') {
        return str_contains($value, '\\') || str_contains($value, '$') ? null : $value;
    }
    $decoded = '';
    for ($i = 0; $i < strlen($value); $i++) {
        if ($value[$i] === '\\' && isset($value[$i + 1]) && in_array($value[$i + 1], ['\\', "'"], true)) {
            $i++;
        }
        $decoded .= $value[$i];
    }
    return $decoded;
}

/** Reject conflicting or computed definitions instead of editing arbitrary PHP. */
function ojPrepareConfig(string $source, string $name, string $value): string
{
    if (str_contains($source, $name)) {
        $tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE), static fn ($token): bool =>
            ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'define'
                && ($tokens[$i + 1] ?? null) === '(' && ($tokens[$i + 3] ?? null) === ','
                && ($tokens[$i + 5] ?? null) === ')' && ($tokens[$i + 6] ?? null) === ';'
                && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                && is_array($tokens[$i + 4] ?? null) && $tokens[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING
                && ojPrepareLiteral($tokens[$i + 2][1]) === $name
                && ojPrepareLiteral($tokens[$i + 4][1]) === $value && substr_count($source, $name) === 1) {
                return $source;
            }
        }
        throw new RuntimeException($name . ': existing setting differs or is computed; no changes made.');
    }
    if (! str_starts_with($source, '<?php') || preg_match('/\bdeclare\s*\(/', $source)) {
        throw new RuntimeException('Unsupported wp-config.php layout; no changes made.');
    }
    return "<?php\ndefine(" . var_export($name, true) . ', ' . var_export($value, true) . ");\n" . substr($source, 5);
}

function ojPrepareMain(array $args): void
{
    if (count($args) !== 5 || ! in_array($args[1], ['--check', '--apply'], true)
        || ! in_array($args[3], ['OJ_AI_PRIVATE_STORAGE_DIR', 'OJ_AI_CLAMD_SOCKET'], true)) {
        throw new RuntimeException('Use install.sh; config helper expects --check|--apply WORDPRESS CONSTANT VALUE.');
    }
    $root = realpath($args[2]);
    if ($root === false || ! is_file($root . '/wp-settings.php') || ! is_file($root . '/wp-config.php')
        || is_link($root . '/wp-config.php')) {
        throw new RuntimeException('WordPress root or regular wp-config.php not found.');
    }
    $config = $root . '/wp-config.php';
    $lock = fopen($config, 'r');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another config update is running. Try again later.');
    }
    $source = stream_get_contents($lock);
    if ($source === false) {
        throw new RuntimeException('Cannot read wp-config.php.');
    }
    $next = ojPrepareConfig($source, $args[3], $args[4]);
    token_get_all($next, TOKEN_PARSE);
    if ($args[1] === '--check') {
        echo "Config check passed.\n";
        return;
    }
    if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Config updates require sudo on Linux.');
    }
    if ($next !== $source) {
        $backupDir = dirname($root) . '/.oj-server-backups';
        if (is_link($backupDir) || (file_exists($backupDir) && ! is_dir($backupDir))
            || (! is_dir($backupDir) && ! mkdir($backupDir, 0700))
            || ! chown($backupDir, 0) || ! chmod($backupDir, 0700)) {
            throw new RuntimeException('Cannot create private backup directory.');
        }
        $backup = $backupDir . '/wp-config-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.php';
        $temporary = tempnam(dirname($config), '.oj-config-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage config.');
        }
        try {
            if (file_put_contents($temporary, $next) !== strlen($next)) {
                throw new RuntimeException('Cannot write staged config.');
            }
            token_get_all($next, TOKEN_PARSE);
            if (! copy($config, $backup) || ! chmod($backup, 0600)) {
                throw new RuntimeException('Cannot create private backup.');
            }
            if (! chown($temporary, fileowner($config)) || ! chgrp($temporary, filegroup($config))
                || ! chmod($temporary, fileperms($config) & 0777) || ! rename($temporary, $config)) {
                throw new RuntimeException('Cannot replace config; backup: ' . $backup);
            }
            echo 'Config backup: ' . $backup . PHP_EOL;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    echo "Config ready.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        ojPrepareMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
