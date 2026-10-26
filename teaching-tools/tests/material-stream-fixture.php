<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    final class AddonCapabilityKeyV1 {
        public static function wordpress(string $key): string { return $key; }
    }
}
namespace LearningMaterials\Materials {
    function is_writable(string $path): bool { return \is_dir($path); }
}
namespace {
    // Run the real protected stream, not an unguarded static PDF response.
    define('ARRAY_A', 'ARRAY_A');
    define('WP_CONTENT_DIR', dirname(__DIR__, 2) . '/_notes/tmp/htt-stream-' . bin2hex(random_bytes(8)));
    $folder = WP_CONTENT_DIR . '/learning-materials-private';
    mkdir($folder, 0750, true);
    $pdf = base64_decode($argv[1]);
    file_put_contents($folder . '/fixture.pdf', $pdf);
    $role = $argv[2] ?? 'denied';
    $_GET['material_id'] = (int) ($argv[3] ?? 1);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if (!empty($argv[4])) { $_SERVER['HTTP_RANGE'] = $argv[4]; }
    $status = 200;
    ob_start();
    register_shutdown_function(static function () use ($folder): void {
        $body = ob_get_clean();
        foreach (array('fixture.pdf', 'index.php', '.htaccess', 'web.config') as $name) {
            if (is_file($folder . '/' . $name)) { unlink($folder . '/' . $name); }
        }
        rmdir($folder);
        rmdir(WP_CONTENT_DIR);
        echo json_encode(array('status' => $GLOBALS['status'], 'body' => base64_encode($body)));
    });
    function __(string $text, string $domain = ''): string { return $text; }
    function status_header(int $status): void { $GLOBALS['status'] = $status; }
    function nocache_headers(): void {}
    function is_user_logged_in(): bool { return $GLOBALS['role'] !== 'denied'; }
    function get_current_user_id(): int { return 2; }
    function current_user_can(string $key): bool { return $GLOBALS['role'] === 'allowed' && $key === 'learning_materials_view'; }
    function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    $wpdb = new class {
        public string $prefix = 'wp_';
        public function prepare(string $sql, int $id): string { return str_replace('%d', (string) $id, $sql); }
        public function get_row(string $sql, string $format): ?array {
            preg_match('/id = (\d+)/', $sql, $match);
            return array('id' => (int) $match[1], 'title' => 'PDF 자료', 'storage_filename' => 'fixture.pdf',
                'original_filename' => '수업.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf',
                'file_size' => strlen($GLOBALS['pdf']), 'publication_status' => 'published');
        }
        public function get_col(string $sql): array { return str_contains($sql, 'material_id = 3 ') ? array('restricted') : array(); }
    };
    foreach (array('Database/Installer', 'Materials/Material', 'Materials/Material_Settings', 'Materials/Google_Link',
        'Materials/Preview_Link', 'Addon/Runtime_Bridge', 'Support/Capabilities', 'Materials/Material_Access',
        'Materials/Material_Repository', 'Materials/Material_Storage', 'Http/Materials_Action_Controller') as $class) {
        require dirname(__DIR__, 2) . '/learning-materials/src/' . $class . '.php';
    }
    LearningMaterials\Http\Materials_Action_Controller::view();
}
