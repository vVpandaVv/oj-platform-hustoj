<?php
declare(strict_types=1);

/**
 * Canonical Platform 2.5.5 ZIP replacement on the owned Oracle acceptance site.
 *
 * php mysql-platform-package.php inspect|install|verify
 *
 * No caller-supplied destination, database, package, or version is accepted.
 * The separate mysql-package-acceptance.php pre/post phases protect all data.
 */
function mysql_platform_package_path(string $path): string {
    return strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
}
function mysql_platform_package_assert(bool $condition, string $code): void {
    if (! $condition) { throw new RuntimeException($code); }
}
function mysql_platform_package_config(array $config, string $workspace): array {
    $workspace = (string) realpath($workspace);
    mysql_platform_package_assert(mysql_platform_package_path($workspace) === 'd:/프로젝트/plugins', 'workspace_guard');
    $run = realpath($workspace . '/_notes/tmp/focus-tracker-mysql8');
    mysql_platform_package_assert($run !== false
        && mysql_platform_package_path($run) === mysql_platform_package_path($workspace . '/_notes/tmp/focus-tracker-mysql8'), 'run_guard');
    $wordpress = realpath((string) ($config['wpPath'] ?? ''));
    $artifacts = realpath((string) ($config['outputDir'] ?? ''));
    mysql_platform_package_assert($wordpress !== false && $artifacts !== false
        && mysql_platform_package_path($wordpress) === mysql_platform_package_path($run . '/wordpress')
        && mysql_platform_package_path($artifacts) === mysql_platform_package_path($run . '/artifacts'), 'site_path_guard');
    mysql_platform_package_assert(($config['home'] ?? '') === 'http://127.0.0.1:33918'
        && ($config['baseUrl'] ?? '') === 'http://127.0.0.1:33918'
        && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_mysql8_20261028', 'site_identity_guard');
    $plugins = realpath($wordpress . '/wp-content/plugins');
    $destination = realpath($wordpress . '/wp-content/plugins/oj-platform');
    mysql_platform_package_assert($plugins !== false && $destination !== false
        && mysql_platform_package_path($plugins) === mysql_platform_package_path($wordpress . '/wp-content/plugins')
        && mysql_platform_package_path($destination) === mysql_platform_package_path($plugins . '/oj-platform')
        && ! is_link($wordpress) && ! is_link($plugins) && ! is_link($destination), 'replacement_target_guard');
    $text = file_get_contents($wordpress . '/wp-config.php');
    mysql_platform_package_assert(is_string($text), 'wp_config_read');
    // Inspect native generated constant literals without evaluating wp-config or
    // touching any database. Credentials are never parsed, printed, or saved.
    foreach (array('DB_NAME' => 'focus_acceptance_mysql8_20261028', 'DB_HOST' => '127.0.0.1:33917',
        'WP_HOME' => 'http://127.0.0.1:33918', 'WP_SITEURL' => 'http://127.0.0.1:33918',
        'WP_ENVIRONMENT_TYPE' => 'local') as $key => $value) {
        $pattern = '/^define\(\s*([\'"])' . $key . '\1\s*,\s*([\'"])' . preg_quote($value, '/') . '\2\s*\);\s*$/m';
        mysql_platform_package_assert(preg_match($pattern, $text) === 1, 'wp_config_identity_guard');
    }
    foreach (array('DISABLE_WP_CRON', 'WP_HTTP_BLOCK_EXTERNAL') as $key) {
        mysql_platform_package_assert(preg_match('/^define\(\s*([\'"])' . $key . '\1\s*,\s*true\s*\);\s*$/m', $text) === 1, 'wp_config_safety_guard');
    }
    mysql_platform_package_assert(preg_match('/\$table_prefix\s*=\s*([\'"])ftreal_\1\s*;/', $text) === 1, 'wp_prefix_guard');
    unset($text);
    return array('workspace' => $workspace, 'run' => $run, 'wordpress' => $wordpress,
        'artifacts' => $artifacts, 'plugins' => $plugins, 'destination' => $destination);
}
function mysql_platform_package_entry(string $name): ?string {
    mysql_platform_package_assert(preg_match('//u', $name) === 1 && ! str_contains($name, '\\')
        && ! str_contains($name, '//') && preg_match('/[\x00-\x1f\x7f:?*"<>|]/', $name) !== 1
        && str_starts_with($name, 'oj-platform/'), 'zip_entry_guard');
    $relative = substr($name, strlen('oj-platform/'));
    foreach (explode('/', rtrim($relative, '/')) as $part) {
        mysql_platform_package_assert($part !== '.' && $part !== '..'
            && ($part === '' || (rtrim($part, ' .') === $part && preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/i', $part) !== 1)), 'zip_segment_guard');
    }
    if ($relative === '' || str_ends_with($name, '/')) { return null; }
    return strtolower($relative);
}
function mysql_platform_package_manifest(array $manifest, string $sha, int $bytes, int $entries): void {
    mysql_platform_package_assert(($manifest['plugin'] ?? '') === 'oj-platform'
        && ($manifest['version'] ?? '') === '2.5.5'
        && ($manifest['package'] ?? '') === 'oj-platform-2.5.5.zip'
        && ($manifest['source_tag'] ?? '') === 'oj-platform-v2.5.5'
        && ($manifest['source_dirty'] ?? true) === false
        && preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['source_commit'] ?? '')) === 1
        && ($manifest['sha256'] ?? '') === $sha
        && (int) ($manifest['bytes'] ?? -1) === $bytes
        && (int) ($manifest['entries'] ?? -1) === $entries, 'release_manifest_guard');
}
function mysql_platform_package_read(array $paths): array {
    mysql_platform_package_assert(class_exists('ZipArchive'), 'zip_extension');
    $release = realpath($paths['workspace'] . '/_releases/oj-platform/2.5.5');
    $zipPath = realpath($paths['workspace'] . '/_releases/oj-platform/2.5.5/oj-platform-2.5.5.zip');
    mysql_platform_package_assert($release !== false && $zipPath !== false
        && mysql_platform_package_path($release) === mysql_platform_package_path($paths['workspace'] . '/_releases/oj-platform/2.5.5')
        && dirname($zipPath) === $release && basename($zipPath) === 'oj-platform-2.5.5.zip' && ! is_link($zipPath), 'canonical_release_guard');
    $checksumPath = realpath($zipPath . '.sha256');
    $manifestPath = realpath($release . '/oj-platform-2.5.5.manifest.json');
    mysql_platform_package_assert($checksumPath !== false && $manifestPath !== false
        && dirname($checksumPath) === $release && dirname($manifestPath) === $release
        && basename($checksumPath) === 'oj-platform-2.5.5.zip.sha256'
        && basename($manifestPath) === 'oj-platform-2.5.5.manifest.json'
        && ! is_link($checksumPath) && ! is_link($manifestPath), 'canonical_release_metadata_guard');
    $sha = hash_file('sha256', $zipPath);
    $checksum = file_get_contents($checksumPath);
    mysql_platform_package_assert(is_string($sha) && is_string($checksum)
        && preg_match('/^([a-f0-9]{64})\s+oj-platform-2\.5\.5\.zip\s*$/D', trim($checksum), $match) === 1
        && hash_equals($sha, $match[1]), 'release_checksum_guard');
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $zip = new ZipArchive();
    mysql_platform_package_assert($zip->open($zipPath, ZipArchive::CHECKCONS) === true, 'release_zip_open');
    try {
        mysql_platform_package_assert($zip->numFiles > 0 && $zip->numFiles <= 20000, 'zip_entry_count');
        mysql_platform_package_manifest($manifest, $sha, (int) filesize($zipPath), $zip->numFiles);
        $hashes = array(); $names = array(); $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            mysql_platform_package_assert(is_string($name), 'zip_entry_name');
            $key = mysql_platform_package_entry($name);
            $identity = strtolower(rtrim($name, '/'));
            mysql_platform_package_assert(! isset($names[$identity]), 'zip_duplicate_path');
            $names[$identity] = true;
            $attributes = 0; $system = 0;
            mysql_platform_package_assert($zip->getExternalAttributesIndex($i, $system, $attributes), 'zip_attributes');
            mysql_platform_package_assert((($attributes >> 16) & 0170000) !== 0120000, 'zip_symlink');
            if ($key === null) { continue; }
            $stat = $zip->statIndex($i);
            mysql_platform_package_assert(is_array($stat) && (int) $stat['size'] <= 134217728
                && (int) ($stat['encryption_method'] ?? 0) === 0, 'zip_size_or_encryption');
            $total += (int) $stat['size'];
            mysql_platform_package_assert($total <= 536870912, 'zip_total_size');
            $data = $zip->getFromIndex($i);
            mysql_platform_package_assert(is_string($data) && strlen($data) === (int) $stat['size'], 'zip_file_read');
            $hashes[$key] = hash('sha256', $data);
            if ($key === 'oj-platform.php') {
                mysql_platform_package_assert(preg_match('/^[ \t\/*#@]*Version:\s*2\.5\.5\s*$/m', $data) === 1, 'zip_version_header');
            }
        }
        mysql_platform_package_assert(isset($hashes['oj-platform.php']) && count($hashes) > 0, 'zip_plugin_main');
        ksort($hashes, SORT_STRING);
        return array('zip' => $zipPath, 'sha256' => $sha, 'fileHashes' => $hashes, 'fileCount' => count($hashes));
    } finally { $zip->close(); }
}
function mysql_platform_package_files(string $root): array {
    $hashes = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        mysql_platform_package_assert(! $entry->isLink() && $entry->getRealPath() !== false
            && str_starts_with(mysql_platform_package_path($entry->getRealPath()) . '/', mysql_platform_package_path($root) . '/'), 'installed_file_boundary');
        if (! $entry->isFile()) { continue; }
        $key = strtolower(str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1)));
        mysql_platform_package_assert(! isset($hashes[$key]), 'installed_duplicate_path');
        $hash = hash_file('sha256', $entry->getPathname());
        mysql_platform_package_assert(is_string($hash), 'installed_file_read');
        $hashes[$key] = $hash;
    }
    ksort($hashes, SORT_STRING); return $hashes;
}
function mysql_platform_package_aggregate(array $hashes): string {
    ksort($hashes, SORT_STRING); $state = hash_init('sha256');
    foreach ($hashes as $name => $sha) { hash_update($state, $name . "\0" . $sha . "\n"); }
    return hash_final($state);
}
function mysql_platform_package_save(string $path, array $data): void {
    mysql_platform_package_assert(! file_exists($path), 'report_already_exists');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $file = fopen($path, 'xb');
    mysql_platform_package_assert($file !== false, 'report_open');
    try { mysql_platform_package_assert(fwrite($file, $json) === strlen($json), 'report_write'); }
    finally { fclose($file); }
}

// Pure guard functions can be loaded by a local boundary test. Main execution
// is CLI-only and cannot reach WordPress/DB until the official ZIP is verified.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) { return; }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$completed = false; $stage = 'mode';
register_shutdown_function(static function () use (&$completed): void {
    if (! $completed) { fwrite(STDERR, "Oracle Platform package verification did not complete.\n"); exit(1); }
});
try {
    $mode = $argv[1] ?? '';
    mysql_platform_package_assert(count($argv) === 2 && in_array($mode, array('inspect', 'install', 'verify'), true), 'mode_guard');
    $stage = 'configuration';
    $workspace = (string) realpath(dirname(__DIR__, 3));
    $configuration = $workspace . '/_notes/tmp/focus-tracker-mysql8/config.json';
    $config = json_decode((string) file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
    $paths = mysql_platform_package_config($config, $workspace);
    $stage = 'official_package';
    $package = mysql_platform_package_read($paths);
    if ($mode === 'inspect') {
        $completed = true;
        echo json_encode(array('mode' => $mode, 'officialVersion' => '2.5.5', 'fileCount' => $package['fileCount'], 'sha256' => $package['sha256'], 'nativeDatabaseRequests' => 0), JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    $stage = 'existing_target_boundary';
    // WordPress recursively removes the old package during overwrite. Inspect
    // every existing descendant before bootstrap/upgrader can follow a nested
    // junction or symlink; a post-install scan would be too late for that guard.
    $existing = mysql_platform_package_files($paths['destination']);
    mysql_platform_package_assert(isset($existing['oj-platform.php']), 'existing_platform_main');
    unset($existing);
    $stage = 'oracle_guard';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli('127.0.0.1', $config['dbUser'], $config['dbPassword'], 'focus_acceptance_mysql8_20261028', 33917);
    $oracle = $db->query('SELECT VERSION() AS version, @@version_comment AS vendor, @@datadir AS dataDirectory')->fetch_assoc();
    mysql_platform_package_assert(str_starts_with($oracle['version'], '8.0.') && $oracle['vendor'] === 'MySQL Community Server - GPL'
        && in_array(mysql_platform_package_path($oracle['dataDirectory']), array('q:/db-data', mysql_platform_package_path($paths['run'] . '/db-data')), true), 'owned_oracle_guard');
    $db->close(); unset($oracle);
    $stage = 'native_wordpress';
    $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_PORT'] = '33918';
    $_SERVER['DOCUMENT_ROOT'] = $paths['wordpress'];
    if ($mode === 'install') { define('FS_METHOD', 'direct'); }
    require $paths['wordpress'] . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    mysql_platform_package_assert(wp_get_environment_type() === 'local' && DISABLE_WP_CRON && WP_HTTP_BLOCK_EXTERNAL
        && DB_NAME === 'focus_acceptance_mysql8_20261028' && DB_HOST === '127.0.0.1:33917'
        && mysql_platform_package_path(ABSPATH) === mysql_platform_package_path($paths['wordpress'])
        && mysql_platform_package_path((string) realpath(WP_CONTENT_DIR)) === mysql_platform_package_path($paths['wordpress'] . '/wp-content')
        && mysql_platform_package_path(WP_PLUGIN_DIR) === mysql_platform_package_path($paths['plugins'])
        && $wpdb->prefix === 'ftreal_' && is_plugin_active('oj-platform/oj-platform.php'), 'native_wordpress_guard');
    if ($mode === 'install') {
        $stage = 'native_upgrader';
        mysql_platform_package_assert(! file_exists($paths['artifacts'] . '/mysql-platform-2.5.5-install-report.json'), 'install_report_exists');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $administrator = get_user_by('login', $config['admin']['login'] ?? $config['admin']['username'] ?? '');
        mysql_platform_package_assert($administrator instanceof WP_User && user_can($administrator, 'update_plugins')
            && user_can($administrator, 'install_plugins'), 'native_administrator_guard');
        wp_set_current_user((int) $administrator->ID);
        // Discard upgrader presentation; errors may include filesystem paths.
        ob_start();
        try {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->install($package['zip'], array('overwrite_package' => true, 'clear_update_cache' => false));
        } finally { ob_end_clean(); }
        mysql_platform_package_assert($result === true && is_plugin_active('oj-platform/oj-platform.php'), 'native_upgrader_result');
    } else {
        $stage = 'fresh_runtime';
        mysql_platform_package_assert(defined('OJ_PLATFORM_VERSION') && OJ_PLATFORM_VERSION === '2.5.5'
            && defined('OJ_PLATFORM_FILE') && mysql_platform_package_path(OJ_PLATFORM_FILE) === mysql_platform_package_path($paths['destination'] . '/oj-platform.php'), 'fresh_platform_runtime');
    }
    $stage = 'whole_package_sha256';
    $installed = mysql_platform_package_files($paths['destination']);
    mysql_platform_package_assert($installed === $package['fileHashes'], 'installed_package_mismatch');
    $report = array('passed' => true, 'mode' => $mode, 'version' => '2.5.5', 'capturedAt' => gmdate('c'),
        'installedByNativePluginUpgrader' => $mode === 'install', 'freshRuntimeVerified' => $mode === 'verify',
        'targetBoundaryCheckedBeforeBootstrap' => true,
        'officialZip' => array('name' => 'oj-platform-2.5.5.zip', 'sha256' => $package['sha256']),
        'fileCount' => count($installed), 'aggregateSha256' => mysql_platform_package_aggregate($installed),
        'missingExtraOrChangedFiles' => 0, 'files' => $installed,
        'dataPreservationScope' => 'The separate mysql-package-acceptance.php pre/post phases compare every database table. This file only verifies native installation and every packaged byte.');
    mysql_platform_package_save($paths['artifacts'] . '/mysql-platform-2.5.5-' . $mode . '-report.json', $report);
    $completed = true;
    echo json_encode(array('passed' => true, 'mode' => $mode, 'version' => '2.5.5', 'fileCount' => count($installed), 'aggregateSha256' => $report['aggregateSha256']), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Oracle Platform package verification failed: stage=' . $stage . ' class=' . get_class($error) . ' at=' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    exit(1);
}
