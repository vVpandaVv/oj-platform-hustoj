<?php
declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';
require __DIR__ . '/statistics-fixture.php';
require __DIR__ . '/student-role-fixture.php';
platform_fixture_apply_check_config(platform_fixture_check_config());
statistics_fixture_load();
platform_fixture_connect_runtime(1, isset($GLOBALS['focus_test_statistics_contexts']) ? array(1, 2) : array(1));
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
student_role_fixture_load($path !== '/__test/student-role-config');
if (platform_fixture_asset($path)) {
    return;
}
header('Cache-Control: no-store');
$body = json_decode((string) file_get_contents('php://input'), true) ?: array();
$request = new WP_REST_Request($body, $_GET);
if (isset($_SERVER['HTTP_X_WP_NONCE'])) { $request->params['_wpnonce'] = (string) $_SERVER['HTTP_X_WP_NONCE']; }
$result = null;
$permission = true;

if ($path === '/__test/reset') {
    student_role_fixture_reset();
    foreach (array('events', 'scores', 'activity_links', 'participations', 'activities', 'checks', 'check_states', 'daily_statistics') as $table) {
        $wpdb->query('DELETE FROM wp_focus_tracker_' . $table);
    }
    // Reset only this fixture's application state, not WordPress bootstrap.
    $wpdb->query("DELETE FROM wp_options WHERE option_name LIKE 'focus_tracker_limit_%' OR option_name LIKE 'focus_tracker_diagnostic_%' OR option_name IN ('focus_tracker_settings','focus_tracker_cleanup_status','focus_tracker_cleanup_progress','focus_tracker_cleanup_lock','focus_tracker_audit_log')");
    platform_fixture_reset_check_config();
    statistics_fixture_reset();
    $result = array('ok' => true);
} elseif ($path === '/__test/student-role-config') {
    $result = student_role_fixture_config($body);
} elseif ($path === '/__test/statistics-config') {
    $result = statistics_fixture_config($body);
} elseif ($path === '/__test/statistics-seed') {
    $result = statistics_fixture_seed();
} elseif ($path === '/__test/age-records') {
    // Move already-ingested evidence to settled days, without relaxing the
    // production 24-hour event admission rule or archiving an unfinished day.
    $offset = max(1, min(365, (int) ($body['days'] ?? 2))) * DAY_IN_SECONDS * 1000;
    foreach ($wpdb->get_results('SELECT * FROM wp_focus_tracker_activities', ARRAY_A) as $row) {
        $wpdb->update('wp_focus_tracker_activities', array(
            'starts_at' => gmdate('Y-m-d H:i:s', intdiv(\FocusTracker\Support\Clock::mysql_to_ms($row['starts_at']) - $offset, 1000)),
            'ends_at' => gmdate('Y-m-d H:i:s', intdiv(\FocusTracker\Support\Clock::mysql_to_ms($row['ends_at']) - $offset, 1000)),
        ), array('id' => $row['id']));
    }
    foreach ($wpdb->get_results('SELECT * FROM wp_focus_tracker_participations', ARRAY_A) as $row) {
        $values = array();
        foreach (array('started_at', 'last_heartbeat_at', 'ended_at') as $field) {
            if (null !== $row[$field . '_ms']) {
                $values[$field . '_ms'] = (int) $row[$field . '_ms'] - $offset;
                $values[$field] = \FocusTracker\Support\Clock::ms_to_mysql($values[$field . '_ms']);
            }
        }
        $wpdb->update('wp_focus_tracker_participations', $values, array('id' => $row['id']));
    }
    foreach ($wpdb->get_results('SELECT id, occurred_at_ms FROM wp_focus_tracker_events', ARRAY_A) as $row) {
        $at = (int) $row['occurred_at_ms'] - $offset;
        $wpdb->update('wp_focus_tracker_events', array('occurred_at_ms' => $at, 'occurred_at' => \FocusTracker\Support\Clock::ms_to_mysql($at)), array('id' => $row['id']));
    }
    $wpdb->query('DELETE FROM wp_focus_tracker_daily_statistics WHERE archived = 0');
    $result = array('ok' => true);
} elseif ($path === '/__test/check-config') {
    $result = platform_fixture_save_check_config($body);
} elseif ($path === '/__test/activity') {
    $now = \FocusTracker\Support\Clock::now_ms();
    $body = array_merge(array(
        'name' => '통합 테스트', 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
        'starts_at' => gmdate('c', (int) floor(($now + ($body['startOffsetMs'] ?? -1000)) / 1000)),
        'ends_at' => gmdate('c', (int) ceil(($now + ($body['endOffsetMs'] ?? 60000)) / 1000)),
    ), $body);
    $result = \FocusTracker\Rest\Controllers\Activities_Controller::create(new WP_REST_Request($body));
} elseif ($path === '/__test/state') {
    $result = array();
    foreach (array('events', 'scores', 'activity_links', 'participations', 'activities', 'checks', 'check_states', 'daily_statistics') as $table) {
        $result[$table] = $wpdb->get_results('SELECT * FROM wp_focus_tracker_' . $table, ARRAY_A);
    }
    $result['nowMs'] = \FocusTracker\Support\Clock::now_ms();
    $result['checkConfig'] = platform_fixture_check_config();
} elseif ($path === '/__test/retention') {
    \FocusTracker\Support\Settings::update(array('retention_days' => max(1, (int) ($body['retention_days'] ?? 1))));
    \FocusTracker\Jobs\Retention_Cleanup::run();
    $result = array('ok' => true);
} elseif ($path === '/__test/settings-save') {
    $save = new ReflectionMethod(\FocusTracker\Admin\Settings_Page::class, 'save');
    try { $error = $save->invoke(null, $body); }
    catch (RuntimeException $error) { http_response_code(503); $error = '설정을 저장하지 못했습니다.'; }
    if ($error !== null && http_response_code() !== 503) { http_response_code(400); }
    $result = array('error' => $error, 'settings' => \FocusTracker\Support\Settings::all());
} elseif ($path === '/__test/dashboard' || $path === '/__test/self' || $path === '/__test/settings') {
    header('Content-Type: text/html; charset=utf-8');
    echo platform_fixture_addon_document($path === '/__test/dashboard' ? 'dashboard' : ($path === '/__test/settings' ? 'settings' : 'self'));
    return;
} elseif ($path === '/wp-json/focus-tracker/v1/contexts') {
    $result = \FocusTracker\Rest\Controllers\Contexts_Controller::index($request);
} elseif ($path === '/wp-json/focus-tracker/v1/activities') {
    $result = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? \FocusTracker\Rest\Controllers\Activities_Controller::create($request)
        : \FocusTracker\Rest\Controllers\Activities_Controller::index($request);
} elseif (preg_match('#^/wp-json/focus-tracker/v1/activities/(\d+)$#', $path, $match) && in_array($_SERVER['REQUEST_METHOD'], array('PATCH', 'DELETE'), true)) {
    $request->params['id'] = (int) $match[1];
    $permission = \FocusTracker\Rest\Controllers\Activities_Controller::can_manage();
    if ($permission === true) {
        $result = $_SERVER['REQUEST_METHOD'] === 'DELETE'
            ? \FocusTracker\Rest\Controllers\Activities_Controller::delete($request)
            : \FocusTracker\Rest\Controllers\Activities_Controller::update($request);
    }
} elseif ($path === '/wp-json/focus-tracker/v1/events') {
    $permission = \FocusTracker\Rest\Controllers\Events_Controller::authorize($request);
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Events_Controller::handle($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/tracking/state' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $permission = \FocusTracker\Rest\Controllers\Tracking_Controller::authorize($request);
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Tracking_Controller::state($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/me/statistics') {
    $permission = \FocusTracker\Rest\Controllers\Statistics_Controller::authorize_self();
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Statistics_Controller::self_statistics($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/statistics') {
    $permission = \FocusTracker\Rest\Controllers\Statistics_Controller::authorize_report();
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Statistics_Controller::report($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/checks/next' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $permission = \FocusTracker\Rest\Controllers\Checks_Controller::authorize($request);
    if ($permission === true) { $result = platform_fixture_check_response(\FocusTracker\Rest\Controllers\Checks_Controller::next($request)); }
} elseif (preg_match('#^/wp-json/focus-tracker/v1/checks/(\d+)/response$#', $path, $match) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $request->params['id'] = (int) $match[1];
    $permission = \FocusTracker\Rest\Controllers\Checks_Controller::authorize($request);
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Checks_Controller::respond($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/checks/summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $permission = \FocusTracker\Rest\Controllers\Checks_Controller::authorize_summary($request);
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Checks_Controller::summary($request); }
} elseif (preg_match('#^/wp-json/focus-tracker/v1/activities/(\d+)/scores$#', $path, $match)) {
    $request->params['id'] = (int) $match[1];
    $permission = \FocusTracker\Rest\Controllers\Scores_Controller::can_view();
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Scores_Controller::activity_scores($request); }
} elseif (preg_match('#^/wp-json/focus-tracker/v1/activities/(\d+)/participants/(\d+)/timeline$#', $path, $match)) {
    $request->params['id'] = (int) $match[1];
    $request->params['user_id'] = (int) $match[2];
    $permission = \FocusTracker\Rest\Controllers\Scores_Controller::can_view_participant($request);
    if ($permission === true) { $result = \FocusTracker\Rest\Controllers\Scores_Controller::timeline($request); }
} elseif ($path === '/wp-json/focus-tracker/v1/me/summary') {
    $result = \FocusTracker\Rest\Controllers\Me_Controller::summary($request);
} elseif ($path === '/wp-admin/admin-post.php') {
    header('Location: /problems/123?submitted=1', true, 303);
    return;
} elseif ($path === '/favicon.ico') {
    http_response_code(204);
    return;
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo platform_fixture_document(is_string($_GET['mode'] ?? null) ? $_GET['mode'] : 'code');
    return;
}
if ($permission instanceof WP_Error) { $result = $permission; }
header('Content-Type: application/json; charset=utf-8');
if ($result instanceof WP_Error) {
    http_response_code((int) ($result->data['status'] ?? 500));
    $error_response = new WP_REST_Response(array('code' => $result->code, 'message' => $result->message, 'data' => $result->data), (int) ($result->data['status'] ?? 500));
    \FocusTracker\Support\Request_Limiter::retry_header($error_response);
    foreach ($error_response->get_headers() as $name => $value) { header($name . ': ' . $value); }
    echo json_encode($error_response->data);
} else {
    echo json_encode($result instanceof WP_REST_Response ? $result->data : $result, JSON_UNESCAPED_UNICODE);
}
