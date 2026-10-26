<?php
declare(strict_types=1);

namespace TeachingTools\Sessions {
    final class Session_Code_Guard {
        public static function check(int $id, string $code): bool { return true; }
        public static function record_failed_attempt(int $id, string $code): void {}
    }
    final class Tool_Session_Repository {
        public static function find_active_by_code(string $code): array { return array('id' => 42, 'tool_type' => 'whiteboard', 'mode' => 'live'); }
        public static function host_role(int $session, int $user): ?string { return match ($user) { 1 => 'owner', 3 => 'cohost', 4 => 'observer', default => null }; }
        public static function is_participant(int $session, int $user): bool { return $user === 2; }
    }
}
namespace {
    define('MINUTE_IN_SECONDS', 60);
    class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
    }
    class WP_REST_Request extends ArrayObject {
        public array $json = array();
        public function get_json_params(): array { return $this->json; }
    }
    function __(string $text, string $domain): string { return $text; }
    function get_option(string $key, mixed $default): array {
        return array('enabled' => true, 'secret' => str_repeat('diagnostic-only-', 4), 'public_url' => 'ws://fixture/classroom-live');
    }
    function get_current_user_id(): int { return $GLOBALS['test_user']; }
    function sanitize_text_field(string $text): string { return $text; }
    function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
    function set_transient(string $key, mixed $value, int $ttl): bool { $GLOBALS['transients'][$key] = $value; return true; }
    function wp_json_encode(mixed $value): string { return json_encode($value); }
    function rest_ensure_response(mixed $value): mixed { return $value; }
    $base = dirname(__DIR__);
    foreach (array('Support/Realtime_Settings', 'Support/Realtime_Ticket', 'Rest/Controllers/Realtime_Controller') as $class) { require $base . '/src/' . $class . '.php'; }
    $GLOBALS['transients'] = array();
    $GLOBALS['test_user'] = 1;
    $request = new WP_REST_Request(array('code' => 'INK123'));
    $teacher = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array('role' => 'viewer');
    $owner_view = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $GLOBALS['test_user'] = 2;
    $student = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array('role' => 'host');
    $forbidden = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array('role' => 'invalid');
    $invalid = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $GLOBALS['test_user'] = 3;
    $request->json = array('role' => 'host');
    $cohost = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array('role' => 'viewer');
    $cohost_view = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $GLOBALS['test_user'] = 4;
    $observer = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array('role' => 'host');
    $observer_host = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $GLOBALS['test_user'] = 9;
    $request->json = array('role' => 'viewer');
    $outsider = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
    $request->json = array();
    $GLOBALS['transients'] = array();
    $GLOBALS['test_user'] = 1;
    $allowed = 0;
    for ($i = 0; $i < 31; $i++) {
        $response = TeachingTools\Rest\Controllers\Realtime_Controller::ticket($request);
        if (!($response instanceof WP_Error)) { $allowed++; }
    }
    echo json_encode(array('teacher' => $teacher, 'ownerView' => $owner_view, 'student' => $student,
        'cohost' => $cohost, 'cohostView' => $cohost_view, 'observer' => $observer,
        'observerHostStatus' => $observer_host instanceof WP_Error ? $observer_host->data['status'] : 200,
        'outsiderStatus' => $outsider instanceof WP_Error ? $outsider->data['status'] : 200,
        'forbiddenStatus' => $forbidden instanceof WP_Error ? ($forbidden->data['status'] ?? 0) : 200,
        'invalidStatus' => $invalid instanceof WP_Error ? ($invalid->data['status'] ?? 0) : 200,
        'rateLimit' => array('allowed' => $allowed, 'lastStatus' => $response instanceof WP_Error ? ($response->data['status'] ?? 0) : 200)));
}
