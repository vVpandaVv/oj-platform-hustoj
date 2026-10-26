<?php
declare(strict_types=1);

// Read-only package verification; ZIP creation belongs to release.cmd only.
if (PHP_SAPI !== 'cli' || ! class_exists(ZipArchive::class) || empty($argv[1])) {
    fwrite(STDERR, "Run: php -d extension=zip focus-tracker/tests/package-smoke.php <release-kit ZIP>\n");
    exit(1);
}
$archive = new ZipArchive();
if ($archive->open($argv[1]) !== true) { throw new RuntimeException('Cannot open release ZIP.'); }
$root = dirname(__DIR__);
$checks = 0;
function package_check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
}
$seen = array();
for ($index = 0; $index < $archive->numFiles; ++$index) {
    $name = $archive->getNameIndex($index);
    package_check(is_string($name) && str_starts_with($name, 'focus-tracker/') && ! str_contains($name, '..') && ! str_contains($name, '\\'), 'Every archive path is confined to the install folder.');
    if (str_ends_with($name, '/')) { continue; }
    $relative = substr($name, strlen('focus-tracker/'));
    package_check(! preg_match('#(^|/)(tests?|_notes|\.git|node_modules|tmp|\.env)(/|$)#', $relative), 'No test server, local data, repository metadata or secrets are distributed.');
    package_check(! isset($seen[$relative]), 'ZIP has no duplicate file names.');
    $seen[$relative] = true;
    $source = $root . '/' . $relative;
    package_check(is_file($source) && hash('sha256', (string) $archive->getFromIndex($index)) === hash_file('sha256', $source), 'Packaged bytes match the tested source: ' . $relative);
}
foreach (array('focus-tracker.php', 'plugin-package.php', 'uninstall.php', 'README.md', 'docs/RC-ACCEPTANCE.md', 'docs/VERIFICATION.md', 'docs/REAL-WORDPRESS.md', 'src/Privacy/Privacy_Guard.php', 'src/Privacy/Personal_Data.php', 'src/Support/Release_Readiness.php', 'resources/css/activity-status.css') as $required) {
    package_check(isset($seen[$required]), 'Required runtime and release documentation are included: ' . $required);
}
foreach (array('src', 'resources', 'docs') as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            package_check(isset($seen[$relative]), 'The complete runtime dependency is packaged: ' . $relative);
        }
    }
}
$main = (string) $archive->getFromName('focus-tracker/focus-tracker.php');
$source_main = (string) file_get_contents($root . '/focus-tracker.php');
preg_match('/^ \* Version:\s*([^\r\n]+)\s*$/m', $source_main, $source_version);
package_check(isset($source_version[1]) && preg_match('/^ \* Version:\s*' . preg_quote(trim($source_version[1]), '/') . '\s*$/m', $main) === 1, 'The final release version is present in the WordPress header.');
package_check(str_contains($main, 'Requires PHP: 8.3') && str_contains($main, 'Requires Plugins: oj-platform'), 'The installer advertises the required runtime.');
package_check(str_contains((string) $archive->getFromName('focus-tracker/plugin-package.php'), 'oj-platform:^2.3.22'), 'The package requires the full platform feature contract.');
$checksum = $argv[1] . '.sha256';
if (is_file($checksum)) {
    package_check(str_contains(strtolower(file_get_contents($checksum)), hash_file('sha256', $argv[1])), 'The release-kit checksum matches the ZIP.');
}
$temporary_parent = realpath(dirname(__DIR__, 2) . '/_notes/tmp');
if ($temporary_parent === false) { throw new RuntimeException('Workspace temporary parent is missing.'); }
$temporary = $temporary_parent . '/focus-tracker-package-' . bin2hex(random_bytes(8));
mkdir($temporary);
try {
    package_check($archive->extractTo($temporary), 'The official archive extracts successfully.');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bootstrap-smoke.php') . ' ' . escapeshellarg($temporary . '/focus-tracker/focus-tracker.php');
    passthru($command, $status);
    package_check($status === 0, 'The extracted plugin boots with its own autoloader and registers the required hooks.');
} finally {
    $resolved = realpath($temporary);
    if ($resolved === false || ! str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporary_parent) . '/focus-tracker-package-')) { throw new RuntimeException('Unsafe temporary cleanup path.'); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($resolved);
    $archive->close();
}
echo 'Package smoke: ' . $checks . ' checks passed (' . count($seen) . " tested-source files, no fixtures).\n";
