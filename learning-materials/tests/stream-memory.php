<?php
declare(strict_types=1);
namespace LearningMaterials\Materials { function is_writable(string $path): bool { return \is_dir($path); } }
namespace CodingPanda\OjPlatform\AddonApi\V1 {
    final class AddonCapabilityKeyV1 { public static function wordpress(string $key): string { return $key; } }
}
namespace {
if (! isset($argv[1])) {
    $process = proc_open(array(PHP_BINARY, '-d', 'memory_limit=12M', '-d', 'output_buffering=0', __FILE__, 'worker'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $length = 0;
    $hash = hash_init('sha256');
    while (! feof($pipes[1])) {
        $data = fread($pipes[1], 65536);
        $length += strlen($data);
        hash_update($hash, $data);
    }
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $expected = hash_init('sha256');
    for ($i = 0; $i < 256; $i++) hash_update($expected, str_repeat('A', 65536));
    if ($exit !== 0 || $errors !== '' || $length !== 16 * 1024 * 1024 || hash_final($hash) !== hash_final($expected)) {
        throw new RuntimeException('Streaming under memory limit failed: ' . $length . ' bytes; ' . $errors);
    }
    echo "Streaming: 16 MiB file delivered intact under a 12 MiB PHP memory limit.\n";
    exit;
}
$root = dirname(__DIR__, 2) . '/_notes/tmp/lmt-stream-' . bin2hex(random_bytes(8));
mkdir($root . '/learning-materials-private', 0750, true);
define('WP_CONTENT_DIR', $root);
define('ARRAY_A', 'ARRAY_A');
register_shutdown_function(static function () use ($root): void {
    foreach (array('large.txt', 'index.php', '.htaccess', 'web.config') as $name) {
        $path = $root . '/learning-materials-private/' . $name;
        if (is_file($path)) unlink($path);
    }
    rmdir($root . '/learning-materials-private'); rmdir($root);
});
$handle = fopen($root . '/learning-materials-private/large.txt', 'wb');
$chunk = str_repeat('A', 65536);
for ($i = 0; $i < 256; $i++) fwrite($handle, $chunk);
fclose($handle); unset($chunk);
function nocache_headers(): void {}
function status_header(int $status): void {}
function is_user_logged_in(): bool { return true; }
function current_user_can(string $key): bool { return true; }
function get_current_user_id(): int { return 1; }
function esc_html__(string $text, string $domain): string { return $text; }
$wpdb = new class {
    public string $prefix = 'wp_';
    public function prepare(string $sql, int $id): string { return $sql; }
    public function get_col(string $sql): array { return array(); }
    public function get_row(string $sql, string $format): array {
        return array('id' => 1, 'storage_filename' => 'large.txt', 'original_filename' => 'large.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_size' => 16 * 1024 * 1024);
    }
};
foreach (array('Database/Installer', 'Materials/Material', 'Materials/Material_Access', 'Materials/Material_Repository', 'Materials/Material_Storage', 'Support/Capabilities', 'Http/Materials_Action_Controller') as $class) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}
$_GET = array('material_id' => 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
LearningMaterials\Http\Materials_Action_Controller::view();
}
