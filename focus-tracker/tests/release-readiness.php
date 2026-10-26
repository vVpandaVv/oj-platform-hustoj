<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';

use FocusTracker\Database\Installer;
use FocusTracker\Support\Audit_Log;
use FocusTracker\Support\Release_Readiness;
use FocusTracker\Support\Settings;
use FocusTracker\Addon\Operations_Panel;

$checks = 0;
function ready_check(bool $condition, string $message): void {
    global $checks, $wpdb;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message . ' SQL: ' . $wpdb->last_error); }
}
ready_check(Installer::ready(), 'A complete install records ready only after inspecting schema.');
ready_check(Installer::inspect_schema()['ready'], 'Real unique constraints and simulated engine metadata satisfy installation contract.');
$GLOBALS['focus_test_storage_engine'] = 'MyISAM';
update_option('focus_tracker_db_version', '0.6.0-dev');
ready_check(! Installer::install(), 'An engine without transactions is rejected.');
ready_check(get_option('focus_tracker_db_version') === '0.6.0-dev', 'A failed upgrade never advances the installed version.');
ready_check(! Installer::ready(), 'Failed installation remains not ready.');
$request = new WP_REST_Request(array(), array('_route' => '/focus-tracker/v1/events'));
$blocked = Release_Readiness::guard(null, null, $request);
ready_check($blocked instanceof WP_Error && $blocked->data['status'] === 503, 'REST fails closed while schema is incomplete.');
ready_check(Release_Readiness::guard('previous', null, $request) === 'previous', 'An earlier REST result is preserved.');
ready_check(Release_Readiness::guard(null, null, new WP_REST_Request(array(), array('_route' => '/wp/v2/posts'))) === null, 'Other REST APIs are unaffected.');
ob_start(); Release_Readiness::notice(); $notice = ob_get_clean();
ready_check(str_contains($notice, '업그레이드가 완료되지'), 'Administrator sees the installation failure.');
ready_check(Release_Readiness::site_test()['status'] === 'critical', 'WordPress Site Health reports the failed storage.');
unset($GLOBALS['focus_test_storage_engine']);
ready_check(Installer::install() && Installer::ready(), 'A repaired installation can safely retry.');
ready_check(Release_Readiness::site_test()['status'] === 'good', 'Site Health can confirm a repaired storage.');
ready_check(Release_Readiness::guard(null, null, $request) === null, 'Ready installations permit REST dispatch.');
$before_settings = Settings::all();
$before_audit = get_option('focus_tracker_audit_log', array());
$wpdb->fail_pattern = 'INSERT INTO ' . $wpdb->options;
$save_failed = false;
try { Settings::update(array('retention_days' => 61)); } catch (RuntimeException $error) { $save_failed = true; }
ready_check($save_failed && Settings::all() === $before_settings, 'A failed settings write is detected and preserves the saved policy.');
ready_check(get_option('focus_tracker_audit_log', array()) === $before_audit, 'Failed settings writes never create a success audit record.');
// Preserve all data while removing only the idempotency constraint.
$wpdb->query('ALTER TABLE wp_focus_tracker_events RENAME TO events_with_index');
$wpdb->query('CREATE TABLE wp_focus_tracker_events AS SELECT * FROM events_with_index');
$health = Installer::inspect_schema();
ready_check(! $health['ready'] && in_array('events:unique_index', $health['issues'], true), 'A missing event deduplication constraint is detected.');
$wpdb->query('DROP TABLE wp_focus_tracker_events');
$wpdb->query('ALTER TABLE events_with_index RENAME TO wp_focus_tracker_events');
ready_check(Installer::inspect_schema()['ready'], 'Restoring the constraint repairs readiness without dropping source data.');
Release_Readiness::register();
ready_check(isset($GLOBALS['focus_test_filters']['rest_pre_dispatch'], $GLOBALS['focus_test_filters']['site_status_tests'], $GLOBALS['focus_test_hooks']['admin_notices']), 'Health and failure guard are registered.');
ready_check(isset(Release_Readiness::site_tests(array())['direct']['focus_tracker_storage']), 'WordPress health check is discoverable.');
for ($index = 0; $index < 110; ++$index) { Audit_Log::record('activity_updated', $index, array('status', 'source', 'answer_id')); }
$history = get_option('focus_tracker_audit_log');
ready_check(count($history) === 100, 'Change history is bounded.');
ready_check($history[0]['fields'] === array('status') && ! str_contains(json_encode($history), 'answer_id'), 'Only safe changed field names are retained.');
ready_check(count(Audit_Log::for_user(1)) === 100 && Audit_Log::for_user(2) === array(), 'A privacy export contains only its own change records.');
$GLOBALS['focus_test_user_id'] = 2; Audit_Log::record('settings');
ready_check(Audit_Log::erase_user(1) && count(Audit_Log::for_user(2)) === 1 && Audit_Log::for_user(1) === array(), 'Privacy erasure preserves other actors history.');
$GLOBALS['focus_test_user_id'] = 1;
$panel = Operations_Panel::render();
ready_check(str_contains($panel, '운영 상태') && str_contains($panel, '저장소 준비 완료') && str_contains($panel, '남은 정리 작업'), 'Settings show storage, cleanup and request diagnostics.');
$GLOBALS['focus_test_user_id'] = 0;
ready_check(Operations_Panel::render() === '', 'Operational data are hidden from unauthenticated users.');
$GLOBALS['focus_test_user_id'] = 1;
$registry = json_decode(file_get_contents(dirname(__DIR__, 2) . '/_release-kit/plugins.json'), true, 512, JSON_THROW_ON_ERROR);
$focus = array_values(array_filter($registry['plugins'], static fn (array $row): bool => $row['id'] === 'focus-tracker'));
ready_check(count($focus) === 1 && $focus[0]['builder']['type'] === 'generic', 'Exactly one official release-kit package is registered.');
ready_check(! in_array('tests', $focus[0]['builder']['include'], true) && in_array('tests/', $focus[0]['builder']['forbid'], true), 'The install package excludes the HTTP test server and all test code.');
$package = require dirname(__DIR__) . '/plugin-package.php';
ready_check($package['version'] === FOCUS_TRACKER_VERSION && $package['requires'] === array('oj-platform:^2.3.22'), 'Final release version and complete feature platform requirement are aligned.');
echo "Release readiness: {$checks} SQL-backed checks passed\n";
