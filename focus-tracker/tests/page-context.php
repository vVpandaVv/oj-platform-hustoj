<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Rest\Controllers\Events_Controller;
use FocusTracker\Rest\Controllers\Tracking_Controller;
use FocusTracker\Rest\Controllers\Activities_Controller;
use FocusTracker\Support\Clock;
use FocusTracker\Tracking\Activity_Window_Gate;
use FocusTracker\Tracking\Page_Context;

$checks = 0;
function page_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function page_error(mixed $value, int $status, string $label): void {
    page_expect(true, $value instanceof WP_Error, $label . ' returns an error');
    page_expect($status, $value->get_error_data()['status'], $label . ' preserves its access boundary');
}
function page_activity(int $start, int $end, string $type = 'class', string $id = 'class:1', ?array $problems = array(123)): int {
    $activity = Activity_Repository::create(array('name' => 'Page context fixture', 'owner_user_id' => 1, 'context_type' => $type, 'context_id' => $id,
        'problem_ids' => $problems, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
    if ($activity === null) { throw new RuntimeException('Could not prepare the authorized schedule.'); }
    return $activity;
}
function page_gate(array $page, array $extra = array()): mixed {
    return Tracking_Controller::state(new WP_REST_Request(array_replace(array('user_id' => 1, 'problem_id' => $page['problemId'], 'page_context' => $page['token']), $extra)));
}
function page_packet(array $page, string $uid, int $start, int $at, int $seq = 1, array $extra = array()): mixed {
    return Events_Controller::handle(new WP_REST_Request(array_replace(array('user_id' => 1, 'problem_id' => $page['problemId'], 'page_context' => $page['token'],
        'session_uid' => $uid, 'session_started_at' => $start, 'events' => array(array('seq' => $seq, 'type' => 'activity', 'occurred_at' => $at, 'meta' => array('visible' => true, 'focused' => true)))), $extra)));
}

platform_fixture_connect_runtime(1, array(1, 2));
$GLOBALS['focus_test_user_id'] = 1;
$now = Clock::now_ms();
$routes = array(
    array('/problems/123', 'problem', null, 123), array('/submit/123', 'submission', 'form', 123),
    array('/submissions/456', 'submission', 'result', null), array('/problems/123/submissions', 'submission', 'result', 123),
    array('/problems/123/statistics', 'submission', 'result', 123), array('/problems/123/ranking', 'submission', 'result', 123),
    array('/quizzes/123/attempts/9', 'practice', 'quiz', null), array('/oj-addons/learning-materials/7', 'material', null, null),
    array('/oj-addons/readcontents-library/lesson-one', 'material', null, null), array('/oj-addons/readcontents-library/topic/intro', 'material', null, null),
    array('/oj-addons/edu-games/games/binary-conversion', 'game', null, null), array('/oj-addons/edu-games/games/ai-model-lab', 'practice', 'ml', null),
    array('/oj-addons/edu-games/games/ai-classifier-lab', 'practice', 'ml', null), array('/oj-addons/oj-ml-playgrounds/lab/4', 'practice', 'ml', null),
    array('/oj-addons/oj-code-lab/lab', 'practice', null, null), array('/oj-addons/oj-primm/learn', 'practice', null, null),
    array('/oj-addons/teaching-tools/join', 'practice', null, null), array('/board/thread/5', 'discussion', null, null),
    array('/notices/5', 'discussion', null, null), array('/groups/1', 'other', null, null), array('/', 'other', null, null),
);
foreach ($routes as [$path, $category, $subtype, $problem]) {
    $page = Page_Context::classify($path);
    page_expect(array($category, $subtype, $problem), array($page['category'], $page['subtype'], $page['problemId']), 'Canonical route classification: ' . $path);
    page_expect(Page_Context::label($category), $page['label'], 'Canonical label: ' . $path);
}
page_expect(123, Page_Context::classify('/submit', array('problem_id' => '123'))['problemId'], 'The query-form submission route keeps its known coding problem');
foreach (array('/login', '/register', '/user-settings', '/messages/thread/private', '/oj-console/users', '/wp-admin', '/wp-json/focus-tracker/v1/events', '/unknown-private-page',
    '//problems/123', '/problems//123', '/problems/123?token=private', '/problems/123#private', "/problems/123\n", '/problems%2f123', '/problems\\123', str_repeat('x', 2050)) as $path) {
    page_expect(null, Page_Context::issue(1, $path), 'Private, unknown or malformed routes do not receive learning claims');
}
$material = Page_Context::issue(1, '/oj-addons/learning-materials/7', array(), $now - 1000);
$verified = Page_Context::verify($material['token'], 1, $now);
page_expect('material', $verified['category'], 'The server signature authenticates the canonical category');
page_expect(null, $verified['problemId'], 'A signed material has no fabricated coding-problem number');
page_expect(false, str_contains($material['token'], 'learning-materials/7?'), 'The claim contains no raw query URL');
page_error(Page_Context::verify($material['token'], 2, $now), 403, 'A token cannot move to another student');
[$encoded, $signature] = explode('.', $material['token']);
$claim = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true, 32, JSON_THROW_ON_ERROR);
$claim['category'] = 'other';
$tampered = rtrim(strtr(base64_encode(wp_json_encode($claim)), '+/', '-_'), '=') . '.' . $signature;
page_error(Page_Context::verify($tampered, 1, $now), 403, 'A browser cannot relabel a resource with an old signature');
page_error(Page_Context::verify(array('category' => 'material'), 1, $now), 403, 'An unsigned metadata object is not a token');
$future = Page_Context::issue(1, '/problems/123', array(), $now + 5000);
page_error(Page_Context::verify($future['token'], 1, $now), 403, 'Future-issued claims fail closed');
page_error(page_gate($material, array('problem_id' => 123)), 403, 'A null-problem claim cannot assert an arbitrary problem');
$known = Page_Context::issue(1, '/problems/123', array(), $now - 1000);
page_error(page_gate($known, array('problem_id' => null)), 403, 'A known problem cannot be downgraded to avoid its problem filter');
page_error(Tracking_Controller::state(new WP_REST_Request(array('problem_id' => null))), 400, 'A non-problem page requires a signed descriptor');

$activity = page_activity($now - 60000, $now + 60000);
page_expect(false, Activity_Window_Gate::state(1, null)['active'], 'Unsigned internal callers do not acquire generic page authorization');
page_expect(true, page_gate($material)->data['active'], 'A signed learning material is recorded during a problem-filtered activity');
$otherProblem = Page_Context::issue(1, '/problems/999', array(), $now - 1000);
page_expect(false, page_gate($otherProblem)->data['active'], 'A known different coding problem still fails the strict filter');
$ingest = page_packet($material, 'signed-material-source', $now - 5000, $now - 100);
page_expect(true, $ingest instanceof WP_REST_Response && $ingest->data['stored'] > 0, 'The actual ingest pipeline commits a signed non-problem page');
$participation = Participation_Repository::find($ingest->data['participation_id']);
page_expect(null, $participation['problem_id'], 'The persisted material keeps a nullable problem ID');
page_expect(array('material', null, 'learning-materials:7', 1), array($participation['page_category'], $participation['page_subtype'], $participation['page_key'], $participation['page_context_version']), 'The participation stores the authenticated page identity');
page_expect($now - 1000, $participation['started_at_ms'], 'Logical source start cannot invent time before its page claim existed');
page_expect(array($activity), Participation_Repository::activity_ids($participation['id']), 'The permitted material links to the existing real activity');
$repeat = page_packet($material, 'signed-material-source', $now - 5000, $now - 100);
page_expect(0, $repeat->data['stored'], 'A signed replay does not duplicate its physical event');
$game = Page_Context::issue(1, '/oj-addons/edu-games/games/binary-conversion', array(), $now - 1000);
$gameIngest = page_packet($game, 'signed-material-source', $now - 5000, $now - 100);
page_expect(true, $gameIngest->data['participation_id'] !== $participation['id'], 'Different authenticated resources never overwrite the previous page identity');

$renewable = Page_Context::issue(1, '/problems/123', array(), $now - 13 * HOUR_IN_SECONDS * 1000);
$initial = page_packet($renewable, 'signed-renew-source', $now - 5000, $now - 100);
$renewed = Page_Context::renew($renewable, 1, $now);
page_expect(true, $renewed['token'] !== $renewable['token'], 'A nearly expired page token is renewed');
page_expect($renewable['issuedAtMs'], $renewed['issuedAtMs'], 'Renewal preserves the original page boundary');
page_expect($now + Page_Context::TOKEN_LIFETIME_MS, $renewed['expiresAtMs'], 'Renewal grants another bounded 24-hour token');
$next = page_packet($renewed, 'signed-renew-source', $now - 5000, $now - 50, 2);
page_expect($initial->data['participation_id'], $next->data['participation_id'], 'Token renewal does not split or mismatch an existing physical source');
$renewedGate = page_gate($renewable);
page_expect(true, isset($renewedGate->data['pageContextToken']) && $renewedGate->data['pageContextToken'] !== $renewable['token'], 'The production lease response delivers token renewal to a long-open page');

$legacy = Events_Controller::handle(new WP_REST_Request(array('problem_id' => 123, 'session_uid' => 'legacy-page-source', 'session_started_at' => $now - 5000,
    'page_category' => 'material', 'page_subtype' => 'neutral', 'events' => array(array('seq' => 1, 'type' => 'activity', 'occurred_at' => $now - 100)))));
$legacyRow = Participation_Repository::find($legacy->data['participation_id']);
page_expect(array('unclassified', 0), array($legacyRow['page_category'], $legacyRow['page_context_version']), 'Legacy problem packets remain compatible without accepting a browser neutral-category claim');
page_expect(null, $legacyRow['page_subtype'], 'Unsigned subtype claims do not enter persisted metadata');

// A real schedule edit retains neutral navigation; reconciliation cannot copy
// an already scoped source into another group's newly created schedule.
$updated = Activities_Controller::update(new WP_REST_Request(array('problem_ids' => array(999), 'ends_at' => gmdate('c', intdiv($now + 120000, 1000))), array('id' => $activity, '_wpnonce' => 'focus-test-nonce')));
page_expect(true, $updated instanceof WP_REST_Response, 'The actual teacher API changes time and selected problems');
$afterEdit = page_packet($material, 'signed-material-source', $now - 5000, $now - 20, 2);
page_expect(true, $afterEdit instanceof WP_REST_Response && $afterEdit->data['stored'] > 0, 'Signed material collection continues after time and problem-scope edits');
page_expect($participation['id'], $afterEdit->data['participation_id'], 'An edit does not lose the original categorized source');
page_expect(0, page_packet($known, 'problem-excluded-after-edit', $now - 5000, $now - 20)->data['stored'], 'Known coding problems still follow the edited problem filter');
$sameContext = page_activity($now - 120000, $now + 120000, 'class', '1', array(999));
page_expect(true, Participation_Repository::link_matching_sessions(Activity_Repository::find($sameContext), array(1)), 'Reconciliation accepts the canonical numeric context alias');
page_expect(true, in_array($sameContext, Participation_Repository::activity_ids($participation['id']), true), 'Signed neutral records can be linked within their original learning context');
$crossContext = page_activity($now - 120000, $now + 120000, 'class', 'class:2');
page_expect(true, Participation_Repository::link_matching_sessions(Activity_Repository::find($crossContext), array(1)), 'Another context reconciliation completes without exposing scoped evidence');
page_expect(false, in_array($crossContext, Participation_Repository::activity_ids($participation['id']), true), 'A new second-group schedule cannot retroactively acquire the first group’s signed material');
$preGate = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => 1, 'problem_id' => 123, 'session_uid' => 'pre-gate-compatibility', 'started_at_ms' => $now - 2000));
Participation_Repository::link_matching_sessions(Activity_Repository::find($crossContext), array(1));
page_expect(true, in_array($crossContext, Participation_Repository::activity_ids($preGate['id']), true), 'Pre-gate unscoped legacy fixture reconciliation keeps its established policy');
Activities_Controller::update(new WP_REST_Request(array('problem_ids' => array(123)), array('id' => $activity, '_wpnonce' => 'focus-test-nonce')));

// Retain the original 24-hour outbox guarantee even when its page token has
// just expired. Both event time and receipt time remain strictly bounded.
$oldAt = $now - 2 * HOUR_IN_SECONDS * 1000;
page_activity($oldAt - 60000, $oldAt + 60000);
$expired = Page_Context::issue(1, '/oj-addons/learning-materials/8', array(), $now - 25 * HOUR_IN_SECONDS * 1000);
page_error(page_gate($expired), 403, 'Expired claims cannot start new real-time collection');
$late = page_packet($expired, 'offline-material-source', $oldAt - 1000, $oldAt);
page_expect(true, $late instanceof WP_REST_Response && $late->data['stored'] > 0, 'A permitted offline observation is accepted within its 24-hour receipt grace');
$lateRow = Participation_Repository::find($late->data['participation_id']);
page_expect('material', $lateRow['page_category'], 'Delayed delivery retains its authenticated original page category');
$outsideToken = page_packet($expired, 'expired-fresh-attempt', $now - 5000, $now - 100);
page_expect(array(0, 1), array($outsideToken->data['stored'], $outsideToken->data['policyDropped']), 'An expired page cannot attach a fresh event to its offline token');
$tooOld = Page_Context::issue(1, '/oj-addons/learning-materials/8', array(), $now - 49 * HOUR_IN_SECONDS * 1000);
page_error(Page_Context::verify($tooOld['token'], 1, $now, true), 403, 'Even offline token use ends after the bounded receipt grace');

$GLOBALS['focus_test_statistics_contexts'] = array(array('type' => 'group', 'id' => 'group:1', 'label' => '그룹 1'), array('type' => 'group', 'id' => 'group:2', 'label' => '그룹 2'));
page_activity($now - 60000, $now + 60000, 'group', 'group:1');
page_activity($now - 60000, $now + 60000, 'group', 'group:2');
$bound = Page_Context::issue(1, '/groups/1', array(), $now - 1000);
page_expect('group:1', page_gate($bound)->data['contextId'], 'A general client request is narrowed to the page’s signed group');
page_error(page_gate($bound, array('context_type' => 'group', 'context_id' => 'group:2')), 403, 'A group-one page cannot be relabeled as group two');
$boundMaterial = Page_Context::issue(1, '/oj-addons/learning-materials/7', array('class_id' => '1'), $now - 1000);
page_error(page_gate($boundMaterial, array('context_type' => 'group', 'context_id' => 'group:1')), 403, 'Explicit class query context remains bound across material navigation');
$contest = Page_Context::issue(1, '/contests/1', array(), $now - 1000);
page_expect(false, page_gate($contest)->data['active'], 'An explicit contest page retains the existing contest exclusion');
unset($GLOBALS['focus_test_statistics_contexts']);
$GLOBALS['focus_test_user_id'] = 2;
page_error(Tracking_Controller::state(new WP_REST_Request(array('problem_id' => null, 'page_context' => $material['token']))), 403, 'An account change invalidates another learner’s signed descriptor');
$GLOBALS['focus_test_user_id'] = 1;

page_expect(true, Installer::inspect_schema()['ready'], 'The page metadata and activity eligibility columns participate in production schema readiness');
echo "Page context: {$checks} SQL-backed checks passed\n";
