<?php
declare(strict_types=1);
$root = dirname(__DIR__);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') continue;
    $text = file_get_contents($file->getPathname());
    if (preg_match('/\$wpdb|\b(?:WP_Query|get_posts|get_users|update_option|add_option|update_user_meta|wp_remote_\w+)\s*\(/', $text)) throw new RuntimeException('Unexpected addon DB/network work: ' . $file->getFilename());
}
foreach (glob($root . '/resources/js/*.js') as $file) { $text = file_get_contents($file); if (preg_match('/\b(?:fetch|XMLHttpRequest|WebSocket|EventSource|sendBeacon)\s*\(/', $text)) throw new RuntimeException('Unexpected browser network request: ' . basename($file)); }
echo "DB budget checks passed: addon registers no DB writes/queries or polling; simulation, editing and recovery issue no application network requests.\n";
