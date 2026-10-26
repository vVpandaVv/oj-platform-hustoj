<?php
declare(strict_types=1);

/** Local HTTP test infrastructure, never included by the production plugin. */
function statistics_fixture_load(): void {
    global $wpdb;
    $wpdb->pdo->exec('CREATE TABLE IF NOT EXISTS focus_tracker_fixture_statistics_config (id INTEGER PRIMARY KEY, config_json TEXT NOT NULL)');
    $json = $wpdb->pdo->query('SELECT config_json FROM focus_tracker_fixture_statistics_config WHERE id = 1')->fetchColumn();
    if (! is_string($json)) { return; }
    $config = json_decode($json, true);
    if (! is_array($config)) { return; }
    $GLOBALS['focus_test_statistics_contexts'] = array(
        array('type' => 'class', 'id' => 'class:1', 'label' => '수업 1'),
        array('type' => 'group', 'id' => 'group:1', 'label' => '그룹 하나'),
        array('type' => 'group', 'id' => 'group:2', 'label' => '그룹 둘'),
    );
    $GLOBALS['focus_test_user_id'] = max(0, (int) ($config['userId'] ?? 1));
    $GLOBALS['focus_test_statistics_deny_context'] = ! empty($config['denyContext']);
}

function statistics_fixture_config(array $input): array {
    global $wpdb;
    $stored = $wpdb->pdo->query('SELECT config_json FROM focus_tracker_fixture_statistics_config WHERE id = 1')->fetchColumn();
    $config = is_string($stored) ? json_decode($stored, true) : array();
    $config = is_array($config) ? $config : array();
    foreach (array('userId', 'denyContext') as $key) {
        if (array_key_exists($key, $input)) { $config[$key] = $input[$key]; }
    }
    $statement = $wpdb->pdo->prepare('INSERT INTO focus_tracker_fixture_statistics_config (id, config_json) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET config_json=excluded.config_json');
    $statement->execute(array(json_encode($config, JSON_THROW_ON_ERROR)));
    statistics_fixture_load();
    return $config;
}

function statistics_fixture_reset(): void {
    global $wpdb;
    $wpdb->pdo->exec('DELETE FROM focus_tracker_fixture_statistics_config');
    unset($GLOBALS['focus_test_statistics_contexts'], $GLOBALS['focus_test_statistics_deny_context']);
}

function statistics_fixture_session(int $user_id, int $problem_id, int $start, int $duration_seconds, string $suffix, array $activities = array()): void {
    $row = \FocusTracker\Database\Participation_Repository::find_or_create(array(
        'activity_id' => null, 'user_id' => $user_id, 'problem_id' => $problem_id,
        'session_uid' => 'statistics-fixture-' . $suffix, 'started_at_ms' => $start,
    ));
    if (null === $row) { throw new RuntimeException('Fixture participation failed'); }
    $end = $start + $duration_seconds * 1000;
    $events = array(array('type' => 'session_start', 'occurred_at' => $start, 'seq' => 1, 'meta' => array('visible' => true, 'focused' => true)));
    $sequence = 2;
    for ($at = $start + 15000; $at < $end; $at += 15000) {
        $events[] = array('type' => 'heartbeat', 'occurred_at' => $at, 'seq' => $sequence++, 'meta' => array('visible' => true, 'focused' => true));
    }
    foreach (array('activity', 'submit') as $offset => $type) {
        $events[] = array('type' => $type, 'occurred_at' => $start + ($offset + 1) * 1000, 'seq' => $sequence++, 'meta' => array('subtype' => 'code_edit'));
    }
    $events[] = array('type' => 'session_end', 'occurred_at' => $end, 'seq' => $sequence, 'meta' => array('visible' => false, 'focused' => false));
    if (false === \FocusTracker\Database\Event_Repository::insert_batch((int) $row['id'], $events)) { throw new RuntimeException('Fixture events failed'); }
    \FocusTracker\Database\Participation_Repository::touch_heartbeat((int) $row['id'], $end);
    \FocusTracker\Database\Participation_Repository::close((int) $row['id'], $end, 'pagehide');
    \FocusTracker\Database\Participation_Repository::record_event_revision((int) $row['id'], count($events));
    foreach ($activities as $activity_id) { \FocusTracker\Database\Participation_Repository::link_activity((int) $row['id'], $activity_id); }
}

function statistics_fixture_seed(): array {
    global $wpdb;
    statistics_fixture_config(array('userId' => 1, 'denyContext' => false));
    $today = (new DateTimeImmutable('now', wp_timezone()))->setTime(0, 0);
    $date = $today->modify('-2 days');
    $start = $date->setTime(10, 0)->getTimestamp() * 1000;
    $activity_ids = array();
    foreach (array(1 => '그룹 하나 활동', 2 => '그룹 둘 활동') as $group_id => $label) {
        $result = \FocusTracker\Rest\Controllers\Activities_Controller::create(new WP_REST_Request(array(
            'name' => $label, 'context_type' => 'group', 'context_id' => 'group:' . $group_id,
            'starts_at' => gmdate('c', intdiv($start - 60000, 1000)),
            'ends_at' => gmdate('c', intdiv($start + 900000, 1000)),
            'problem_ids' => array($group_id === 1 ? 123 : 124),
        )));
        if ($result instanceof WP_Error) { throw new RuntimeException($result->get_error_message()); }
        $activity_ids[$group_id] = (int) $result->get_data()['id'];
    }
    statistics_fixture_session(1, 123, $start, 300, 'one-a', array($activity_ids[1]));
    statistics_fixture_session(1, 123, $start + 60000, 300, 'one-overlap', array($activity_ids[1]));
    statistics_fixture_session(1, 124, $start + 420000, 120, 'two', array($activity_ids[2]));
    statistics_fixture_session(2, 123, $start, 60, 'another-user', array($activity_ids[1]));
    statistics_fixture_session(1, 123, $date->modify('-7 days')->setTime(10, 0)->getTimestamp() * 1000, 120, 'previous');
    return array('date' => $date->format('Y-m-d'), 'today' => $today->format('Y-m-d'), 'activityIds' => $activity_ids,
        'expectedSelfActiveSeconds' => 480, 'expectedGroupOneActiveSeconds' => 360, 'expectedGroupTwoActiveSeconds' => 120);
}
