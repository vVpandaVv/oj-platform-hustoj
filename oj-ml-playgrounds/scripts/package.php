<?php
/**
 * Deterministic release ZIP and hash manifest. Run verify.ps1 first.
 * Usage: php scripts/package.php /absolute/output/directory
 * Output directory must exist, be outside the plugin, and is never overwritten.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !class_exists('ZipArchive') || !function_exists('mb_check_encoding')) { exit(1); }
$root = realpath(dirname(__DIR__));
$output = isset($argv[1]) ? realpath($argv[1]) : false;
if (!is_string($output) || !is_dir($output) || str_starts_with(strtolower($output), strtolower($root))) {
    throw new RuntimeException('Choose an existing output directory outside this plugin.');
}
$package = require $root . '/plugin-package.php';
$version = $package['version'];
$main = file_get_contents($root . '/oj-ml-playgrounds.php');
$readme = file_get_contents($root . '/readme.txt');
if (!preg_match('/^\s*\* Version:\s*' . preg_quote($version, '/') . '\s*$/m', $main)
    || !str_contains($main, "define( 'OJ_ML_PLAYGROUNDS_VERSION', '" . $version . "' );")
    || !str_contains($readme, 'Stable tag: ' . $version)) { throw new RuntimeException('Version metadata mismatch.'); }
$files = [];
$fixed = ['oj-ml-playgrounds.php', 'plugin-package.php', 'uninstall.php', 'README.md', 'readme.txt',
    'INSTALL.md', 'CHANGELOG.md', 'SECURITY.md', 'LICENSE.txt', 'UPSTREAM.md'];
foreach ($fixed as $file) { if (!is_file($root . '/' . $file)) { throw new RuntimeException('Required file missing: ' . $file); } $files[] = $file; }
foreach (['src', 'resources', 'docs', 'LICENSES', 'patches'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) { throw new RuntimeException('Symlink is not allowed in a release.'); }
        if ($file->isFile()) { $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)); }
    }
}
sort($files, SORT_STRING);
$hashes = []; $bytes = 0;
foreach ($files as $file) {
    if (preg_match('~(^|/)(\.git|node_modules|vendor|tests|\.env|\.DS_Store)(/|$)~', $file)) { throw new RuntimeException('Unexpected package path.'); }
    $path = $root . '/' . $file;
    if (is_link($path)) { throw new RuntimeException('Symlink file is not allowed.'); }
    $body = file_get_contents($path);
    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php', 'js', 'css', 'json', 'html', 'md', 'txt', 'patch'], true)
        && (!mb_check_encoding($body, 'UTF-8') || str_starts_with($body, "\xEF\xBB\xBF"))) {
        throw new RuntimeException('UTF-8/BOM check failed: ' . $file);
    }
    if (str_starts_with($file, 'src/') && preg_match('~CodingPanda\\\\OjPlatform\\\\(?!AddonApi\\\\)~', $body)) {
        throw new RuntimeException('Private OJ API coupling: ' . $file);
    }
    $hashes[$file] = ['sha256' => hash('sha256', $body), 'bytes' => strlen($body)];
    $bytes += strlen($body);
}
$manifest = json_encode(['plugin' => $package['id'], 'version' => $version, 'schemaVersion' => 4,
    'upstreamVersion' => '0.12.1', 'sourceEpoch' => 1788739200, 'files' => $hashes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$name = $package['id'] . '-' . $version . '.zip';
$target = $output . DIRECTORY_SEPARATOR . $name;
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Output exists or is not writable.'); }
foreach ($files as $file) {
    $entry = 'oj-ml-playgrounds/' . $file;
    if (!$zip->addFile($root . '/' . $file, $entry) || !$zip->setMtimeName($entry, 1788739200)
        || !$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
        throw new RuntimeException('Failed to add: ' . $file);
    }
    $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 9);
}
$entry = 'oj-ml-playgrounds/release-manifest.json';
$zip->addFromString($entry, $manifest);
$zip->setMtimeName($entry, 1788739200);
$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16);
$zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 9);
if (!$zip->close()) { throw new RuntimeException('ZIP finalize failed.'); }
$checkZip = new ZipArchive();
if ($checkZip->open($target, ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('ZIP integrity failed.'); }
foreach ($hashes as $file => $expected) {
    $body = $checkZip->getFromName('oj-ml-playgrounds/' . $file);
    if (!is_string($body) || !hash_equals($expected['sha256'], hash('sha256', $body))) { throw new RuntimeException('Round-trip mismatch: ' . $file); }
}
if ($checkZip->numFiles !== count($files) + 1) { throw new RuntimeException('Unexpected ZIP entries.'); }
$checkZip->close();
$sha = hash_file('sha256', $target);
foreach ([$target . '.sha256' => $sha . '  ' . $name . "\n", $target . '.manifest.json' => $manifest] as $path => $contents) {
    $handle = fopen($path, 'xb');
    if ($handle === false) { throw new RuntimeException('Sidecar already exists.'); }
    try { if (fwrite($handle, $contents) !== strlen($contents)) { throw new RuntimeException('Sidecar write failed.'); } }
    finally { fclose($handle); }
}
echo json_encode(['file' => $target, 'sha256' => $sha, 'files' => count($files) + 1,
    'uncompressedBytes' => $bytes + strlen($manifest), 'zipBytes' => filesize($target)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
