<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Core\Plugin;
use OjMicrobit\Storage\Installer;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\Privacy;
use OjMicrobit\Support\Settings;

$testCron = []; $testCronFailure = false;
function wp_next_scheduled(string $hook, array $args = []): int|false { return $GLOBALS['testCron'][$hook . ':' . json_encode($args)] ?? false; }
function wp_schedule_single_event(int $time, string $hook, array $args = []): bool {
    if ($GLOBALS['testCronFailure']) { return false; }
    $GLOBALS['testCron'][$hook . ':' . json_encode($args)] = $time; return true;
}
function pendingCount(string $table, string $key, int $user): int {
    return (int) $GLOBALS['wpdb']->pdo->query("SELECT COUNT(*) FROM wp_oj_microbit_{$table} WHERE {$key}={$user}")->fetchColumn();
}

$wpdb = new SqliteWpdb(); $repo = new WpdbRepository($wpdb); $project = fixtureProject(); $exercise = fixtureExercise();
$result = fixtureResult($project, $exercise); $result['_visibility'] = ['public' => true, 'secret' => false];
for ($index = 0; $index < 205; $index++) { $repo->saveAttempt(1, 1, 10, $project, $result); }
$repo->saveAttempt(1, 1, 20, $project, $result);
$repo->saveProject(1, 10, $project, 0); $repo->saveProject(1, 20, $project, 0);
$repo->saveExercise($exercise, 10, null, 0); $repo->saveExercise($exercise, 20, null, 0);
$wpdb->pdo->exec("INSERT INTO wp_oj_microbit_exercise_classes(exercise_id,class_id,status,archived) VALUES (1,7,'published',0),(2,7,'published',0)");
$wpdb->queries = [];
Privacy::eraseUser(10);
verify(pendingCount('attempts', 'user_id', 10) === 105, 'Account deletion removes at most 100 attempts in one request');
verify(pendingCount('projects', 'user_id', 20) === 1 && pendingCount('attempts', 'user_id', 20) === 1, 'Composite project deletion and attempt deletion preserve another user');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes WHERE exercise_id=1')->fetchColumn() === 0, 'Teacher erasure removes class links for the bounded owned exercise IDs');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes WHERE exercise_id=2')->fetchColumn() === 1, 'Deleting a teacher never erases another teacher class assignment');
verify(count($testCron) === 1 && wp_next_scheduled('oj_microbit_erase_user', [10]) !== false, 'Remaining rows schedule a single retry with stable user arguments');
verify((int) $wpdb->pdo->query("SELECT window_start FROM wp_oj_microbit_limits WHERE scope_key='erase:10'")->fetchColumn() === PHP_INT_MAX, 'Pending erasure is durable beyond transient cron events');
$repo->purge(0);
verify((int) $wpdb->pdo->query("SELECT COUNT(*) FROM wp_oj_microbit_limits WHERE scope_key='erase:10'")->fetchColumn() === 1, 'Quota expiry never removes the pending erasure marker');
Privacy::eraseUser(10);
verify(pendingCount('attempts', 'user_id', 10) === 5 && count($testCron) === 1, 'Duplicate callbacks remain bounded and do not duplicate the retry event');
$testCron = []; // Simulate a cron event consumed while the plugin is inactive.
$testOptions[Settings::OPTION] = ['retentionDays' => 0];
Plugin::cleanup();
verify(pendingCount('attempts', 'user_id', 10) === 0 && (int) $wpdb->pdo->query("SELECT COUNT(*) FROM wp_oj_microbit_limits WHERE scope_key='erase:10'")->fetchColumn() === 0, 'Maintenance resumes the durable marker and clears it only after completion');
verify(pendingCount('attempts', 'user_id', 20) === 1, 'Retention disabled keeps unrelated learner records');
$deleteQueries = array_filter($wpdb->queries, static fn (string $query): bool => str_starts_with($query, 'DELETE FROM') && !str_contains($query, 'limits'));
verify(count($deleteQueries) >= 4 && array_reduce($deleteQueries, static fn (bool $ok, string $query): bool => $ok && str_contains($query, ' IN ('), true), 'Privacy record deletes always target a previously bounded primary-key set');

$repo->saveAttempt(1, 1, 30, $project, $result); $wpdb->fail = 'DELETE FROM `wp_oj_microbit_attempts`';
Privacy::eraseUser(30);
verify(isset($testOptions['oj_microbit_privacy_cleanup_error']) && wp_next_scheduled('oj_microbit_erase_user', [30]) !== false, 'Failed erasure records an operational error and retains a retry');
verify(pendingCount('attempts', 'user_id', 30) === 1, 'Storage failure never pretends that the attempt was deleted');
$wpdb->fail = null; Privacy::eraseUser(30);
verify(!isset($testOptions['oj_microbit_privacy_cleanup_error']) && pendingCount('attempts', 'user_id', 30) === 0, 'Retry completes the actual deletion and clears the latest error');

$testUsers['batch@test.invalid'] = (object) ['ID' => 20];
for ($index = 0; $index < 3; $index++) { $repo->saveAttempt(1, 1, 20, $project, $result); }
$wpdb->queries = []; $items = [];
for ($page = 1; $page <= 8; $page++) {
    $export = Privacy::exportData('batch@test.invalid', $page);
    verify(is_array($export) && count($export['data']) <= 6, 'Export hydrates no more than one large row per table per callback');
    $items = array_merge($items, $export['data']);
    if ($export['done']) { break; }
}
verify($page === 5 && count($items) === 6 && count(array_unique(array_column($items, 'item_id'))) === 6, 'Paged export completes with all owned rows and no duplicates');
foreach ($items as $item) {
    $stored = json_decode($item['data'][1]['value'], true, 64, JSON_THROW_ON_ERROR);
    if (!isset($stored['result_json'])) { continue; }
    $exported = json_decode($stored['result_json'], true, 64, JSON_THROW_ON_ERROR);
    verify(!isset($exported['_visibility'], $exported['scenarios'][1]['assertions']), 'Every exported attempt retains hidden-test redaction');
}
$exports = array_values(array_filter($wpdb->queries, static fn (string $query): bool => str_starts_with($query, 'SELECT record.*')));
verify(count($exports) === 30 && str_contains($exports[6], 'LIMIT 1 OFFSET 1'), 'WordPress page contract skips only keys before hydrating a single raw record');
$testUsers['other@test.invalid'] = (object) ['ID' => 99];
verify(Privacy::exportData('other@test.invalid')['done'] && Privacy::exportData('other@test.invalid')['data'] === [], 'Privacy pagination remains scoped to the requested user');

$wpdb = new SqliteWpdb(); $repo = new WpdbRepository($wpdb); $testCron = [];
for ($index = 0; $index < 19; $index++) {
    $repo->saveAttempt(1, 1, 20, $project, $result);
}
$wpdb->pdo->exec("UPDATE wp_oj_microbit_attempts SET result_status=''");
Plugin::cleanup();
verify((int) $wpdb->pdo->query("SELECT COUNT(*) FROM wp_oj_microbit_attempts WHERE result_status<>''")->fetchColumn() === 8, 'One maintenance callback converts no more than eight legacy summaries');
verify(wp_next_scheduled('oj_microbit_cleanup_continue') !== false, 'Incomplete bounded work schedules a continuation');
$events = count($testCron); Plugin::cleanup();
verify(count($testCron) === $events && pendingCount('attempts', 'user_id', 20) === 19, 'Continuation is deduplicated and retention zero preserves history');
$testCron = []; $testCronFailure = true; Plugin::cleanup();
verify(!isset($testOptions['oj_microbit_cleanup_error']), 'Completed work does not require a continuation');
$wpdb->pdo->exec("UPDATE wp_oj_microbit_attempts SET result_status=''");
Plugin::cleanup();
verify(isset($testOptions['oj_microbit_cleanup_error']), 'Failed scheduling of unfinished maintenance remains observable');
$testCronFailure = false;
$testOptions['oj_microbit_schema_version'] = Installer::SCHEMA_VERSION;
$health = Settings::healthTests([])['direct']['oj_microbit_storage']['test'];
verify($health()['status'] === 'recommended', 'Site health surfaces maintenance failure');
delete_option('oj_microbit_cleanup_error');
verify($health()['status'] === 'good', 'Installed schema with no cleanup errors has healthy storage status');
for ($index = 0; $index < 105; $index++) { $repo->saveAttempt(1, 1, 20, $project, $result); }
$erase = Privacy::eraseData('batch@test.invalid', 1);
verify($erase['items_removed'] && !$erase['done'] && pendingCount('attempts', 'user_id', 20) === 24, 'WordPress eraser reports unfinished bounded work instead of premature completion');
$erase = Privacy::eraseData('batch@test.invalid', 2);
verify($erase['done'] && pendingCount('attempts', 'user_id', 20) === 0, 'Next eraser page deletes remaining rows without OFFSET skips');

$wpdb = new SqliteWpdb(); $repo = new WpdbRepository($wpdb); $testCron = [];
$classExercise = $exercise; $classExercise['audience'] = ['mode' => 'classes', 'classIds' => range(1, 50)];
for ($index = 0; $index < 3; $index++) { $repo->saveExercise($classExercise, 40, null, 0); }
$repo->saveExercise($classExercise, 41, null, 0);
Privacy::eraseUser(40);
verify(pendingCount('exercises', 'owner_id', 40) === 1, 'Class assignment erasure processes at most two owned exercise keys per callback');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 100, 'A maximum-size class assignment erasure deletes no more than 100 link rows');
verify(wp_next_scheduled('oj_microbit_erase_user', [40]) !== false, 'A teacher with more exercises retains a bounded continuation');
Privacy::eraseUser(40);
verify(pendingCount('exercises', 'owner_id', 40) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 50, 'Continuation removes only the remaining owned links and keeps another teacher assignments');
$wpdb->fail = 'DELETE FROM `wp_oj_microbit_exercise_classes`'; Privacy::eraseUser(41);
verify(pendingCount('exercises', 'owner_id', 41) === 1 && isset($testOptions['oj_microbit_privacy_cleanup_error']), 'A failed link erasure preserves its parent and records a retryable storage error');
$wpdb->fail = null; Privacy::eraseUser(41);
verify(pendingCount('exercises', 'owner_id', 41) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Link erasure retry completes without orphaned class assignments');
$repo->saveExercise($classExercise, 42, null, 0); $wpdb->queries = [];
$wpdb->fail = 'DELETE FROM `wp_oj_microbit_exercises`'; Privacy::eraseUser(42);
verify(pendingCount('exercises', 'owner_id', 42) === 1 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 50, 'A parent delete failure rolls back already-deleted class links atomically');
verify(in_array('ROLLBACK', $wpdb->queries, true) && count(array_filter($wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'))) === 1, 'Parent rows are locked before assignment deletion and failure releases the transaction');
$wpdb->fail = null; Privacy::eraseUser(42);
verify(pendingCount('exercises', 'owner_id', 42) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Atomic erasure can be retried after the parent failure');
$repo->saveExercise($classExercise, 43, null, 0); $wpdb->queries = []; $wpdb->engine = 'MyISAM'; Privacy::eraseUser(43);
verify(pendingCount('exercises', 'owner_id', 43) === 1 && !in_array('START TRANSACTION', $wpdb->queries, true), 'Non-transactional storage is refused before deleting parent or class assignments');
$wpdb->engine = 'InnoDB'; Privacy::eraseUser(43);
verify(pendingCount('exercises', 'owner_id', 43) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Erasure resumes after transactional storage is repaired');
$repo->saveAttempt(1, 1, 44, $project, $result); $wpdb->denyLock = true;
$deferred = Privacy::eraseBatch(44);
verify($deferred === ['removed' => 0, 'more' => true] && pendingCount('attempts', 'user_id', 44) === 1, 'Erasure defers without deleting records while a grading lease is busy');
$wpdb->denyLock = false; $releaseCount = $wpdb->releases;
verify(Privacy::eraseBatch(44)['removed'] >= 1 && pendingCount('attempts', 'user_id', 44) === 0 && $wpdb->releases === $releaseCount + 1, 'A deferred erase completes after the grading lease is released and releases its own lock');

$wpdb = new SqliteWpdb(); $repo = new WpdbRepository($wpdb); $testCron = [];
$groupExercise = $exercise; $groupExercise['audience'] = ['mode' => 'groups', 'groupIds' => range(1, 50)];
for ($index = 0; $index < 3; $index++) { $repo->saveExercise($groupExercise, 40, null, 0); }
$repo->saveExercise($groupExercise, 41, null, 0);
Privacy::eraseUser(40);
verify(pendingCount('exercises', 'owner_id', 40) === 1, 'Group assignment erasure processes at most two owned exercise keys per callback');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 100, 'A maximum-size group assignment erasure deletes no more than 100 link rows');
verify(wp_next_scheduled('oj_microbit_erase_user', [40]) !== false, 'A teacher with more exercises retains a bounded continuation');
Privacy::eraseUser(40);
verify(pendingCount('exercises', 'owner_id', 40) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 50, 'Continuation removes only the remaining owned links and keeps another teacher assignments');
$wpdb->fail = 'DELETE FROM `wp_oj_microbit_exercise_groups`'; Privacy::eraseUser(41);
verify(pendingCount('exercises', 'owner_id', 41) === 1 && isset($testOptions['oj_microbit_privacy_cleanup_error']), 'A failed link erasure preserves its parent and records a retryable storage error');
$wpdb->fail = null; Privacy::eraseUser(41);
verify(pendingCount('exercises', 'owner_id', 41) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0, 'Link erasure retry completes without orphaned group assignments');
$repo->saveExercise($groupExercise, 42, null, 0); $wpdb->queries = [];
$wpdb->fail = 'DELETE FROM `wp_oj_microbit_exercises`'; Privacy::eraseUser(42);
verify(pendingCount('exercises', 'owner_id', 42) === 1 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 50, 'A parent delete failure rolls back already-deleted group links atomically');
verify(in_array('ROLLBACK', $wpdb->queries, true) && count(array_filter($wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'))) === 1, 'Parent rows are locked before assignment deletion and failure releases the transaction');
$wpdb->fail = null; Privacy::eraseUser(42);
verify(pendingCount('exercises', 'owner_id', 42) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0, 'Atomic erasure can be retried after the parent failure');
$repo->saveExercise($groupExercise, 43, null, 0); $wpdb->queries = []; $wpdb->engine = 'MyISAM'; Privacy::eraseUser(43);
verify(pendingCount('exercises', 'owner_id', 43) === 1 && !in_array('START TRANSACTION', $wpdb->queries, true), 'Non-transactional storage is refused before deleting parent or group assignments');
$wpdb->engine = 'InnoDB'; Privacy::eraseUser(43);
verify(pendingCount('exercises', 'owner_id', 43) === 0 && (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0, 'Erasure resumes after transactional storage is repaired');
echo '[privacy-batches] ' . $testChecks . " checks passed (bounded SQL, durable deletion retries and maintenance; SQLite fixtures)\n";
