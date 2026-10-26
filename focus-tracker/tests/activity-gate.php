<?php
declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Event_Repository as Events;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Rest\Controllers\Events_Controller as Ingest;
use FocusTracker\Rest\Controllers\Tracking_Controller as Tracking;
use FocusTracker\Scoring\Score_Calculator;
use FocusTracker\Support\Clock;
use FocusTracker\Tracking\Activity_Window_Gate as Gate;

$checks = 0;
function gate_check(bool $condition, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if (! $condition) { throw new RuntimeException($label . ' ' . $wpdb->last_error); }
}
$gateway = new class implements Api\AddonLearningContextGatewayV1 {
    public bool $member = true;
    public bool $failed = false;
    public bool $partial = false;
    public array $contexts = array(array('class', 'class:1'), array('group', 'group:10'), array('group', 'group:20'));
    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1 {
        if ($this->failed || ($this->partial && $query->cursor() !== '')) {
            return new Api\AddonContextPageV1(false, array(), '', new Api\AddonApiProblemV1('addon_context_unavailable', 'Unavailable', 'gate-context', true));
        }
        $items = $this->member ? array_map(static fn (array $value): Api\AddonContextSummaryV1 => new Api\AddonContextSummaryV1($value[0], $value[1], '학습 그룹', 'active', 'revision-1'), $this->contexts) : array();
        return new Api\AddonContextPageV1(true, $items, $this->partial ? 'later' : '', null);
    }
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { throw new LogicException('unused'); }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 { throw new LogicException('unused'); }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new LogicException('unused'); }
};
Runtime_Bridge::connect(new class($gateway) implements Api\AddonRuntimeContextV1 {
    public function __construct(private Api\AddonContextGatewayV1 $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '^2.3', '2.3.22', '^1.4', '1.4.0', 'accepted', '', 'gate-catalog', array(), 'gate-runtime'); }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('unused'); }
});
$now = intdiv(Clock::now_ms(), 1000) * 1000;
$make = static function (int $start, int $end, string $type = 'group', string $id = 'group:10', ?array $problems = array(123)): int {
    $id = Activities::create(array('name' => '허용된 활동', 'owner_user_id' => 50, 'context_type' => $type, 'context_id' => $id,
        'problem_ids' => $problems, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end),
        'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
    gate_check(is_int($id), 'The schedule fixture uses real SQL storage.');
    return $id;
};
$post = static fn (array $input) => Ingest::handle(new WP_REST_Request($input));
$payload = static fn (array $events, string $uid = 'gate-source', string $type = 'group', string $id = '10'): array => array(
    'user_id' => 1, 'problem_id' => 123, 'session_uid' => $uid, 'session_started_at' => $now - 60000,
    'context_type' => $type, 'context_id' => $id, 'events' => $events,
);
$event = static fn (int $seq, string $type, int $at, array $meta = array('visible' => true, 'focused' => true)): array => array('seq' => $seq, 'type' => $type, 'occurred_at' => $at, 'meta' => $meta);

$off = Gate::state(1, 123, 'general', null, $now);
gate_check(is_array($off) && ! $off['active'] && $off['windows'] === array(), 'No activity means no collection or checks.');
$outside = $post($payload(array($event(1, 'activity', $now - 1000))));
gate_check($outside instanceof WP_REST_Response && $outside->data['acknowledged'] && $outside->data['stored'] === 0 && $outside->data['policyDropped'] === 1, 'Outside events are acknowledged as a policy drop without infinite retries.');
gate_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table()) === 0, 'Outside input creates no empty participation.');
gate_check(Tracking::authorize(new WP_REST_Request()) instanceof WP_Error, 'The lease endpoint requires a WordPress nonce.');
$nonceRequest = new WP_REST_Request(array('user_id' => 1, 'problem_id' => 123), array('_wpnonce' => 'focus-test-nonce'));
gate_check(Tracking::authorize($nonceRequest) === true, 'The current learner and nonce may probe schedule state.');
gate_check(Tracking::state($nonceRequest)->data['active'] === false, 'The actual state controller stays inactive without a schedule.');
gate_check(Tracking::authorize(new WP_REST_Request(array('user_id' => 2), array('_wpnonce' => 'focus-test-nonce'))) instanceof WP_Error, 'Stale-page identity is rejected before granting a lease.');

$future = $make($now + 30000, $now + 60000);
$scheduled = Gate::state(1, 123, 'group', '10', $now);
gate_check(! $scheduled['active'] && $scheduled['nextStartsAtMs'] === $now + 30000 && count($scheduled['windows']) === 1, 'A page opened early gets only a future wake-up boundary.');
gate_check($scheduled['validUntilMs'] <= $now + 10000, 'An inactive response is not a permanent future authorization.');
$start = Gate::state(1, 123, 'group', 'group:10', $now + 30000);
gate_check($start['active'] && $start['windowStartsAtMs'] === $now + 30000 && $start['contextId'] === 'group:10', 'The start boundary is inclusive and typed numeric IDs normalize to the authorized opaque ID.');
gate_check($start['validUntilMs'] === $now + 40000 && $start['activityIds'] === array($future), 'An active lease lasts no more than ten seconds.');
gate_check(! Gate::state(1, 123, 'group', '10', $now + 60000)['active'], 'The exact end boundary is exclusive.');
gate_check(! Gate::state(1, 999, 'group', '10', $now + 30000)['active'], 'A schedule cannot authorize another problem.');
gate_check(! Gate::state(1, 123, 'group', '20', $now + 30000)['active'], 'A page explicitly belonging to another group cannot borrow a matching schedule.');
gate_check(! Gate::state(1, 123, 'contest', '1', $now + 30000)['active'], 'A contest page does not inherit unrelated class/group authorization.');
$overlap = $make($now + 50000, $now + 90000);
$adjacent = $make($now + 90000, $now + 120000);
$component = Gate::state(1, 123, 'group', '10', $now + 70000);
gate_check($component['active'] && $component['windowStartsAtMs'] === $now + 30000 && $component['windowEndsAtMs'] === $now + 120000, 'An overlapping/adjacent chain retains one continuous run across the ordinary first-window end.');
gate_check($component['activityIds'] === array($future, $overlap, $adjacent), 'The continuous authorization contains every contributing activity ID.');
$nearEnd = Gate::state(1, 123, 'group', '10', $now + 119999);
gate_check($nearEnd['validUntilMs'] === $now + 120000, 'The lease cannot cross a natural schedule end.');
$gateway->member = false;
gate_check(! Gate::state(1, 123, 'general', null, $now + 40000)['active'], 'Revoking membership immediately removes authorization.');
$gateway->member = true;
$gateway->failed = true;
gate_check(Gate::state(1, 123) instanceof WP_Error, 'Missing membership infrastructure fails closed.');
$gateway->failed = false;
$gateway->partial = true;
gate_check(Gate::state(1, 123) instanceof WP_Error, 'An incomplete later membership page cannot authorize any window.');
$gateway->partial = false;
gate_check(Gate::state(2, 123) instanceof WP_Error, 'Gate callers cannot request another current actor.');
gate_check(Tracking::state(new WP_REST_Request(array('problem_id' => 123, 'context_type' => 'group', 'context_id' => array()))) instanceof WP_Error, 'Invalid typed values are rejected.');

// Clear only schedule fixtures before exercising a mixed, delayed batch.
$wpdb->query('DELETE FROM ' . Installer::activities_table());
$first = $make($now - 40000, $now - 20000);
$second = $make($now - 10000, $now + 20000);
$secondOverlap = $make($now - 5000, $now + 40000);
$raw = array($event(1, 'session_start', $now - 60000), $event(2, 'activity', $now - 40001),
    $event(3, 'session_start', $now - 40000), $event(4, 'activity', $now - 35000), $event(5, 'heartbeat', $now - 25000),
    $event(6, 'activity', $now - 20000), $event(7, 'session_end', $now - 20000), $event(8, 'activity', $now - 15000),
    $event(9, 'session_start', $now - 10000), $event(10, 'activity', $now - 5000), $event(11, 'heartbeat', $now - 1000),
    $event(12, 'activity', $now + 25000));
$mixed = $post($payload($raw));
gate_check($mixed instanceof WP_REST_Response && $mixed->data['acknowledged'] && count($mixed->data['participation_ids']) === 2, 'One physical outbox is segmented into two authorized runs separated by a gap.');
gate_check($mixed->data['policyDropped'] === 5, 'Before-start, exact-end work, gap, and future observations are discarded as policy exclusions.');
$parts = $wpdb->get_results('SELECT * FROM ' . Installer::participations_table() . ' ORDER BY started_at_ms', ARRAY_A);
gate_check(count($parts) === 2 && (int) $parts[0]['started_at_ms'] === $now - 40000 && (int) $parts[1]['started_at_ms'] === $now - 10000, 'Server logical sessions never begin before an authorized run.');
gate_check((int) $parts[0]['ended_at_ms'] === $now - 20000 && (int) $parts[1]['ended_at_ms'] === $now + 40000, 'Session metadata is bounded by each continuous run, including the future hard deadline.');
gate_check((int) $parts[0]['has_delivery_gaps'] === 0 && (int) $parts[1]['has_delivery_gaps'] === 0, 'Intentional policy exclusions are not recorded as transmission loss.');
gate_check(Participations::activity_ids((int) $parts[0]['id']) === array($first), 'Historical observations link only the historical activity.');
$secondIds = Participations::activity_ids((int) $parts[1]['id']); sort($secondIds);
gate_check($secondIds === array($second, $secondOverlap), 'The other run links every overlapping/future authorization without duplicating observations.');
$saved = $wpdb->get_results('SELECT * FROM ' . Installer::events_table() . ' ORDER BY occurred_at_ms', ARRAY_A);
gate_check(count(array_filter($saved, static fn (array $row): bool => $row['event_type'] === 'activity')) === 2, 'Overlapping activities never double-count an edit.');
foreach ($saved as $row) {
    $part = (int) $row['participation_id'] === (int) $parts[0]['id'] ? $parts[0] : $parts[1];
    $at = (int) $row['occurred_at_ms'];
    gate_check($at >= (int) $part['started_at_ms'] && ($at < (int) $part['ended_at_ms'] || ($row['event_type'] === 'session_end' && $at === (int) $part['ended_at_ms'])), 'Every persisted raw observation lies inside its run; only an end control may sit at the exclusive boundary.');
}
$before = count($saved);
$retry = $post($payload($raw));
gate_check($retry instanceof WP_REST_Response && $retry->data['stored'] === 0 && count($retry->data['participation_ids']) === 2, 'Segmented retries retain stable identifiers and exactly-once sequences.');
gate_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table()) === $before, 'Retrying an offline mixed batch does not append duplicates.');
$inputs = array();
foreach ($parts as $row) { $part = Participations::find((int) $row['id']); $part['events'] = Events::list_for_participations(array($part['id']))[$part['id']]; $inputs[] = $part; }
$timeline = Score_Calculator::timeline_for_participations($inputs, $now - 60000, $now + 60000, $now + 60000);
gate_check(max(array_column($timeline['intervals'], 'endedAt')) <= $now + 40000, 'Heartbeat grace cannot infer learning after the stored schedule deadline if the final browser packet is lost.');
gate_check(empty(array_filter($timeline['intervals'], static fn (array $span): bool => $span['startedAt'] < $now - 20000 && $span['endedAt'] > $now - 10000)), 'The scorer never bridges a non-scheduled gap.');

$gateway->member = false;
$revoked = $post($payload(array($event(20, 'activity', $now - 500)), 'revoked'));
gate_check($revoked instanceof WP_REST_Response && $revoked->data['stored'] === 0, 'An old cached browser lease cannot upload after membership is revoked.');
$gateway->member = true;
$gateway->failed = true;
$failed = $post($payload(array($event(21, 'activity', $now - 500)), 'unavailable'));
gate_check($failed instanceof WP_Error && $failed->data['status'] === 503, 'Unavailable membership checks leave the outbox unacknowledged for a safe retry.');
$gateway->failed = false;
$forged = $payload(array($event(22, 'activity', $now - 500)), 'forged', 'group', '20');
$forged['activity_ids'] = array($second);
gate_check($post($forged)->data['stored'] === 0, 'Client activity IDs cannot grant another page context a collection window.');
$invalid = $post($payload(array($event(23, 'activity', $now + 90000)), 'bad-time'));
gate_check($invalid instanceof WP_Error && $invalid->data['status'] === 400, 'Invalid clock bounds reject the whole batch before storage.');
gate_check($post($payload(array($event(0, 'activity', $now - 1000)), 'reserved-sequence')) instanceof WP_Error, 'Clients cannot use the server-reserved start sequence.');

// Explicit close stops present collection but historical in-window records
// remain eligible. The effective end comes from the saved server close time.
$stop = intdiv(Clock::now_ms(), 1000) * 1000;
foreach (array($second, $secondOverlap) as $id) { Activities::update($id, array('status' => 'closed', 'ends_at' => Clock::ms_to_mysql($stop))); }
gate_check(! Gate::state(1, 123, 'group', '10')['active'], 'Manual close stops current collection and question eligibility.');
$afterClose = $post($payload(array($event(30, 'heartbeat', $stop - 1), $event(31, 'activity', $stop), $event(32, 'session_end', $stop)), 'delayed-closed'));
gate_check($afterClose instanceof WP_REST_Response && $afterClose->data['stored'] > 0 && $afterClose->data['policyDropped'] === 1, 'After close, offline data strictly before the actual end is accepted, exact-end activity is dropped, and the boundary end control is accepted.');
$closePart = Participations::find((int) $afterClose->data['participation_id']);
gate_check($closePart['ended_at_ms'] === $stop, 'Delayed post-close storage cannot extend a logical participation beyond the stop boundary.');
Activities::update($second, array('ends_at' => Clock::ms_to_mysql($stop + 50000)));
gate_check(! Gate::state(1, 123, 'group', '10')['active'], 'A legacy closed row with a future configured end still stops at its recorded closure time.');

// Retroactive edits can change a logical run without changing the physical
// browser source. Its acknowledged sequence must remain exactly once.
$wpdb->query('DELETE FROM ' . Installer::activities_table());
$editNow = intdiv(Clock::now_ms(), 1000) * 1000;
$beforeRun = $make($editNow - 120000, $editNow - 60000);
$afterRun = $make($editNow - 60000, $editNow + 60000);
$original = $payload(array($event(101, 'activity', $editNow - 30000)), 'edited-run');
$initial = $post($original);
$initialId = (int) $initial->data['participation_id'];
Activities::update($beforeRun, array('ends_at' => Clock::ms_to_mysql($editNow - 90000)));
$splitRetry = $post($original);
gate_check($splitRetry instanceof WP_REST_Response && $splitRetry->data['stored'] === 0 && $splitRetry->data['participation_id'] === $initialId, 'A run split cannot duplicate a previously acknowledged physical-source sequence.');
$newAfterSplit = $post($payload(array($event(102, 'activity', $editNow - 20000)), 'edited-run'));
$splitId = (int) $newAfterSplit->data['participation_id'];
gate_check($newAfterSplit instanceof WP_REST_Response && $splitId !== $initialId, 'A genuinely new observation uses the edited run without discarding it.');
$sourceParts = array(Participations::find($initialId), Participations::find($splitId));
gate_check(strlen($sourceParts[0]['session_uid']) === 64 && strlen($sourceParts[1]['session_uid']) === 64 && substr($sourceParts[0]['session_uid'], 0, 36) === substr($sourceParts[1]['session_uid'], 0, 36), 'Logical runs retain a stable physical-source prefix within the existing 64-character storage bound.');
$sourceEvents = array_merge(...array_values(Events::list_for_participations(array($initialId, $splitId))));
gate_check(count(array_filter($sourceEvents, static fn (array $row): bool => $row['type'] === 'activity')) === 2, 'Schedule edits leave both genuine edits present exactly once.');
Activities::update($beforeRun, array('ends_at' => Clock::ms_to_mysql($editNow - 60000)));
$joinedRetry = $post($payload(array($event(102, 'activity', $editNow - 20000)), 'edited-run'));
gate_check($joinedRetry->data['stored'] === 0 && $joinedRetry->data['participation_id'] === $splitId, 'Joining an edited run back together also preserves cross-segment idempotency.');

// A real page end remains authoritative when an offline packet arrives after
// the later natural window end, even without the optional final flag.
$wpdb->query('DELETE FROM ' . Installer::activities_table());
$endedRun = $make($editNow - 60000, $editNow - 10000);
$ended = $post($payload(array($event(201, 'session_start', $editNow - 50000), $event(202, 'activity', $editNow - 40000),
    $event(203, 'heartbeat', $editNow - 30000), $event(204, 'session_end', $editNow - 20000)), 'early-real-end'));
$endedPart = Participations::find((int) $ended->data['participation_id']);
gate_check($endedPart['ended_at_ms'] === $editNow - 20000, 'A synthetic natural end cannot extend metadata past the earlier real page end.');
$endedEvents = Events::list_for_participations(array($endedPart['id']))[$endedPart['id']];
gate_check(count(array_filter($endedEvents, static fn (array $row): bool => $row['seq'] === 202 && $row['occurred_at'] === $editNow - 40000)) === 1, 'Early-end reconciliation preserves every valid final edit.');

$wpdb->query('DELETE FROM ' . Installer::activities_table());
$continuedRun = $make($editNow - 60000, $editNow + 60000);
$oldFinal = $payload(array($event(301, 'heartbeat', $editNow - 30000)), 'delayed-final-metadata');
$oldFinal['is_final'] = true;
$oldFinalResult = $post($oldFinal);
$continued = $post($payload(array($event(302, 'heartbeat', $editNow - 10000)), 'delayed-final-metadata'));
gate_check(Participations::find((int) $continued->data['participation_id'])['ended_at_ms'] === $editNow - 10000, 'Later valid observations cannot be truncated by older final metadata.');
$post($oldFinal);
gate_check(Participations::find((int) $oldFinalResult->data['participation_id'])['ended_at_ms'] === $editNow - 10000, 'An out-of-order retry of final metadata cannot retract an already persisted later observation.');

fwrite(STDOUT, "Activity gate: {$checks} SQL-backed checks passed\n");
