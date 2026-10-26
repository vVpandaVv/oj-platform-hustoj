<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1 {
    interface AddonPageHandlerV1 { public function handle(AddonPageRequestV1 $request): AddonPageResponseV1; }
    final class AddonCapabilityKeyV1 { public static function wordpress(string $key): string { return $key; } }
    final class AddonPageRequestV1 {
        public function __construct(private int $id) {}
        public function parameters(): array { return array('material_id' => $this->id); }
        public function query(): array { return $_GET; }
        public function actorUserId(): int { return 1; }
    }
    final class AddonPageResponseV1 {
        public function __construct(public string $title, public string $body, public int $status) {}
        public static function page(string $title, string $body, string $layout, bool $theme, int $status): self { return new self($title, $body, $status); }
    }
}
namespace LearningMaterials\Materials { function is_writable(string $path): bool { return \is_dir($path); } }
namespace {
// Local-only visual/HTTP fixture; not included in release packages.
if (PHP_SAPI !== 'cli-server') { echo "Run with PHP -S 127.0.0.1:8898\n"; exit; }
define('ARRAY_A', 'ARRAY_A');
define('LEARNING_MATERIALS_PATH', dirname(__DIR__) . '/');
define('WP_CONTENT_DIR', dirname(__DIR__, 2) . '/_notes/tmp/lmt-ui-site');
if (! is_dir(WP_CONTENT_DIR . '/learning-materials-private')) mkdir(WP_CONTENT_DIR . '/learning-materials-private', 0750, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT1kAAAAASUVORK5CYII=');
file_put_contents(WP_CONTENT_DIR . '/learning-materials-private/test.png', $png);
spl_autoload_register(static function (string $class): void {
    $prefix = 'LearningMaterials\\';
    if (str_starts_with($class, $prefix)) {
        $path = LEARNING_MATERIALS_PATH . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($path)) require_once $path;
    }
});
$role = $_GET['test_role'] ?? $_COOKIE['lmt_test_role'] ?? 'learner';
if (isset($_GET['test_role'])) setcookie('lmt_test_role', $role, array('path' => '/', 'httponly' => true, 'samesite' => 'Lax'));
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string { return esc_html($text); }
function esc_url(string $text): string { return esc_html($text); }
function esc_textarea(string $text): string { return esc_html($text); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function esc_html_e(string $text, string $domain = ''): void { echo esc_html($text); }
function esc_attr_e(string $text, string $domain = ''): void { echo esc_attr($text); }
function selected(mixed $value, mixed $expected): void { if ($value === $expected) echo 'selected'; }
function checked(mixed $value): void { if ($value) echo 'checked'; }
function wp_create_nonce(string $action): string { return 'fixture-nonce'; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Seoul'); }
function nocache_headers(): void { header('Cache-Control: private, no-store'); }
function status_header(int $code): void { http_response_code($code); }
function is_user_logged_in(): bool { return true; }
function current_user_can(string $key): bool { return $GLOBALS['role'] === 'admin' || $key === 'learning_materials_view'; }
function get_current_user_id(): int { return 1; }
function wp_salt(string $scheme): string { return 'fixture-test-salt'; }
function check_ajax_referer(string $action, string $name, bool $die): bool { return ($_POST[$name] ?? '') === 'fixture-nonce'; }
function wp_send_json_success(array $data): never { header('Content-Type: application/json'); echo json_encode(array('success' => true, 'data' => $data)); exit; }
function wp_send_json_error(array $data, int $status): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode(array('success' => false, 'data' => $data)); exit; }
function home_url(string $path): string { return 'http://' . $_SERVER['HTTP_HOST'] . $path; }
function admin_url(string $path): string { return home_url('/wp-admin/' . $path); }
function add_query_arg(array $args, string $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function wp_parse_url(string $url, int $component): mixed { return parse_url($url, $component); }
$wpdb = new class {
    public string $prefix = 'wp_';
    public array $rows = array();
    public function prepare(string $sql, int $id): string { return str_replace('%d', (string) $id, $sql); }
    public function get_row(string $sql, string $format): ?array { preg_match('/id = (\d+)/', $sql, $match); return $this->rows[(int) ($match[1] ?? 0)] ?? null; }
    public function get_results(string $sql, string $format): array {
        if (str_contains($sql, 'learning_material_groups')) return array(array('material_id' => 2, 'group_id' => 'secret'));
        return array_values($this->rows);
    }
    public function get_col(string $sql): array { return str_contains($sql, 'material_id = 2 ') ? array('secret') : array(); }
};
for ($id = 1; $id <= 24; $id++) {
    $wpdb->rows[$id] = array('id' => $id, 'title' => '학습자료 ' . $id, 'description' => '수업에서 사용할 학습자료입니다.',
        'storage_filename' => 'test.png', 'original_filename' => '자료.png', 'extension' => 'png', 'mime_type' => 'image/png',
        'file_size' => strlen($png), 'created_at' => '2026-10-15 00:00:00', 'updated_at' => '2026-10-15 00:00:00',
        'category' => $id % 2 ? '인공지능' : '프로그래밍', 'tags_json' => '["수업","연습"]', 'is_pinned' => $id === 21 ? 1 : 0,
        'publication_status' => $id === 3 ? 'draft' : ($id === 4 ? 'archived' : 'published'),
        'publish_from' => $id === 5 ? '2099-01-01 00:00:00' : null, 'publish_until' => $id === 6 ? '2000-01-01 00:00:00' : null);
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/(public.css|console.css|catalog.css|preview.js)$~', $path, $match)) {
    $folder = str_ends_with($match[1], '.js') ? 'js' : 'css';
    header('Content-Type: ' . ($folder === 'js' ? 'application/javascript' : 'text/css'));
    readfile(LEARNING_MATERIALS_PATH . 'resources/' . $folder . '/' . $match[1]); exit;
}
if ($path === '/wp-admin/admin-post.php') {
    $action = $_REQUEST['action'] ?? '';
    match ($action) {
        'learning_materials_view' => LearningMaterials\Http\Materials_Action_Controller::view(),
        'learning_materials_download' => LearningMaterials\Http\Materials_Action_Controller::download(),
        'learning_materials_preview_link' => LearningMaterials\Http\Materials_Action_Controller::preview_link(),
        'learning_materials_diagnose' => LearningMaterials\Http\Preview_Diagnostics_Controller::run(),
        default => http_response_code(404),
    }; exit;
}
$id = preg_match('~/([0-9]+)(?:/edit)?$~', $path, $match) ? (int) $match[1] : 0;
$request = new CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1($id);
$console = str_starts_with($path, '/oj-console/');
if ($console && $role !== 'admin') { http_response_code(403); echo 'Forbidden'; exit; }
if ($console && (str_ends_with($path, '/new') || str_ends_with($path, '/edit'))) $handler = new LearningMaterials\Addon\Console_Form_Page_Handler();
elseif ($console) $handler = new LearningMaterials\Addon\Console_List_Page_Handler();
elseif ($id) $handler = new LearningMaterials\Addon\Public_Detail_Page_Handler();
else $handler = new LearningMaterials\Addon\Public_List_Page_Handler();
$response = $handler->handle($request);
http_response_code($response->status);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($response->title) . '</title>';
echo '<style>body{font-family:system-ui,sans-serif;margin:24px;background:#f7f8fa;color:#222}label{display:block}input,select,textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #bbb;border-radius:8px}input[type=checkbox]{width:auto}button,a.ojp-button{padding:9px 14px;border:1px solid #aaa;border-radius:8px;background:white;color:inherit;text-decoration:none;display:inline-block;cursor:pointer}.ojp-field{margin-bottom:12px}.ojp-field-help{font-size:13px;color:#555}.ojp-heading-actions,.ojp-page-header{display:flex;gap:12px;align-items:center;justify-content:space-between}.ojp-status-badge{font-size:12px;padding:3px 7px;border-radius:6px;background:#e8eef0}.ojp-table-wrap{overflow:auto}.ojp-table{width:100%;border-collapse:collapse}.ojp-table td,.ojp-table th{padding:12px;border-bottom:1px solid #ddd;text-align:left}.ojp-visually-hidden{position:absolute;clip:rect(0,0,0,0)}fieldset{border:1px solid #ccc;border-radius:8px;margin-bottom:16px}.ojp-empty-state{padding:24px}</style>';
echo '<link rel="stylesheet" href="/assets/catalog.css"><link rel="stylesheet" href="/assets/' . ($console ? 'console.css' : 'public.css') . '">';
if (($_GET['test_width'] ?? '') === '342') echo '<style>body{width:342px}</style>';
echo $response->body . '<script src="/assets/preview.js"></script></html>';
}
