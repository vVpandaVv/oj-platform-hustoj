<?php
declare(strict_types=1);
// Release builder: require source provenance, then run syntax and all research regression tests.
$root = dirname(__DIR__);
require __DIR__ . '/release-state.php';
$spec = require $root . '/plugin-package.php';
$entry = file_get_contents($root . '/' . $spec['main_file']);
preg_match('/^\s*\* Version: (\S+)/m', $entry, $header);
preg_match("/define\('OJ_RESEARCH_VERSION', '([^']+)'\)/", $entry, $runtime);
if (($header[1] ?? null) !== $spec['version'] || ($runtime[1] ?? null) !== $spec['version']) { throw new RuntimeException('Package versions disagree'); }
$allowDirty = in_array('--allow-dirty', $argv, true);
$source = researchReleaseState(dirname($root), $spec['version'], $allowDirty);
$output = dirname($root) . '/dist';
if (! is_dir($output)) { mkdir($output, 0777, true); }
$base = $output . '/' . $spec['slug'] . '-' . $spec['version'];
if (file_exists($base . '.zip') || file_exists($base . '.manifest.json')) { throw new RuntimeException('Version already packaged. Bump the version; never overwrite a release.'); }
$php = [PHP_BINARY];
if (is_string(php_ini_loaded_file())) { array_push($php, '-c', php_ini_loaded_file()); }
$process = proc_open([...$php, $root . '/tools/run-tests.php'], [0=>STDIN, 1=>STDOUT, 2=>STDERR], $pipes, $root);
if (! is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Research tests failed'); }
if (researchReleaseState(dirname($root), $spec['version'], $allowDirty) !== $source) { throw new RuntimeException('Release source changed during validation'); }
$files = [];
foreach ($spec['include'] as $path) {
    $full = $root . '/' . $path;
    if (is_file($full)) { $files[] = $path; continue; }
    if (! is_dir($full)) { throw new RuntimeException('Missing package input: ' . $path); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root)+1)); }
    }
}
sort($files);
$zip = new ZipArchive();
if ($zip->open($base . '.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Cannot create package'); }
foreach ($files as $file) {
    if (! $zip->addFile($root . '/' . $file, $spec['slug'] . '/' . $file)) { throw new RuntimeException('Cannot package file: ' . $file); }
}
if (! $zip->close()) { throw new RuntimeException('Cannot finish package'); }
$verify = new ZipArchive();
if ($verify->open($base . '.zip') !== true || $verify->numFiles !== count($files)) { throw new RuntimeException('Package validation failed'); }
foreach ($files as $file) {
    if (hash('sha256', $verify->getFromName($spec['slug'] . '/' . $file)) !== hash_file('sha256', $root . '/' . $file)) { throw new RuntimeException('Package content mismatch'); }
}
$verify->close();
$manifest = ['plugin'=>$spec['slug'], 'version'=>$spec['version'], 'requires_platform'=>$spec['requires_platform'], 'requires_addon_api'=>$spec['requires_addon_api'],
    'sha256'=>hash_file('sha256', $base . '.zip'), 'entries'=>count($files), ...$source,
    'tests'=>'syntax, UTF-8 and 16 research test invocations passed', 'built_at_utc'=>gmdate('c')];
file_put_contents($base . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo $base . '.zip' . "\n" . $manifest['sha256'] . "\n";
