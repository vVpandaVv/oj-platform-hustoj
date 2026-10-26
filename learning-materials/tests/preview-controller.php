<?php
declare(strict_types=1);

namespace LearningMaterials\Materials {
    // Windows sandbox ACL checks report false even for writable fixtures.
    // Only override the storage permission probe in this isolated test process.
    function is_writable(string $path): bool { return \is_dir($path); }
}

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    final class AddonCapabilityKeyV1 {
        public static function wordpress(string $key): string { return $key; }
    }
}

namespace {
// Exercise the real controller/repository/storage with isolated WordPress stubs.
if (! isset($argv[1])) {
    $cases = array(
        'issue' => array(200, 'issued'),
        'nonce' => array(403, 'error'),
        'logged-out' => array(404, 'error'),
        'group-denied' => array(404, 'error'),
        'http' => array(400, 'error'),
        'missing' => array(404, 'error'),
        'issue-docx' => array(200, 'issued'),
        'issue-xlsx' => array(200, 'issued'),
        'google' => array(200, 'linked'),
        'google-denied' => array(404, 'error'),
        'google-invalid' => array(400, 'error'),
        'save-google' => array(200, 'saved'),
        'file-to-google' => array(200, 'saved'),
        'edit-google' => array(200, 'saved'),
        'google-to-file' => array(200, 'converted'),
        'save-invalid' => array(200, 'error'),
        'save-group-failure' => array(200, 'error'),
        'save-settings' => array(200, 'saved'),
        'save-malformed-group' => array(200, 'error'),
        'save-scalar-group' => array(200, 'error'),
        'file' => array(200, '0123456789'),
        'head' => array(200, ''),
        'range' => array(206, '2345'),
        'suffix' => array(206, '789'),
        'range-invalid' => array(416, ''),
        'tampered' => array(404, '학습자료를 찾을 수 없습니다.'),
        'expired' => array(404, '학습자료를 찾을 수 없습니다.'),
        'replaced' => array(404, '학습자료를 찾을 수 없습니다.'),
        'groups-changed' => array(404, '학습자료를 찾을 수 없습니다.'),
        'deleted' => array(404, '학습자료를 찾을 수 없습니다.'),
        'draft-view' => array(404, '학습자료를 찾을 수 없습니다.'),
        'draft-download' => array(404, '학습자료를 찾을 수 없습니다.'),
        'expired-download' => array(404, '학습자료를 찾을 수 없습니다.'),
        'no-view-capability' => array(404, '학습자료를 찾을 수 없습니다.'),
        'manager-draft-view' => array(200, '0123456789'),
        'draft-signed' => array(404, '학습자료를 찾을 수 없습니다.'),
        'manager-draft-signed' => array(200, '0123456789'),
        'forged-manager-preview' => array(404, '학습자료를 찾을 수 없습니다.'),
        'diagnostics-denied' => array(403, 'error'),
        'diagnostics-nonce' => array(403, 'error'),
        'diagnostics-missing' => array(404, 'error'),
    );
    foreach ($cases as $name => [$expectedStatus, $expectedBody]) {
        $process = proc_open(array(PHP_BINARY, __FILE__, $name), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode($output, true);
        if ($exit !== 0 || $errors !== '' || ! is_array($result)
            || $result['status'] !== $expectedStatus || $result['body'] !== $expectedBody) {
            fwrite(STDERR, "FAIL $name: $output $errors\n");
            exit(1);
        }
    }
    echo count($cases) . " controller checks passed (WordPress stubs; no live Google request).\n";
    exit;
}

$scenario = $argv[1];
define('LEARNING_MATERIALS_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
$fixtureRoot = dirname(__DIR__, 2) . '/_notes/tmp/lmt-preview-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot . '/learning-materials-private', 0750, true);
define('WP_CONTENT_DIR', $fixtureRoot);
file_put_contents($fixtureRoot . '/learning-materials-private/test.pptx', '0123456789');
$status = 200;
ob_start();
register_shutdown_function(static function () use ($fixtureRoot): void {
    $body = ob_get_clean();
    foreach (array('test.pptx', 'index.php', '.htaccess', 'web.config') as $filename) {
        $path = $fixtureRoot . '/learning-materials-private/' . $filename;
        if (is_file($path)) unlink($path);
    }
    rmdir($fixtureRoot . '/learning-materials-private');
    rmdir($fixtureRoot);
    echo json_encode(array('status' => $GLOBALS['status'], 'body' => $body), JSON_UNESCAPED_UNICODE);
});
function nocache_headers(): void {}
function status_header(int $code): void { $GLOBALS['status'] = $code; }
function __(string $text, string $domain): string { return $text; }
function esc_html__(string $text, string $domain): string { return $text; }
function is_user_logged_in(): bool { return ! in_array($GLOBALS['scenario'], array('logged-out', 'file', 'head', 'range', 'suffix', 'range-invalid'), true); }
function get_current_user_id(): int { return 1; }
function current_user_can(string $key): bool {
    if (in_array($GLOBALS['scenario'], array('no-view-capability', 'diagnostics-denied'), true)) return false;
    if (in_array($GLOBALS['scenario'], array('draft-view', 'draft-download', 'expired-download'), true)) return $key === 'learning_materials_view';
    return ! in_array($GLOBALS['scenario'], array('group-denied', 'google-denied'), true);
}
function check_admin_referer(string $action): void {}
function wp_unslash(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return strip_tags($value); }
function sanitize_textarea_field(string $value): string { return strip_tags($value); }
function home_url(string $path): string { return 'https://school.test' . $path; }
function current_time(string $type, bool $gmt): string { return '2026-10-15 10:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
function wp_safe_redirect(string $url): void {
    if (str_contains($url, 'lmt_error=')) {
        if ($GLOBALS['scenario'] === 'save-group-failure' && ($GLOBALS['wpdb']->row['storage_filename'] !== 'test.pptx'
            || ! is_file(WP_CONTENT_DIR . '/learning-materials-private/test.pptx'))) throw new RuntimeException('Failed group update changed original material');
        echo 'error'; return;
    }
    $row = $GLOBALS['wpdb']->row;
    if ($GLOBALS['scenario'] === 'save-settings' && ($row['category'] !== '정보' || $row['is_pinned'] !== 1
        || json_decode($row['tags_json'], true) !== array('AI', '윤리') || $row['publication_status'] !== 'published'
        || $row['publish_from'] !== '2026-10-16 12:00:00')) throw new RuntimeException('Publication metadata was not persisted');
    if (($row['source_url'] ?? '') !== 'https://docs.google.com/document/d/shared_123/edit' || $row['storage_filename'] !== '') {
        throw new RuntimeException('Link was not persisted correctly');
    }
    $oldExists = is_file(WP_CONTENT_DIR . '/learning-materials-private/test.pptx');
    if ($GLOBALS['scenario'] === 'file-to-google' && $oldExists) throw new RuntimeException('Old upload was not removed');
    if ($GLOBALS['scenario'] === 'edit-google' && ! $oldExists) throw new RuntimeException('Link edit touched file storage');
    echo 'saved';
}
function check_ajax_referer(string $action, string $name, bool $die): bool { return ! in_array($GLOBALS['scenario'], array('nonce', 'diagnostics-nonce'), true); }
function wp_salt(string $scheme): string { return 'controller-test-key'; }
function admin_url(string $path): string { return ($GLOBALS['scenario'] === 'http' ? 'http' : 'https') . '://school.test/wp-admin/' . $path; }
function add_query_arg(array|string $args, string $url, ?string $base = null): string {
    return $base === null ? $url . '?' . http_build_query($args) : $base . '?' . http_build_query(array($args => $url));
}
function wp_parse_url(string $url, int $component): mixed { return parse_url($url, $component); }
function wp_send_json_error(array $data, int $code): void { status_header($code); echo 'error'; }
function wp_send_json_success(array $data): void {
    if (str_starts_with($GLOBALS['scenario'], 'google')) {
        if ($data['viewer_url'] !== 'https://docs.google.com/document/d/shared_123/preview' || isset($data['expires_at'])) {
            throw new RuntimeException('Incorrect linked preview response');
        }
        echo 'linked';
        return;
    }
    parse_str((string) parse_url($data['viewer_url'], PHP_URL_QUERY), $viewer);
    if (($viewer['embedded'] ?? '') !== 'true') {
        throw new RuntimeException('Google requires embedded=true to permit iframe display');
    }
    parse_str((string) parse_url($viewer['url'], PHP_URL_QUERY), $file);
    $material = LearningMaterials\Materials\Material::from_row($GLOBALS['wpdb']->row);
    if (LearningMaterials\Materials\Preview_Link::valid($material, array(), (int) $file['expires'], $file['signature'], wp_salt('auth'), time(), ($file['manager_preview'] ?? '') === '1')) {
        echo 'issued';
    } else {
        throw new RuntimeException('Invalid issued signature');
    }
}
$wpdb = new class {
    public string $prefix = 'wp_';
    public int $insert_id = 12;
    public array $groups = array();
    private ?array $snapshot = null;
    public ?array $row = array('id' => 7, 'storage_filename' => 'test.pptx', 'original_filename' => '자료.pptx',
        'extension' => 'pptx', 'mime_type' => 'application/zip', 'file_size' => 10, 'updated_at' => '2026-10-15');
    public function prepare(string $sql, int $id): string { return $sql; }
    public function get_row(string $sql, string $format): ?array { return $this->row; }
    public function get_col(string $sql): array { return $this->groups; }
    public function insert(string $table, array $data, array $format): int {
        if ($table === $this->prefix . 'learning_materials') $this->row = $data + array('id' => $this->insert_id);
        return 1;
    }
    public function update(string $table, array $data, array $where, array $format, array $whereFormat): int {
        $this->row = array_merge($this->row, $data);
        return 1;
    }
    public function delete(string $table, array $where, array $format): int|false { return $GLOBALS['scenario'] === 'save-group-failure' ? false : 1; }
    public function query(string $sql): int {
        if ($sql === 'START TRANSACTION') $this->snapshot = $this->row;
        if ($sql === 'ROLLBACK') $this->row = $this->snapshot;
        return 1;
    }
};
// Load explicitly: Windows PHP is_readable() can reject the workspace ACL.
foreach (array('Database/Installer', 'Materials/Material', 'Materials/Material_Settings', 'Materials/Preview_Link', 'Materials/Google_Link',
    'Addon/Runtime_Bridge', 'Support/Capabilities', 'Materials/Material_Access',
    'Materials/Material_Repository', 'Materials/Material_Storage', 'Http/Materials_Action_Controller', 'Http/Preview_Diagnostics_Controller') as $class) {
    require_once LEARNING_MATERIALS_PATH . 'src/' . $class . '.php';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('material_id' => 7);
if (str_starts_with($scenario, 'diagnostics-')) {
    if ($scenario === 'diagnostics-missing') $wpdb->row = null;
    LearningMaterials\Http\Preview_Diagnostics_Controller::run();
    exit;
}
if ($scenario === 'google-to-file') {
    $wpdb->row['source_url'] = 'https://docs.google.com/document/d/shared_123/edit';
    (new LearningMaterials\Materials\Material_Repository())->update(7, '파일 자료', '', array(
        'storage_filename' => 'test.pptx', 'original_filename' => '자료.pptx', 'extension' => 'pptx',
        'mime_type' => 'application/zip', 'file_size' => 10,
    ), array());
    if (LearningMaterials\Materials\Material::from_row($wpdb->row)->is_google_link()) throw new RuntimeException('Old link was not cleared');
    echo 'converted';
    exit;
}
if (in_array($scenario, array('save-google', 'file-to-google', 'edit-google', 'save-invalid', 'save-group-failure', 'save-settings', 'save-malformed-group', 'save-scalar-group'), true)) {
    $_POST = array('material_id' => $scenario === 'save-google' ? 0 : 7,
        'title' => '링크 자료', 'source_url' => $scenario === 'save-invalid' ? 'https://evil.test/' : 'https://docs.google.com/document/d/shared_123/edit?usp=sharing');
    if ($scenario === 'edit-google') { $wpdb->row['source_url'] = $_POST['source_url']; $wpdb->row['storage_filename'] = ''; }
    if ($scenario === 'save-settings') $_POST = array_merge($_POST, array('category' => '정보', 'tags' => 'AI, 윤리', 'is_pinned' => '1',
        'publication_status' => 'published', 'publish_from' => '2026-10-16T12:00', 'publish_until' => '2026-10-17T12:00'));
    if ($scenario === 'save-malformed-group') $_POST['group_ids'] = array(array('nested'));
    if ($scenario === 'save-scalar-group') $_POST['group_ids'] = 'own';
    LearningMaterials\Http\Materials_Action_Controller::save();
    exit;
}
if (in_array($scenario, array('issue', 'nonce', 'logged-out', 'group-denied', 'http', 'missing', 'issue-docx', 'issue-xlsx', 'google', 'google-invalid', 'google-denied'), true)) {
    if (in_array($scenario, array('group-denied', 'google-denied'), true)) $wpdb->groups = array('restricted');
    if ($scenario === 'missing') $wpdb->row = null;
    if ($scenario === 'issue-docx') $wpdb->row['extension'] = 'docx';
    if ($scenario === 'issue-xlsx') $wpdb->row['extension'] = 'xlsx';
    if (str_starts_with($scenario, 'google')) $wpdb->row['source_url'] = $scenario === 'google-invalid'
        ? 'https://evil.test/' : 'https://docs.google.com/document/d/shared_123/edit';
    LearningMaterials\Http\Materials_Action_Controller::preview_link();
    exit;
}
$_SERVER['REQUEST_METHOD'] = $scenario === 'head' ? 'HEAD' : 'GET';
if (in_array($scenario, array('draft-view', 'draft-download', 'manager-draft-view', 'expired-download', 'no-view-capability'), true)) {
    $_GET = array('material_id' => 7);
    $wpdb->row['extension'] = 'txt';
    if ($scenario === 'expired-download') $wpdb->row['publish_until'] = gmdate('Y-m-d H:i:s', time() - 1);
    elseif ($scenario !== 'no-view-capability') $wpdb->row['publication_status'] = 'draft';
    if (str_contains($scenario, 'download')) LearningMaterials\Http\Materials_Action_Controller::download();
    else LearningMaterials\Http\Materials_Action_Controller::view();
    exit;
}
$expires = time() + 600;
if ($scenario === 'expired') $expires = time() - 1;
if (in_array($scenario, array('draft-signed', 'manager-draft-signed'), true)) $wpdb->row['publication_status'] = 'draft';
$material = LearningMaterials\Materials\Material::from_row($wpdb->row);
$_GET = array('material_id' => 7, 'expires' => $expires,
    'signature' => LearningMaterials\Materials\Preview_Link::signature($material, array(), $expires, wp_salt('auth'), $scenario === 'manager-draft-signed'));
if (in_array($scenario, array('manager-draft-signed', 'forged-manager-preview'), true)) $_GET['manager_preview'] = '1';
if ($scenario === 'tampered') $_GET['signature'] = str_repeat('0', 64);
if ($scenario === 'replaced') $wpdb->row['storage_filename'] = 'replacement.pptx';
if ($scenario === 'groups-changed') $wpdb->groups = array('restricted');
if ($scenario === 'deleted') $wpdb->row = null;
if ($scenario === 'range') $_SERVER['HTTP_RANGE'] = 'bytes=2-5';
if ($scenario === 'suffix') $_SERVER['HTTP_RANGE'] = 'bytes=-3';
if ($scenario === 'range-invalid') $_SERVER['HTTP_RANGE'] = 'bytes=20-30';
LearningMaterials\Http\Materials_Action_Controller::preview_file();
}
