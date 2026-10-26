<?php
declare(strict_types=1);

namespace TeachingTools\Sessions {
    final class Session_Code_Guard {
        public static function check(int $id, string $code): bool { return true; }
        public static function record_failed_attempt(int $id, string $code): void {}
    }
    final class Tool_Session_Repository {
        public static function find_active_by_code(string $code): ?array {
            if ($GLOBALS['case'] === 'ended') { return null; }
            return array('id' => $code === 'OTHER1' ? 43 : 42, 'tool_type' => 'whiteboard', 'mode' => 'live',
                'state' => json_encode(array('materialSharing' => in_array($GLOBALS['case'], array('permissions', 'legacy-image', 'upload-blocked'), true) ? 'permissions' : 'session')));
        }
        public static function host_role(int $session, int $id): ?string { return $id === 1 ? 'owner' : null; }
        public static function is_participant(int $session, int $id): bool { return $id === 2; }
    }
}
namespace {
    class WP_Error { public function __construct(public string $code, public string $message, public array $data = array()) {} }
    class WP_REST_Request extends ArrayObject {
        public function get_file_params(): array { return array('image' => $GLOBALS['file']); }
        public function get_param(string $key): mixed { return $this[$key] ?? null; }
    }
    function __(string $text, string $domain): string { return $text; }
    function get_current_user_id(): int { return $GLOBALS['user']; }
    function sanitize_text_field(string $text): string { return $text; }
    function sanitize_file_name(string $text): string { return basename($text); }
    function wp_upload_dir(): array { return array('basedir' => $GLOBALS['root'] . '/uploads'); }
    function trailingslashit(string $path): string { return rtrim($path, '/\\') . '/'; }
    function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0750, true); }
    function wp_generate_password(int $length, bool $special, bool $extra): string { return substr(bin2hex(random_bytes($length)), 0, $length); }
    function get_temp_dir(): string { return $GLOBALS['root']; }
    function wp_salt(string $scheme): string { return 'fake-fixture-auth-key-not-for-production'; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function wp_check_filetype(string $file, array $types): array { return array('type' => 'image/png'); }
    function wp_get_image_editor(string $source): object {
        return new class($source) {
            public function __construct(private string $source) {}
            public function set_quality(int $quality): void {}
            public function save(string $path, string $type): array {
                copy($this->source, $path);
                return array('path' => $path, 'mime-type' => $type, 'width' => 1, 'height' => 1);
            }
        };
    }
    function rest_ensure_response(mixed $value): mixed { return $value; }
    function admin_url(string $path): string { return '/' . $path; }
    function add_query_arg(array $params, string $url): string { return $url . '?' . http_build_query($params); }
    function esc_url_raw(string $url): string { return $url; }
    function status_header(int $status): void { $GLOBALS['status'] = $status; }
    function nocache_headers(): void {}
    if (!isset($argv[1])) {
        $cases = array('shared', 'permissions', 'ended', 'outsider', 'anonymous', 'cross-session', 'tampered', 'legacy-image', 'upload-blocked', 'upload-student', 'upload-no-openssl', 'quotas');
        foreach ($cases as $case) {
            $pipes = array();
            $command = array(PHP_BINARY);
            if ($case === 'upload-no-openssl') { $command[] = '-n'; }
            elseif (!function_exists('openssl_encrypt')) { array_push($command, '-d', 'extension=openssl'); }
            array_push($command, __FILE__, $case);
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { throw new RuntimeException($case . ': ' . $err); }
            $result = json_decode($out, true);
            $expected = match ($case) { 'shared', 'legacy-image', 'quotas' => 200, 'upload-blocked' => 403, 'upload-no-openssl' => 503, default => 404 };
            if (($result['status'] ?? 0) !== $expected) { throw new RuntimeException($case . ': ' . $out . $err); }
            if (in_array($case, array('shared', 'legacy-image'), true) && base64_decode($result['body']) !== base64_decode($result['expected'])) { throw new RuntimeException('Stream changed image bytes'); }
        }
        echo "Protected background checks passed: opt-in, upload roles, active participants, revocation/end, encrypted static storage, tamper/session binding, independent quotas and legacy images.\n";
        exit;
    }
    $case = $argv[1];
    $user = match ($case) { 'outsider' => 3, 'anonymous' => 0, 'upload-blocked', 'upload-no-openssl' => 1, default => 2 };
    $status = 200;
    $root = dirname(__DIR__, 2) . '/_notes/tmp/tt-sharing-' . bin2hex(random_bytes(8));
    mkdir($root, 0750, true);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jK1cAAAAASUVORK5CYII=');
    file_put_contents($root . '/input.png', $png);
    $file = array('tmp_name' => $root . '/input.png', 'size' => strlen($png), 'error' => UPLOAD_ERR_OK);
    foreach (array('Addon/Whiteboard/Whiteboard_Image_Repository', 'Rest/Controllers/Whiteboard_Controller') as $class) { require dirname(__DIR__) . '/src/' . $class . '.php'; }
    ob_start();
    register_shutdown_function(static function () use ($root, $png): void {
        $body = ob_get_clean();
        $resolved = realpath($root);
        $allowed = realpath(dirname(__DIR__, 2) . '/_notes/tmp') . DIRECTORY_SEPARATOR;
        if (!$resolved || !str_starts_with($resolved, $allowed) || !str_starts_with(basename($resolved), 'tt-sharing-')) { throw new RuntimeException('Unsafe fixture cleanup'); }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
        rmdir($resolved);
        echo json_encode(array('status' => $GLOBALS['status'], 'body' => base64_encode($body), 'expected' => base64_encode($png)));
    });
    if (str_starts_with($case, 'upload-')) {
        $result = TeachingTools\Rest\Controllers\Whiteboard_Controller::upload_image(new WP_REST_Request(array('code' => 'INK123', 'purpose' => 'background')));
        $status = $result instanceof WP_Error ? $result->data['status'] : 200;
        exit;
    }
    $repo = TeachingTools\Addon\Whiteboard\Whiteboard_Image_Repository::class;
    $saved = $repo::save_upload(42, $file, $case !== 'legacy-image');
    if (!$saved) { throw new RuntimeException('Cannot save fixture background'); }
    if ($case !== 'legacy-image' && substr((string) file_get_contents($saved['path']), 0, 5) !== 'TTBG1') { throw new RuntimeException('Background must be encrypted'); }
    if ($case === 'cross-session') {
        $other = dirname($saved['path'], 2) . '/43'; mkdir($other);
        copy($saved['path'], $other . '/' . basename($saved['path']));
    }
    if ($case === 'tampered') { $raw = file_get_contents($saved['path']); $raw[34] = chr(ord($raw[34]) ^ 1); file_put_contents($saved['path'], $raw); }
    if ($case === 'quotas') {
        for ($i = 0; $i < 30; $i++) { if (!$repo::save_upload(42, $file, true) || !$repo::save_upload(42, $file)) { throw new RuntimeException('Backgrounds consumed ordinary image quota'); } }
        if ($repo::save_upload(42, $file) !== null) { throw new RuntimeException('Normal image quota not enforced'); }
        for ($i = 31; $i < 500; $i++) { if (!$repo::save_upload(42, $file, true)) { throw new RuntimeException('Cannot store the supported 500 backgrounds'); } }
        if ($repo::save_upload(42, $file, true) !== null) { throw new RuntimeException('Background image quota not enforced'); }
        $repo::delete_for_session(42);
        if (is_dir(dirname($saved['path']))) { throw new RuntimeException('Session images were not removed'); }
        exit;
    }
    $_GET = array('code' => $case === 'cross-session' ? 'OTHER1' : 'INK123', 'file' => $saved['filename']);
    TeachingTools\Rest\Controllers\Whiteboard_Controller::serve_image();
}
