<?php
/** Version upgrade preservation on the disposable native WordPress site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$config = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$phase = $argv[2] ?? '';
if (($config['dbHost'] ?? '') !== '127.0.0.1:33917' || !preg_match('/^focus_acceptance_[a-z0-9_]+$/D', $config['dbName'] ?? '')) {
    throw new RuntimeException('Refusing a non-test database.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:33918';
$_SERVER['REQUEST_URI'] = '/';
require rtrim($config['wpPath'], '/\\') . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || !\FocusTracker\Database\Installer::ready()) {
    throw new RuntimeException('A ready native local installation is required.');
}
$tables = ['activities', 'participations', 'activity_links', 'events', 'scores', 'checks', 'check_states', 'daily_statistics'];
$snapshot = ['version' => FOCUS_TRACKER_VERSION, 'databaseVersion' => get_option('focus_tracker_db_version'), 'tables' => [], 'options' => []];
foreach ($tables as $name) {
    $order = match ($name) { 'activity_links' => 'participation_id, activity_id', 'check_states' => 'user_id', default => 'id' };
    $rows = $wpdb->get_results('SELECT * FROM `' . $wpdb->prefix . 'focus_tracker_' . $name . '` ORDER BY ' . $order, ARRAY_A);
    if ($wpdb->last_error !== '') { throw new RuntimeException($wpdb->last_error); }
    $snapshot['tables'][$name] = ['count' => count($rows), 'sha256' => hash('sha256', (string) wp_json_encode($rows))];
}
foreach (['focus_tracker_settings', 'focus_tracker_student_participation_defaults', 'focus_tracker_statistics_coverage_since'] as $key) {
    $snapshot['options'][$key] = get_option($key, null);
}
$output = rtrim($config['outputDir'], '/\\');
$beforePath = $output . '/upgrade-before.json';
if ($phase === 'before') {
    if (FOCUS_TRACKER_VERSION !== '1.0.0-rc.3') { throw new RuntimeException('Expected the tested RC3 starting version.'); }
    foreach (['activities', 'participations', 'activity_links', 'events', 'checks', 'check_states'] as $name) {
        if ($snapshot['tables'][$name]['count'] === 0) { throw new RuntimeException('The acceptance run must populate ' . $name . ' before upgrade.'); }
    }
    if (is_file($beforePath)) { throw new RuntimeException('Refusing to overwrite the pre-upgrade snapshot.'); }
    file_put_contents($beforePath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "Pre-upgrade native snapshot saved.\n";
} elseif ($phase === 'after') {
    $before = json_decode((string) file_get_contents($beforePath), true, 512, JSON_THROW_ON_ERROR);
    $checks = ['The installed plugin and native database version are 1.0.0' => FOCUS_TRACKER_VERSION === '1.0.0' && $snapshot['databaseVersion'] === '1.0.0'];
    foreach ($snapshot['tables'] as $name => $data) { $checks['All persisted ' . $name . ' records survive the version upgrade byte-for-byte'] = $data === $before['tables'][$name]; }
    foreach ($snapshot['options'] as $key => $value) { $checks[$key . ' survives the version upgrade'] = $value === $before['options'][$key]; }
    $capability = \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('focus_tracker_participate');
    $checks['The inherited subscriber participation grant survives the upgrade'] = (get_role('subscriber')->capabilities[$capability] ?? null) === true;
    $report = ['passed' => !in_array(false, $checks, true), 'assertionCount' => count($checks), 'checks' => $checks,
        'fromVersion' => $before['version'], 'toVersion' => FOCUS_TRACKER_VERSION, 'before' => $before['tables'], 'after' => $snapshot['tables']];
    file_put_contents($output . '/upgrade-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if (!$report['passed']) { throw new RuntimeException('Native version upgrade preservation failed.'); }
    echo 'Native upgrade: ' . count($checks) . " checks passed.\n";
} else { throw new RuntimeException('Expected before or after.'); }
