<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';

use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Tracking\Page_Context;

$checks = 0;
function page_privacy_assert(bool $value, string $message): void {
    global $checks;
    if (!$value) { throw new RuntimeException($message); }
    $checks++;
}
function get_user_by(string $field, string $value): object|false {
    return $field === 'email' && $value === 'category-owner@example.test' ? (object) ['ID' => 7] : false;
}
function scope_matches(string $path): bool {
    foreach (Settings::get('tracked_url_patterns') as $pattern) { if (preg_match($pattern, $path) === 1) { return true; } }
    return false;
}
update_option('focus_tracker_settings', ['tracked_url_patterns' => ['#^/(problems|submit)(/|$)#'], 'retention_days' => 41]);
page_privacy_assert(scope_matches('/oj-addons/edu-games/games/binary-conversion'), 'The old built-in scope expands to educational games');
page_privacy_assert(scope_matches('/oj-addons/edu-games/games/ai-model-lab'), 'ML lab routes are included in the new default scope');
page_privacy_assert(scope_matches('/oj-addons/learning-materials/1') && scope_matches('/board/thread/1'), 'Materials and discussion are included without individual teacher setup');
page_privacy_assert(Settings::get('retention_days') === 41, 'Expanding the scope preserves existing retention settings');
page_privacy_assert(!scope_matches('/wp-admin/users.php') && !scope_matches('/messages') && !scope_matches('/user-settings'), 'Private and administration paths remain outside the default scope');
update_option('focus_tracker_settings', ['tracked_url_patterns' => ['#^/submit/123$#']]);
page_privacy_assert(Settings::get('tracked_url_patterns') === ['#^/submit/123$#'], 'A custom scope survives the upgrade unchanged');
update_option('focus_tracker_settings', ['tracked_url_patterns' => ['#^/(problems|submit)(/|$)#']]);
Settings::update(['tracked_url_patterns' => ['#^/(problems|submit)(/|$)#']]);
page_privacy_assert(!scope_matches('/board/thread/1'), 'An explicit later choice of the narrow former scope stays narrow');
page_privacy_assert(get_option('focus_tracker_settings')['page_scope_version'] === 1, 'The explicit scope choice is stored atomically with the settings');
Settings::update(['tracked_url_patterns' => Settings::defaults()['tracked_url_patterns']]);

$now = Clock::now_ms();
$GLOBALS['focus_test_user_id'] = 7;
$page = Page_Context::issue(7, '/oj-addons/learning-materials/1', [], $now - 2000);
page_privacy_assert(is_array($page), 'The server issues an actual signed material-page context');
$own = Participation_Repository::find_or_create(array_merge(['user_id' => 7, 'problem_id' => null, 'activity_id' => null,
    'session_uid' => 'page-privacy-owner', 'started_at_ms' => $now - 1000], Page_Context::columns($page)));
$peerPage = Page_Context::issue(8, '/oj-addons/edu-games/games/binary-conversion', [], $now - 2000);
$peer = Participation_Repository::find_or_create(array_merge(['user_id' => 8, 'problem_id' => null, 'activity_id' => null,
    'session_uid' => 'page-privacy-peer', 'started_at_ms' => $now - 1000], Page_Context::columns($peerPage)));
page_privacy_assert($own !== null && $peer !== null, 'Category sessions are persisted separately for two learners');
Event_Repository::insert_batch($own['id'], [['type' => 'heartbeat', 'seq' => 1, 'occurred_at' => $now - 900, 'meta' => ['visible' => true, 'focused' => true]]]);
Event_Repository::insert_batch($peer['id'], [['type' => 'heartbeat', 'seq' => 1, 'occurred_at' => $now - 900, 'meta' => ['visible' => true, 'focused' => true]]]);
$export = Personal_Data::export_data('category-owner@example.test');
page_privacy_assert(is_array($export) && $export['done'], 'WordPress privacy export handles the new page columns');
$json = wp_json_encode($export, JSON_UNESCAPED_UNICODE);
page_privacy_assert(str_contains($json, 'page_category') && str_contains($json, 'learning-materials:1'), 'The owner can export their category and resource identifier');
page_privacy_assert(!str_contains($json, 'page-privacy-peer') && !str_contains($json, 'edu-games:games:binary-conversion'), 'The export excludes the other learner’s page history');
page_privacy_assert(!str_contains($json, $page['token']), 'Page authorization tokens are never stored or exported with learning history');
$erased = Personal_Data::erase_data('category-owner@example.test');
page_privacy_assert(is_array($erased) && $erased['done'] && $erased['items_removed'], 'The existing privacy eraser removes category history');
page_privacy_assert(Participation_Repository::find($own['id']) === null, 'The erased category session is removed');
page_privacy_assert(Participation_Repository::find($peer['id']) !== null, 'Erasure preserves the peer’s category session');
page_privacy_assert((int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id=' . $own['id']) === 0, 'The erased owner’s category events are removed');
echo 'Page scope/privacy: ' . $checks . " checks passed\n";
