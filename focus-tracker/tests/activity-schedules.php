<?php
declare(strict_types=1);

/** SQL-backed schedule management, using the production controllers and repositories. */
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Rest\Controllers\Activities_Controller as Controller;
use FocusTracker\Support\Clock;

$checks = 0;
function schedule_check(bool $condition, string $message): void {
    global $checks, $wpdb;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message . ' ' . $wpdb->last_error); }
}
function schedule_error($result, int $status, string $message): void {
    schedule_check($result instanceof WP_Error && $result->data['status'] === $status, $message);
}

$gateway = new class implements Api\AddonAssignmentRosterGatewayV1 {
    public array $allowed = array('group:1' => true, 'group:2' => true);
    public array $rosters = array('group:1' => array(1, 2), 'group:2' => array(3, 4));
    public function context(string $id): Api\AddonContextSnapshotV1 {
        return new Api\AddonContextSnapshotV1('group', $id, $id === 'group:1' ? '첫째 모둠' : '둘째 모둠', 'active', 'revision-1', 'owner');
    }
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 {
        $items = array();
        if ($query->actorUserId() === 1) {
            foreach ($this->allowed as $id => $allowed) {
                if ($allowed) { $items[] = new Api\AddonContextSummaryV1('group', $id, $id === 'group:1' ? '첫째 모둠' : '둘째 모둠', 'active', 'revision-1'); }
            }
        }
        return new Api\AddonContextPageV1(true, $items, '', null);
    }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        $allowed = $request->actorUserId() === 1 && $request->contextType() === 'group' && ($this->allowed[$request->contextId()] ?? false);
        return new Api\AddonContextAccessDecisionV1($allowed, $allowed ? '' : 'addon_context_denied', $allowed ? $this->context($request->contextId()) : null, 'schedule-context');
    }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 {
        return new Api\AddonContextSnapshotResultV1(true, $this->context($request->contextId()), null);
    }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1 {
        $items = array_map(static fn (int $id): Api\AddonAssignmentLearnerV1 => new Api\AddonAssignmentLearnerV1($id, 'membership-1'), $this->rosters[$query->contextId()] ?? array());
        return new Api\AddonAssignmentLearnerPageV1(true, $items, '', 'roster-1', null);
    }
};
Runtime_Bridge::connect(new class($gateway) implements Api\AddonRuntimeContextV1 {
    public function __construct(private Api\AddonContextGatewayV1 $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 {
        return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '^2.3', '2.3.22', '^1.4', '1.4.0', 'accepted', '', 'schedule-catalog', array(), 'schedule-runtime');
    }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('unused'); }
});

$now = intdiv(Clock::now_ms(), 1000) * 1000;
$payload = static fn (string $context = 'group:1', int $start = -120000, int $end = 3600000): array => array(
    'name' => '모둠별 시간', 'context_type' => 'group', 'context_id' => $context,
    'starts_at' => gmdate('c', intdiv($now + $start, 1000)), 'ends_at' => gmdate('c', intdiv($now + $end, 1000)),
    'problem_ids' => array(123),
);
$create = static function (array $input): array {
    $result = Controller::create(new WP_REST_Request($input));
    schedule_check($result instanceof WP_REST_Response && isset($result->data['id']), 'A group schedule is created through the actual controller.');
    return $result->data;
};
$change = static fn (array $activity, array $input) => Controller::update(new WP_REST_Request($input, array('id' => $activity['id'])));

// Saved observations can precede the schedule. Each group's own roster is
// reconciled, with no change to the page-based raw-collection policy.
$first = Participations::find_or_create(array('activity_id' => null, 'user_id' => 1, 'problem_id' => 123, 'session_uid' => 'schedule-first', 'started_at_ms' => $now - 60000));
$second = Participations::find_or_create(array('activity_id' => null, 'user_id' => 3, 'problem_id' => 123, 'session_uid' => 'schedule-second', 'started_at_ms' => $now - 60000));
Participations::touch_heartbeat($first['id'], $now);
Participations::touch_heartbeat($second['id'], $now);
$a = $create($payload());
$b = $create($payload('group:2', -60000, 7200000));
schedule_check($a['contextId'] === 'group:1' && $b['contextId'] === 'group:2', 'Opaque group IDs must survive the API unchanged.');
schedule_check($a['status'] === 'scheduled' && $a['effectiveStatus'] === 'active', 'An automatically progressing active window must not stay labeled scheduled.');
schedule_check(Participations::activity_ids($first['id']) === array($a['id']), 'First-group observations reconcile only with that group.');
schedule_check(Participations::activity_ids($second['id']) === array($b['id']), 'Second-group observations remain separate.');
$list = Controller::index(new WP_REST_Request(array(), array('context_type' => 'group', 'context_id' => 'group:1')));
schedule_check($list instanceof WP_REST_Response && count($list->data) === 1 && $list->data[0]['id'] === $a['id'], 'The actual SQL list filters one group without leaking the other.');
schedule_check($list->data[0]['contextLabel'] === '첫째 모둠', 'Schedule rows show the current authorized group label.');
$all = Controller::index(new WP_REST_Request());
schedule_check(count($all->data) === 2, 'The all-context view includes both separately stored schedules.');
$changed = $change($a, array('name' => '첫째 모둠 수정', 'starts_at' => gmdate('c', intdiv($now - 180000, 1000)),
    'ends_at' => gmdate('c', intdiv($now + 1800000, 1000)), 'problem_ids' => array(123, 124, 123), 'weight_focus' => 4.5, 'weight_activity' => 0));
schedule_check($changed instanceof WP_REST_Response && $changed->data['name'] === '첫째 모둠 수정', 'The owning teacher can edit a group schedule.');
$storedA = Activities::find($a['id']);
$storedB = Activities::find($b['id']);
schedule_check($storedA['problem_ids'] === array(123, 124) && $storedA['weight_focus'] === 4.5 && $storedA['weight_activity'] === 0.0, 'Problem selection and scoring weights save with validation and deduplication.');
schedule_check($storedB['ends_at'] === str_replace(array('T', 'Z'), array(' ', ''), $b['endsAt']) && $storedB['problem_ids'] === array(123), 'Editing one group must not alter another group schedule.');

// The same instant is canonical UTC regardless of the request offset. Naive
// API callers retain the site's Asia/Seoul interpretation for compatibility.
$offset = $create(array_merge($payload(), array('starts_at' => '2026-10-24T09:30:00+09:00', 'ends_at' => '2026-10-24T10:30:00+09:00')));
$utc = $create(array_merge($payload(), array('starts_at' => '2026-10-24T00:30:00Z', 'ends_at' => '2026-10-24T01:30:00Z')));
$naive = $create(array_merge($payload(), array('starts_at' => '2026-10-24T09:30', 'ends_at' => '2026-10-24T10:30')));
schedule_check($offset['startsAt'] === '2026-10-24T00:30:00Z' && $offset['startsAt'] === $utc['startsAt'] && $utc['startsAt'] === $naive['startsAt'], 'Explicit offsets and legacy local input resolve to the same UTC instant.');
$midnight = $create(array_merge($payload(), array('starts_at' => '2026-10-24T00:15:00+09:00', 'ends_at' => '2026-10-24T00:45:00+09:00')));
schedule_check($midnight['startsAt'] === '2026-10-23T15:15:00Z', 'Timezone conversion correctly crosses a UTC day boundary.');
$dates = new ReflectionMethod(Controller::class, 'to_mysql_utc');
schedule_check($dates->invoke(null, '2028-02-29T09:00:00+09:00') === '2028-02-29 00:00:00', 'A real leap day is accepted.');
$GLOBALS['focus_test_timezone'] = 'America/New_York';
schedule_check($dates->invoke(null, '2026-10-24T09:00:00') === '2026-10-24 13:00:00', 'Legacy naive API dates use the configured site timezone rather than the PHP host timezone.');
schedule_check($dates->invoke(null, '2026-10-24T09:00:00Z') === '2026-10-24 09:00:00', 'Explicit client offsets remain authoritative when site and browser timezones differ.');
schedule_check($dates->invoke(null, '2026-03-08T02:30:00') === null, 'A nonexistent daylight-saving local time must not silently shift.');
$GLOBALS['focus_test_timezone'] = 'Asia/Seoul';
foreach (array('2026-02-29T09:00:00+09:00', '2026-04-31T09:00:00Z', 'tomorrow', '2026-10-24T24:00:00Z',
    '2026-10-24T10:60:00Z', '2026-10-24T10:00:60Z', '0000-01-01T10:00:00Z', '2026-10-24T10:00:00+99:00', '2026-10-24T10:00:00+14:30') as $invalid) {
    schedule_check($dates->invoke(null, $invalid) === null, 'Invalid or relative calendar values cannot silently roll over: ' . $invalid);
}
$fixed = array('status' => 'scheduled', 'starts_at' => '2026-10-24 00:00:00', 'ends_at' => '2026-10-24 01:00:00');
foreach (array('2026-10-23 23:59:59' => 'scheduled', '2026-10-24 00:00:00' => 'active',
    '2026-10-24 00:59:59' => 'active', '2026-10-24 01:00:00' => 'closed') as $instant => $expected) {
    schedule_check(Activities::effective_status($fixed, $instant) === $expected, 'Schedule status uses exact UTC start/end boundaries.');
}
$fixed['status'] = 'closed';
schedule_check(Activities::effective_status($fixed, '2026-10-23 23:59:59') === 'closed', 'An explicitly closed schedule stays closed.');

// Invalid edits leave the persisted schedule unchanged.
$beforeInvalid = Activities::find($a['id']);
foreach (array(array('ends_at' => $beforeInvalid['starts_at']), array('starts_at' => 'tomorrow'), array('name' => ''),
    array('name' => array()), array('starts_at' => null), array('weight_focus' => -1), array('weight_activity' => 101),
    array('problem_ids' => array('123x')), array('problem_ids' => '123'), array('problem_ids' => array(0)),
    array('context_id' => 'group:2'), array('context_type' => 'class'), array('owner_user_id' => 2), array('status' => 'unknown')) as $invalid) {
    schedule_error($change($a, $invalid), 400, 'Invalid or cross-context schedule edits must fail.');
}
schedule_check(Activities::find($a['id']) === $beforeInvalid, 'Failed edits must not mutate the existing schedule.');
foreach (array(array('context_type' => 'contest'), array('context_id' => '../group:1'), array('context_id' => str_repeat('x', 161)),
    array('starts_at' => '2026-02-30T09:00:00Z'), array('starts_at' => array()), array('problem_ids' => array(-1))) as $invalid) {
    schedule_error(Controller::create(new WP_REST_Request(array_merge($payload(), $invalid))), 400, 'Invalid schedule creation is rejected before persistence.');
}
schedule_error(Controller::create(new WP_REST_Request($payload('group:99'))), 403, 'Unmanaged groups cannot receive a schedule.');
$GLOBALS['focus_test_user_id'] = 2;
schedule_error($change($a, array('name' => 'other teacher')), 404, 'Another teacher cannot edit a schedule they do not own.');
schedule_check(Controller::index(new WP_REST_Request())->data === array(), 'Another teacher cannot enumerate the owner schedules.');
$GLOBALS['focus_test_user_id'] = 1;
$gateway->allowed['group:1'] = false;
schedule_error($change($a, array('name' => 'revoked')), 403, 'Ownership alone does not bypass a revoked group role.');
schedule_error(Controller::index(new WP_REST_Request(array(), array('context_type' => 'group', 'context_id' => 'group:1'))), 403, 'A revoked group filter must fail closed.');
$visible = Controller::index(new WP_REST_Request())->data;
schedule_check(count($visible) === 1 && $visible[0]['contextId'] === 'group:2', 'Revoked group activities disappear from the aggregate owner list.');
$gateway->allowed['group:1'] = true;
$GLOBALS['focus_test_allowed'] = false;
schedule_error(Controller::can_manage(), 403, 'The REST feature permission remains mandatory.');
$GLOBALS['focus_test_allowed'] = true;

// Closing now seals the scoring window, and cannot be used to create a
// negative duration for a future schedule or to extend a past interval.
$future = $create($payload('group:1', 3600000, 7200000));
schedule_error($change($future, array('status' => 'closed')), 400, 'A future activity cannot be closed into a negative duration.');
$closed = $change($a, array('status' => 'closed'));
schedule_check($closed instanceof WP_REST_Response && $closed->data['status'] === 'closed' && $closed->data['effectiveStatus'] === 'closed', 'The teacher can close an active group schedule now.');
schedule_check(abs(Clock::mysql_to_ms(Activities::find($a['id'])['ends_at']) - Clock::now_ms()) < 2000, 'Early close uses the server UTC time.');
schedule_check(Activities::find($b['id'])['ends_at'] === $storedB['ends_at'], 'Early close cannot end another group schedule.');
schedule_error($change($a, array('status' => 'active')), 409, 'An explicitly closed schedule cannot be reopened.');
schedule_error($change($a, array('ends_at' => $payload()['ends_at'])), 409, 'An explicitly closed schedule cannot have its time changed.');
schedule_check($closed->data['canEditSchedule'] === false, 'The teacher UI receives an explicit immutable-schedule state after closing.');
$past = $create($payload('group:1', -7200000, -3600000));
$pastEnd = Activities::find($past['id'])['ends_at'];
$pastClose = $change($past, array('status' => 'closed'));
schedule_check($pastClose instanceof WP_REST_Response && Activities::find($past['id'])['ends_at'] === $pastEnd, 'Closing a naturally ended schedule does not extend its observation window.');
$filtered = Controller::index(new WP_REST_Request(array(), array('context_type' => 'group', 'context_id' => 'group:2', 'status' => 'active')));
schedule_check(count($filtered->data) === 1 && $filtered->data[0]['id'] === $b['id']
    && $filtered->data[0]['status'] === 'scheduled' && $filtered->data[0]['effectiveStatus'] === 'active',
    'Status filters use the effective time window rather than the stored scheduled flag.');
schedule_error(Controller::index(new WP_REST_Request(array(), array('context_type' => 'group'))), 400, 'An incomplete context filter is rejected.');

// A retained final snapshot must not be silently invalidated after its raw
// events have been deleted; a name-only change remains harmless.
$wpdb->query('INSERT INTO ' . Installer::scores_table() . ' (activity_id, user_id, raw_events_expired, finalized, total_active_seconds, total_hidden_seconds, focus_ratio, blur_count, avg_recovery_seconds, activity_window_ratio, score_focus, score_selfreg, score_activity, score_total, computed_at)'
    . ' VALUES (' . $b['id'] . ", 3, 1, 1, 60, 0, 100, 0, 0, 100, 8, 6, 6, 20, '2026-10-24 00:00:00')");
schedule_error($change($b, array('weight_focus' => 4)), 409, 'Archived scoring snapshots prohibit changes that require erased source records.');
$renamed = $change($b, array('name' => '보관된 모둠 활동'));
schedule_check($renamed instanceof WP_REST_Response && $renamed->data['name'] === '보관된 모둠 활동', 'An archived schedule may retain its snapshot while updating only its display name.');
schedule_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::scores_table() . ' WHERE activity_id = ' . $b['id'] . ' AND raw_events_expired = 1') === 1, 'A display-name change preserves the archived final aggregate.');
schedule_check($renamed->data['canEditSchedule'] === false, 'The UI does not offer scoring-parameter edits for erased raw source.');

fwrite(STDOUT, "Activity schedules: {$checks} SQL-backed checks passed\n");
