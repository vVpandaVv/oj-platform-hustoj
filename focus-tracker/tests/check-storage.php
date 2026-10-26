<?php
declare(strict_types=1);

// Production service/controllers/repositories and SQL. WordPress infrastructure
// and the authorised platform context gateway are explicit local test doubles.
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Checks\Check_Service;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Rest\Controllers\Checks_Controller as Check_Api;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

$checks = 0;
function assert_check(bool $condition, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if (! $condition) { throw new RuntimeException($label . ' SQL: ' . $wpdb->last_error); }
}
function expect_check(mixed $expected, mixed $actual, string $label): void {
    assert_check($expected === $actual, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function check_error(mixed $result, int $status, string $label): void {
    assert_check($result instanceof WP_Error && ($result->data['status'] ?? null) === $status, $label);
}

$gateway = new class implements Api\AddonLearningCheckGatewayV1 {
    public bool $allowed = true;
    public bool $samples = true;
    public bool $submissions = true;
    public int $revision = 1;
    public string $source = "# SUBMISSION_SECRET_MUST_NOT_PERSIST\nif attempts < 4:\n    print(attempts)";
    public string $language = 'python';
    public array $queries = array();

    public function context(Api\AddonLearningCheckContextQueryV1 $query): Api\AddonLearningCheckContextResultV1 {
        $this->queries[] = array($query->actorUserId(), $query->problemId(), $query->submissionId(), $query->contextType(), $query->contextId());
        if (! $this->allowed || $query->actorUserId() !== get_current_user_id() || ! in_array($query->problemId(), array(123, 124), true)
            || (null !== $query->submissionId() && $query->submissionId() !== 1000 + $query->actorUserId())) {
            return new Api\AddonLearningCheckContextResultV1(false, array(), new Api\AddonApiProblemV1('addon_access_denied', '자료 접근 거부', 'storage-test-denied', false));
        }
        $problem = array(
            'id' => $query->problemId(), 'title' => '문제 내용 기반 자동 확인',
            'description' => '<p>두 정수를 더합니다. 개정 ' . $this->revision . '</p>',
            'input' => '정수 두 개', 'output' => '두 정수의 합',
            'sample_input' => $this->samples ? "1 2\n" : '',
            'sample_output' => $this->samples ? "3\n" : '',
        );
        $problem['content_hash'] = hash('sha256', json_encode($problem, JSON_UNESCAPED_UNICODE));
        return new Api\AddonLearningCheckContextResultV1(true, array(
            'problem' => $problem,
            'submission' => $this->submissions ? array(
                'id' => 1000 + $query->actorUserId(), 'problem_id' => $query->problemId(),
                'source' => $this->source, 'language_id' => 6, 'language' => $this->language,
                'result_code' => 4, 'submitted_at' => '2026-10-23 00:00:00', 'processing' => false,
            ) : null,
        ), null);
    }
};
$runtime = new class($gateway) implements Api\AddonLearningCheckRuntimeContextV1 {
    public function __construct(private Api\AddonLearningCheckGatewayV1 $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 {
        return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '>=2.3.0 <3.0.0', '2.3.22-dev', '^1.4', '1.4.0', 'accepted', '', 'check-test-catalog', array(), 'check-test-runtime');
    }
    public function contexts(): Api\AddonContextGatewayV1 { return new Platform_Fixture_Context_Gateway(); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('No AI is used to validate these checks.'); }
    public function learningChecks(): Api\AddonLearningCheckGatewayV1 { return $this->gateway; }
};
Runtime_Bridge::connect($runtime);

function check_user(int $id): void {
    $GLOBALS['focus_test_user_id'] = $id;
    $GLOBALS['focus_test_allowed'] = true;
    $GLOBALS['focus_test_nonce_age'] = 1;
}
function next_check(array $extra = array()): array|WP_Error {
    $request = new WP_REST_Request(array_merge(array('problem_id' => 123, 'user_id' => get_current_user_id()), $extra), array('_wpnonce' => 'focus-test-nonce'));
    $allowed = Check_Api::authorize($request);
    if ($allowed instanceof WP_Error) { return $allowed; }
    $result = Check_Api::next($request);
    return $result instanceof WP_Error ? $result : $result->get_data();
}
function respond_check(int $id, array $response): array|WP_Error {
    $request = new WP_REST_Request(array_merge(array('user_id' => get_current_user_id()), $response), array('id' => $id, '_wpnonce' => 'focus-test-nonce'));
    $allowed = Check_Api::authorize($request);
    if ($allowed instanceof WP_Error) { return $allowed; }
    $result = Check_Api::respond($request);
    return $result instanceof WP_Error ? $result : $result->get_data();
}
function ready_check(array $extra = array()): array {
    $result = next_check($extra);
    assert_check(is_array($result) && ($result['status'] ?? null) === 'ready', 'A verified question is ready');
    return $result['check'];
}
function allow_next_check(): void {
    global $wpdb;
    $wpdb->update(Installer::check_states_table(), array('next_allowed_at_ms' => 0), array('user_id' => get_current_user_id()));
}
function stored_check(int $id): array {
    $row = Check_Repository::find($id, get_current_user_id());
    assert_check(is_array($row), 'Question snapshot stored for current learner');
    return $row;
}
function correct_response(array $check, string $uid = 'response-correct-001'): array|WP_Error {
    return respond_check($check['id'], array('answer_id' => stored_check($check['id'])['candidate']['correctAnswer'], 'response_uid' => $uid));
}

Settings::update(array('checks_enabled' => true, 'check_daily_limit' => 20, 'check_cooldown_seconds' => 60));
check_user(1);
$GLOBALS['focus_test_statistics_contexts'] = array(
    array('type' => 'class', 'id' => 'class:1', 'label' => '수업 1'),
    array('type' => 'group', 'id' => 'group:1', 'label' => '모둠 1'),
);
$gate_start = Clock::now_ms() - 1000;
$gate_end = $gate_start + 3600000;
foreach (array('class', 'group') as $gate_type) {
    \FocusTracker\Database\Activity_Repository::create(array('name' => '자동 확인 허용 시간', 'owner_user_id' => 1,
        'context_type' => $gate_type, 'context_id' => $gate_type . ':1', 'problem_ids' => array(123, 124),
        'starts_at' => Clock::ms_to_mysql($gate_start), 'ends_at' => Clock::ms_to_mysql($gate_end),
        'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
}
$nonce_request = new WP_REST_Request(array('user_id' => 1), array('_wpnonce' => 'focus-test-nonce'));
expect_check(true, Check_Api::authorize($nonce_request), 'Current REST nonce authorised');
$GLOBALS['focus_test_nonce_age'] = 2;
expect_check(true, Check_Api::authorize($nonce_request), 'Previous valid REST nonce tick authorised');
check_error(Check_Api::authorize(new WP_REST_Request()), 403, 'Missing nonce rejected');
check_error(Check_Api::authorize(new WP_REST_Request(array(), array('_wpnonce' => 'invalid'))), 403, 'Wrong nonce rejected');
check_error(Check_Api::authorize(new WP_REST_Request(array('user_id' => 2), array('_wpnonce' => 'focus-test-nonce'))), 403, 'Stale login identity rejected');
$GLOBALS['focus_test_allowed'] = false;
check_error(Check_Api::authorize($nonce_request), 403, 'Learner capability required');
check_user(0);
check_error(Check_Api::authorize($nonce_request), 403, 'Anonymous request denied');
check_user(1);

// Seed actual observations and a settled grade, then prove every check outcome
// leaves those independent records byte-for-byte unchanged.
$start = Clock::now_ms() - 120000;
$activity_result = \FocusTracker\Rest\Controllers\Activities_Controller::create(new WP_REST_Request(array(
    'name' => '확인 문항과 독립된 평가', 'context_type' => 'class', 'context_id' => 'class:1',
    'starts_at' => gmdate('c', intdiv($start, 1000)), 'ends_at' => gmdate('c', intdiv($start + 60000, 1000)), 'problem_ids' => array(123),
)));
assert_check($activity_result instanceof WP_REST_Response, 'Baseline activity created');
$events = array();
for ($i = 0; $i <= 4; ++$i) {
    $events[] = array('seq' => $i + 1, 'type' => $i === 0 ? 'session_start' : 'activity', 'occurred_at' => $start + $i * 15000, 'meta' => array('visible' => true, 'focused' => true));
}
$events[] = array('seq' => 6, 'type' => 'session_end', 'occurred_at' => $start + 60000);
$ingest = \FocusTracker\Rest\Controllers\Events_Controller::handle(new WP_REST_Request(array('user_id' => 1, 'session_uid' => 'checks-do-not-change-focus', 'session_started_at' => $start, 'problem_id' => 123, 'is_final' => true, 'events' => $events)));
assert_check($ingest instanceof WP_REST_Response, 'Baseline actual events accepted');
$activity = \FocusTracker\Database\Activity_Repository::find($activity_result->data['id']);
$baseline_score = \FocusTracker\Scoring\Activity_Score_Service::score_for_learner($activity, 1);
expect_check(20.0, $baseline_score['score_total'], 'Baseline grade computed from real event rows');
$tracking_before = array();
foreach (array(Installer::events_table(), Installer::participations_table(), Installer::scores_table()) as $table) {
    $tracking_before[$table] = $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A);
}

$draft_text = "# DRAFT_SECRET_MUST_NOT_PERSIST\nscore = int(input())\nif score >= 60:\n    print('pass')";
$draft_request = array('draft' => array('source' => $draft_text, 'language_id' => 6));
$draft_check = ready_check($draft_request);
expect_check('draft', $draft_check['sourceKind'], 'Current draft can supply first question without submitting');
$private = stored_check($draft_check['id']);
expect_check(64, strlen($private['source_hash']), 'Opaque source fingerprint stored');
assert_check(! str_contains($private['candidate_json'], 'DRAFT_SECRET_MUST_NOT_PERSIST') && ! str_contains($private['candidate_json'], 'score = int(input())'), 'Complete editor draft is not persisted');
assert_check(! str_contains(json_encode($draft_check), 'DRAFT_SECRET_MUST_NOT_PERSIST'), 'Complete draft never appears in public result');
foreach (array('correctAnswer', 'proof', 'explanation', 'sourceHash', 'candidate_json', 'problem_hash', 'source', 'content_hash') as $secret) {
    assert_check(! array_key_exists($secret, $draft_check), 'Private field excluded from public question: ' . $secret);
}
assert_check(str_contains($draft_check['codeSnippet'], 'score = '), 'Displayed question includes all synthetic assignments');
$again = ready_check($draft_request);
expect_check($draft_check['id'], $again['id'], 'Retry returns same pending question');
$edited_draft = ready_check(array('draft' => array('source' => "if newer_value > 4:\n    pass", 'language_id' => 6)));
expect_check($draft_check['codeSnippet'], $edited_draft['codeSnippet'], 'Pending draft question retains its fully displayed issue-time snapshot');
expect_check(1, (int) $wpdb->get_var('SELECT issued_today FROM ' . Installer::check_states_table() . ' WHERE user_id = 1'), 'Pending retry does not consume another quota');
$different_problem = next_check(array('problem_id' => 124));
expect_check('cooldown', $different_problem['status'], 'Other page cannot create a second simultaneous pending question');
check_error(respond_check($draft_check['id'], array('answer_id' => 'not-a-choice', 'response_uid' => 'invalid-choice-001')), 400, 'Unknown answer choice rejected');
expect_check('pending', stored_check($draft_check['id'])['status'], 'Rejected answer does not consume question');
$answered = correct_response($draft_check);
expect_check('correct', $answered['status'], 'Correctness comes from private verified answer');
expect_check(false, $answered['affectsFocusScore'], 'Correct answer cannot change focus score');
expect_check($answered, correct_response($draft_check), 'Same response UID retries idempotently');
check_error(correct_response($draft_check, 'response-correct-002'), 409, 'Second distinct response rejected');
expect_check('cooldown', next_check($draft_request)['status'], 'Actual automatic cooldown enforced after response');

allow_next_check();
$submission_check = ready_check($draft_request);
expect_check('submission', $submission_check['sourceKind'], 'Balanced selection also uses own submitted code');
$submission_private = stored_check($submission_check['id']);
expect_check(1001, $submission_private['submission_id'], 'Submission identity retained for fresh access recheck');
assert_check(! str_contains($submission_private['candidate_json'], 'SUBMISSION_SECRET_MUST_NOT_PERSIST'), 'Full submission source is not persisted either');
$wrong = array_values(array_filter($submission_check['choices'], static fn (array $choice): bool => $choice['id'] !== $submission_private['candidate']['correctAnswer']))[0]['id'];
$incorrect = respond_check($submission_check['id'], array('answer_id' => $wrong, 'correctAnswer' => $wrong, 'response_uid' => 'response-incorrect-001'));
expect_check('incorrect', $incorrect['status'], 'Client cannot forge the server-side correct answer');
expect_check(false, $incorrect['affectsFocusScore'], 'Incorrect answer cannot change focus score');
assert_check(str_contains($incorrect['message'], '미집중으로 판정하지 않습니다'), 'Incorrect answer explicitly avoids inattentive judgement');

allow_next_check();
$sample_check = ready_check($draft_request);
expect_check('problem', $sample_check['sourceKind'], 'Balanced selection also uses public problem content');
expect_check("1 2", $sample_check['input'], 'Public sample input is supplied verbatim');
expect_check('', $sample_check['codeSnippet'], 'Sample recognition does not pretend to run code');
expect_check('unknown', respond_check($sample_check['id'], array('action' => 'unknown', 'response_uid' => 'response-unknown-001'))['status'], 'Unknown is a distinct accepted outcome');
$summary = Check_Service::summary(1);
expect_check(1, $summary['counts']['correct'], 'Summary includes correct response');
expect_check(1, $summary['counts']['incorrect'], 'Summary includes incorrect response separately');
expect_check(1, $summary['counts']['unknown'], 'Summary includes unknown response separately');
expect_check(false, $summary['affectsFocusScore'], 'Summary is separate learning evidence');
$own_summary = Check_Api::summary(new WP_REST_Request(array(), array('user_id' => 999)))->get_data();
expect_check($summary, $own_summary, 'Summary endpoint always derives learner identity from authenticated session');

// No submission is a normal supported state, both with and without a draft.
check_user(2);
$gateway->submissions = false;
$no_submit = ready_check();
expect_check('problem', $no_submit['sourceKind'], 'Student with no submission or draft receives a validated public sample');
expect_check('skipped', respond_check($no_submit['id'], array('action' => 'skip', 'response_uid' => 'response-skip-002'))['status'], 'Skip is separately recorded');
allow_next_check();
expect_check('unavailable', next_check()['status'], 'Identical answered sample is not repeatedly issued');
check_user(3);
$gateway->samples = false;
$draft_only = ready_check($draft_request);
expect_check('draft', $draft_only['sourceKind'], 'Draft-only case works without submission or samples');
$wpdb->update(Installer::checks_table(), array('expires_at_ms' => Clock::now_ms() - 1), array('id' => $draft_only['id']));
expect_check('unanswered', correct_response($draft_only, 'response-expired-003')['status'], 'Late correct answer stays unanswered rather than retroactive correctness');
expect_check('unanswered', stored_check($draft_only['id'])['status'], 'Expiry outcome persisted');
expect_check('unanswered', correct_response($draft_only, 'response-expired-again')['status'], 'Expired outcome retry stays stable');

check_user(4);
$unanswered_check = ready_check($draft_request);
$wpdb->update(Installer::checks_table(), array('expires_at_ms' => Clock::now_ms() - 1), array('id' => $unanswered_check['id']));
$summary = Check_Service::summary(4);
expect_check(1, $summary['counts']['unanswered'], 'Passive expiry is reported even before another poll');
expect_check(0, $summary['counts']['incorrect'], 'No response is not recorded as a wrong answer');
next_check($draft_request);
expect_check('unanswered', stored_check($unanswered_check['id'])['status'], 'Next poll finalises expired pending question');

check_user(5);
$gateway->samples = true;
$owned = ready_check();
check_user(6);
check_error(respond_check($owned['id'], array('action' => 'skip', 'response_uid' => 'cross-user-response')), 404, 'Another account cannot respond to owned question');
check_user(5);
expect_check('pending', stored_check($owned['id'])['status'], 'Cross-account response left owner question unchanged');
$gateway->allowed = false;
expect_check('invalidated', respond_check($owned['id'], array('action' => 'skip', 'response_uid' => 'revoked-access-response'))['status'], 'Revoked platform access invalidates pending evidence');
expect_check('invalidated', stored_check($owned['id'])['status'], 'Invalidation stored with no score effect');
$gateway->allowed = true;

check_user(7);
$changed_problem_check = ready_check();
++ $gateway->revision;
expect_check('invalidated', correct_response($changed_problem_check, 'problem-changed-response')['status'], 'Changed problem content invalidates even an otherwise correct answer');
check_user(8);
$gateway->submissions = true;
$gateway->samples = false;
$changed_source_check = ready_check();
expect_check('submission', $changed_source_check['sourceKind'], 'Submission-only source available');
$old_source = $gateway->source;
$gateway->source .= "\n# changed source revision";
expect_check('invalidated', correct_response($changed_source_check, 'source-changed-response')['status'], 'Changed submission source invalidates issued snapshot');
$gateway->source = $old_source;

// Explicit submission IDs are authorised by the gateway before source lookup.
check_user(9);
expect_check('unavailable', next_check(array('submission_id' => 1008))['status'], 'Another learner submission cannot be requested');
$explicit = ready_check(array('submission_id' => 1009));
expect_check('submission', $explicit['sourceKind'], 'Authorised explicit own submission accepted');
correct_response($explicit, 'explicit-own-response');

// Positive numeric contextual IDs must reach the strict typed platform DTO.
foreach (array('class', 'group') as $index => $type) {
    check_user(20 + $index);
    $contextual = ready_check(array('context_type' => $type, 'context_id' => '1'));
    $last = $gateway->queries[count($gateway->queries) - 1];
    expect_check($type, $last[3], 'Context type preserved');
    expect_check(1, $last[4], 'Numeric context ID normalised for typed platform contract');
    expect_check('correct', correct_response($contextual, 'contextual-response-' . $type)['status'], 'Stored context can be reauthorised for response');
}
check_user(22);
expect_check('inactive', next_check(array('context_type' => 'contest', 'context_id' => '1'))['status'], 'Contest context cannot infer a class/group schedule authorisation');

check_user(30);
$gateway->samples = true;
Settings::update(array('check_daily_limit' => 1));
$quota = ready_check($draft_request);
correct_response($quota, 'quota-first-response');
allow_next_check();
expect_check('cooldown', next_check($draft_request)['status'], 'Daily quota enforced independently of cooldown timestamp');
$wpdb->update(Installer::check_states_table(), array('issued_day' => '2000-01-01', 'next_allowed_at_ms' => 0), array('user_id' => 30));
expect_check('ready', next_check($draft_request)['status'], 'New local calendar day restores quota');
Settings::update(array('check_daily_limit' => 20));

check_user(31);
$gateway->submissions = false;
$gateway->samples = false;
$before_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table());
$unsupported = next_check(array('draft' => array('source' => 'if calculate(value) > 0:', 'language_id' => 6)));
expect_check('unavailable', $unsupported['status'], 'Unverifiable code with no reference sample produces no invented question');
expect_check($before_count, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table()), 'Unsupported grammar creates no evidence row');
check_error(next_check(array('draft' => array('source' => str_repeat('a', 65537), 'language_id' => 6))), 400, 'Oversized draft rejected');
check_error(next_check(array('draft' => array('source' => "if x > 1:\n\xFF", 'language_id' => 6))), 400, 'Invalid UTF-8 draft rejected');
check_error(next_check(array('draft' => array('source' => 'if x > 1:', 'language_id' => 'python'))), 400, 'Invalid editor language identifier rejected');
check_error(next_check(array('problem_id' => 0)), 400, 'Invalid problem identifier rejected');
check_error(next_check(array('submission_id' => -1)), 400, 'Invalid submission identifier rejected');
check_error(next_check(array('context_type' => 'administrator')), 400, 'Unsupported context rejected');
check_error(next_check(array('context_type' => 'class', 'context_id' => 'class:1')), 400, 'Non-numeric contextual identifier rejected');
check_error(next_check(array('context_type' => 'class')), 400, 'Missing contextual identifier rejected');
check_error(next_check(array('context_type' => 'general', 'context_id' => '1')), 400, 'General context cannot inject an unrelated contextual ID');
check_error(respond_check(1, array('action' => 'skip', 'answer_id' => 'a', 'response_uid' => 'ambiguous-response')), 400, 'Ambiguous response intent rejected');
check_error(respond_check(1, array('action' => 'skip', 'response_uid' => 'short')), 400, 'Invalid response UID rejected');

check_user(32);
Settings::update(array('checks_enabled' => false));
expect_check('unavailable', next_check($draft_request)['status'], 'Feature switch suppresses automatic issue');
Settings::update(array('checks_enabled' => true));
$gateway->samples = true;
$wpdb->fail_pattern = 'INSERT INTO ' . Installer::checks_table();
$failed = next_check();
check_error($failed, 503, 'Failed insert is never reported as a ready check');
expect_check(null, Check_Repository::find(999999, 32), 'Missing check safely returns no row');
expect_check(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = 32'), 'Failed transaction rolls back question');
expect_check(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::check_states_table() . ' WHERE user_id = 32'), 'Failed transaction also rolls back quota state');
$retried = ready_check();
$wpdb->fail_pattern = 'UPDATE ' . Installer::checks_table();
check_error(correct_response($retried, 'failed-response-retry'), 503, 'Failed response is not acknowledged');
expect_check('pending', stored_check($retried['id'])['status'], 'Failed response leaves pending evidence unchanged');
expect_check('correct', correct_response($retried, 'failed-response-retry')['status'], 'Same response can retry after storage recovery');

check_user(34);
$wpdb->fail_pattern = 'COMMIT';
check_error(next_check(), 503, 'Commit failure cannot acknowledge a ready check');
expect_check(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = 34'), 'Failed commit rolls back issue snapshot');
expect_check(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::check_states_table() . ' WHERE user_id = 34'), 'Failed commit rolls back daily quota');
$commit_retry = ready_check();
$wpdb->fail_pattern = 'COMMIT';
check_error(correct_response($commit_retry, 'failed-commit-response'), 503, 'Response commit failure cannot acknowledge answer');
expect_check('pending', stored_check($commit_retry['id'])['status'], 'Response rollback preserves pending question');
expect_check('correct', correct_response($commit_retry, 'failed-commit-response')['status'], 'Response retries after failed commit');

// Explicit retention removal only purges question snapshots, not grading data.
check_user(33);
$old = ready_check();
$wpdb->update(Installer::checks_table(), array('issued_at_ms' => Clock::now_ms() - 61 * DAY_IN_SECONDS * 1000), array('id' => $old['id']));
\FocusTracker\Jobs\Retention_Cleanup::run();
expect_check(null, Check_Repository::find($old['id'], 33), 'Configured retention purges old learning-check snapshots');
check_user(32);
assert_check(Check_Repository::find($retried['id'], 32) !== null, 'Recent learning-check snapshot retained');

foreach ($tracking_before as $table => $rows) {
    expect_check($rows, $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A), 'Every check outcome leaves tracking/grade rows unchanged: ' . $table);
}
check_user(1);
$final_score = \FocusTracker\Scoring\Activity_Score_Service::score_for_learner($activity, 1);
expect_check($baseline_score['score_total'], $final_score['score_total'], 'Correct, incorrect, skip, unknown and unanswered have zero grade effect');

// Render the real console form and exercise its save boundary. These helpers
// model WordPress output functions only; the page and sanitiser are production.
if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'http://localhost/wp-admin/' . ltrim($path, '/'); }
}
if (! function_exists('esc_url')) {
    function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
}
if (! function_exists('esc_textarea')) {
    function esc_textarea(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (! function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string {
        $attribute = (string) $checked === (string) $current ? 'checked="checked"' : '';
        if ($display) { echo $attribute; }
        return $attribute;
    }
}
if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name): void {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    }
}
update_option('focus_tracker_settings', array());
expect_check(true, Settings::get('checks_enabled'), 'New installation enables automatic operation without teacher authoring');
$settings_request = new Api\AddonPageRequestV1('addon.focus_tracker.settings', 1, array(), array(), 'ko_KR', 'check-test-settings');
$settings_html = (new \FocusTracker\Addon\Console_Settings_Page_Handler())->handle($settings_request)->body();
assert_check(str_contains($settings_html, '교사가 문항을 만들거나 채점할 필요가 없습니다'), 'Console explains teacher-free operation');
assert_check(str_contains($settings_html, '초안 원문은 저장하지 않으며') && str_contains($settings_html, '미집중 판정이나 집중 점수에 반영하지 않습니다'), 'Console explains draft privacy and nonpunitive outcomes');
assert_check(preg_match('/<input\b(?=[^>]*name="checks_enabled")(?=[^>]*checked="checked")[^>]*>/', $settings_html) === 1, 'Automatic check checkbox renders enabled by default');
assert_check(str_contains($settings_html, 'name="check_cooldown_seconds" value="300"') && str_contains($settings_html, 'name="check_daily_limit" value="3"'), 'Default low-interruption schedule is visible');
assert_check(str_contains($settings_html, 'name="focus_tracker_settings_nonce"'), 'Console form includes save CSRF token');
$save_settings = new ReflectionMethod(\FocusTracker\Admin\Settings_Page::class, 'save');
$save_settings->invoke(null, array('check_cooldown_seconds' => 1, 'check_daily_limit' => 999));
expect_check(false, Settings::get('checks_enabled'), 'Unchecked form checkbox disables automatic issue');
expect_check(array(60, 20), array(Settings::get('check_cooldown_seconds'), Settings::get('check_daily_limit')), 'Submitted settings clamp unsafe frequency and excessive daily count');
$save_settings->invoke(null, array('checks_enabled' => '1', 'check_cooldown_seconds' => 99999, 'check_daily_limit' => -1));
expect_check(array(true, 3600, 1), array(Settings::get('checks_enabled'), Settings::get('check_cooldown_seconds'), Settings::get('check_daily_limit')), 'Enabled form respects opposite operational bounds');
$save_settings->invoke(null, array('checks_enabled' => '1', 'check_cooldown_seconds' => 180, 'check_daily_limit' => 5));
expect_check(array(true, 180, 5), array(Settings::get('checks_enabled'), Settings::get('check_cooldown_seconds'), Settings::get('check_daily_limit')), 'Valid operator configuration saves without distortion');

$privacy = platform_fixture_manifest()->privacyData();
expect_check(1, count($privacy), 'Addon manifest declares learning record privacy information');
assert_check(str_contains(implode(' ', $privacy[0]->dataCategories()), '응답') && str_contains($privacy[0]->administrationNote(), '초안') && str_contains($privacy[0]->retention(), '60일'), 'Manifest discloses response/code scope, draft handling and retention');

$_GET = array();
$_SERVER['REQUEST_URI'] = '/problems/123';
$config = \FocusTracker\Tracking\Tracked_Page_Enqueuer::config(1);
expect_check(true, $config['checks']['enabled'], 'Configured current runtime enables checks on tracked problem');
$older_runtime = new class implements Api\AddonRuntimeContextV1 {
    public function snapshot(): Api\AddonRuntimeSnapshotV1 {
        return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '>=2.3.0 <3.0.0', '2.3.19', '^1.4', '1.4.0', 'accepted', '', 'older-check-catalog', array(), 'older-check-runtime');
    }
    public function contexts(): Api\AddonContextGatewayV1 { return new Platform_Fixture_Context_Gateway(); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('Optional checks are unavailable.'); }
};
Runtime_Bridge::connect($older_runtime);
expect_check(null, Runtime_Bridge::learning_checks(), 'Older runtime has no optional learning-check gateway');
$older_config = \FocusTracker\Tracking\Tracked_Page_Enqueuer::config(1);
expect_check(false, $older_config['checks']['enabled'], 'Older runtime disables optional check client');
assert_check(isset($older_config['restUrl'], $older_config['heartbeatMs']), 'Older runtime preserves ordinary tracking configuration');
expect_check('unavailable', next_check()['status'], 'Older runtime cannot accidentally issue ungrounded questions');
Runtime_Bridge::connect($runtime);

// The platform distinguishes GNU C (13) and GNU C++ (14). Keep canonical
// language in the verifier proof and source hash even for editor aliases.
$gateway->samples = false;
$gateway->submissions = false;
$alias_source = 'if (value >= 7) {}';
foreach (array(13 => 'c', 14 => 'cpp') as $language_id => $canonical) {
    check_user(40 + $language_id);
    $alias_check = ready_check(array('draft' => array('source' => $alias_source, 'language_id' => $language_id)));
    $alias_row = stored_check($alias_check['id']);
    expect_check($canonical, $alias_row['candidate']['proof']['language'], 'Editor alias uses canonical verifier semantics');
    expect_check(hash('sha256', $canonical . "\n" . $alias_source), $alias_row['source_hash'], 'Canonical language binds source fingerprint');
    expect_check('correct', correct_response($alias_check, 'alias-response-' . $language_id)['status'], 'Canonical editor alias question can be verified and answered');
}

echo 'check-storage: ' . $checks . " SQL-backed checks passed\n";
