<?php
declare(strict_types=1);
$zip = realpath($argv[1] ?? '');
if (!$zip) { throw new RuntimeException('Pass the generated installation ZIP'); }
$temporary_root = dirname(__DIR__, 2) . '/_notes/tmp';
if (!is_dir($temporary_root)) { mkdir($temporary_root, 0777, true); }
$temporary = tempnam($temporary_root, 'ttv');
unlink($temporary);
mkdir($temporary);
try {
    $archive = new ZipArchive();
    if (true !== $archive->open($zip)) { throw new RuntimeException('Cannot open installation ZIP'); }
    foreach (array('whiteboard-render', 'host-whiteboard', 'join', 'realtime-client', 'whiteboard-viewer', 'pdf-pages', 'discovery-client', 'global-classroom', 'assigned-activities') as $asset) {
        $relative = 'resources/js/' . $asset . '.js';
        $packaged = $archive->getFromName('teaching-tools/' . $relative);
        if (false === $packaged || hash('sha256', $packaged) !== hash_file('sha256', dirname(__DIR__) . '/' . $relative)) {
            throw new RuntimeException('Packaged whiteboard asset does not match verified source: ' . $asset);
        }
    }
    foreach (array('src/Rest/Controllers/Realtime_Controller.php', 'src/Rest/Controllers/Whiteboard_Controller.php',
        'src/Rest/Controllers/Classroom_Controller.php', 'src/Rest/Controllers/Sessions_Controller.php',
        'src/Sessions/Tool_Session_Repository.php', 'src/Database/Installer.php', 'src/Addon/Oj_Platform_Registration.php',
        'src/Addon/Whiteboard/Whiteboard_Image_Repository.php', 'src/Addon/Whiteboard/Whiteboard_Host_Page_Handler.php',
        'src/Addon/Public_Join_Page_Handler.php', 'resources/css/whiteboard.css') as $relative) {
        $packaged = $archive->getFromName('teaching-tools/' . $relative);
        if (false === $packaged || hash('sha256', $packaged) !== hash_file('sha256', dirname(__DIR__) . '/' . $relative)) {
            throw new RuntimeException('Packaged role/sharing asset does not match verified source: ' . $relative);
        }
    }
    $archive->extractTo($temporary, array('teaching-tools/teaching-tools.php', 'teaching-tools/realtime-addon.json', 'teaching-tools/common-server/package.json', 'teaching-tools/src/Support/Realtime_Versions.php'));
    $archive->close();
    define('TEACHING_TOOLS_PATH', $temporary . '/teaching-tools/');
    $manifest = json_decode(file_get_contents(TEACHING_TOOLS_PATH . 'realtime-addon.json'), true);
    preg_match('/ \* Version: ([^\r\n]+)/', file_get_contents(TEACHING_TOOLS_PATH . 'teaching-tools.php'), $version);
    define('TEACHING_TOOLS_VERSION', $version[1]);
    require TEACHING_TOOLS_PATH . 'src/Support/Realtime_Versions.php';
    $bundled = TeachingTools\Support\Realtime_Versions::bundled();
    $core = json_decode(file_get_contents(TEACHING_TOOLS_PATH . 'common-server/package.json'), true);
    if ($bundled['module'] !== $manifest['version'] || $bundled['plugin'] !== TEACHING_TOOLS_VERSION || $bundled['core'] !== $core['version']) {
        throw new RuntimeException('Installed ZIP version metadata is unavailable');
    }
    echo 'Installation ZIP versions verified: plugin ' . $bundled['plugin'] . ', module ' . $bundled['module'] . ', core ' . $bundled['core'] . ".\n";
    echo "Packaged whiteboard assets match verified source.\n";
} finally {
    $resolved = realpath($temporary);
    $allowed = realpath($temporary_root) . DIRECTORY_SEPARATOR;
    if (!$resolved || !str_starts_with($resolved, $allowed) || !str_starts_with(basename($resolved), 'ttv')) { throw new RuntimeException('Unsafe temporary cleanup path'); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($resolved);
}
