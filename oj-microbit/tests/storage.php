<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\Privacy;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Run with PDO SQLite enabled, e.g. php -d extension=pdo_sqlite tests/storage.php');
}

require_once __DIR__ . '/lib/SqliteWpdb.php';

$wpdb = new SqliteWpdb(); $records = new WpdbRepository($wpdb); $data = fixtureExercise(); $project = fixtureProject();
$saved = $records->saveExercise($data, 10, null, 0);
verify($saved['revision'] === 1 && $saved['ownerId'] === 10, 'Exercise insert hydrates ownership and first revision');
$data['title'] = "한글 '따옴표'와 % 문자";
$saved = $records->saveExercise($data, 10, $saved['id'], 1);
verify($saved['revision'] === 2 && $saved['title'] === $data['title'], 'Prepared update preserves exact UTF-8 data and advances revision');
throws(fn () => $records->saveExercise($data, 10, 1, 1), DomainException::class, 'Exercise stale revision cannot overwrite');
throws(fn () => $records->saveExercise($data, 99, 1, 2), DomainException::class, 'Repository owner condition rejects unauthorized overwrite');
verify($records->exercise(1)['revision'] === 2, 'Failed writes do not advance revision');
verify(count($records->exercises(10, false)) === 1 && $records->exercises(99, false) === [], 'Teacher listing scoped by owner');
$draft = $data; $draft['status'] = 'draft';
$records->saveExercise($draft, 10, null, 0);
verify(count($records->exercises(null, true)) === 1, 'Learner listing excludes drafts');

$first = $records->saveProject(1, 20, $project, 0);
verify($first['revision'] === 1 && $records->project(1, 20)['project'] === $project, 'Project first insert stored and reloaded');
throws(fn () => $records->saveProject(1, 20, $project, 0), DomainException::class, 'Concurrent first save cannot replace existing project');
verify($records->saveProject(1, 20, $project, 1)['revision'] === 2, 'Project CAS increments revision');
throws(fn () => $records->saveProject(1, 20, $project, 1), DomainException::class, 'Stale project update rejected');
verify($records->saveProject(1, 21, $project, 0)['revision'] === 1 && $records->project(1, 99) === null, 'Projects isolated by learner ID');
$result = fixtureResult($project, $data); $result['_visibility'] = ['public' => true, 'secret' => false];
$records->saveAttempt(1, 2, 20, $project, $result); $records->saveAttempt(1, 2, 21, $project, $result);
verify(count($records->attempts(1, 20)) === 1 && count($records->attempts(1, null)) === 2, 'Attempt queries enforce learner scope or explicit teacher scope');
verify($records->attempt(1, $records->attempts(1, 20)[0]['id'], 20)['result']['_visibility']['secret'] === false, 'Original visibility persists in full detail JSON');
throws(fn () => $records->archiveExercise(1, 1), DomainException::class, 'Archive honors revision CAS');
$records->archiveExercise(1, 2);
verify($records->exercise(1) === null && count($records->exercises(10, false)) === 1, 'Archived exercise absent from detail and listings');
throws(fn () => $records->saveExercise($data, 10, 1, 3), DomainException::class, 'Archived record cannot be resurrected by stale save');

verify($records->withRunLease(20, static fn () => 'ran') === 'ran' && $wpdb->locks === $wpdb->releases, 'Run acquires and releases learner lock');
throws(fn () => $records->withRunLease(20, static function () { throw new RuntimeException('Execution failed'); }), RuntimeException::class, 'Runner failure propagated');
verify($wpdb->locks === $wpdb->releases, 'Run lock released even on failure');
$wpdb->denyLock = true;
throws(fn () => $records->withRunLease(20, static fn () => null), DomainException::class, 'Busy lock does not execute');
$wpdb->denyLock = false;
$wpdb->pdo->exec("UPDATE wp_oj_microbit_limits SET hits=30 WHERE scope_key='run:20'");
throws(fn () => $records->withRunLease(20, static fn () => null), DomainException::class, 'Thirty-per-minute quota enforced atomically');
verify($wpdb->locks === $wpdb->releases, 'Quota rejection still releases lock');
$wpdb->pdo->exec("UPDATE wp_oj_microbit_limits SET window_start=0 WHERE scope_key='run:20'");
verify($records->withRunLease(20, static fn () => true) === true, 'Expired quota window resets');
$wpdb->fail = 'SELECT hits';
throws(fn () => $records->withRunLease(20, static fn () => null), RuntimeException::class, 'Quota read failure fails closed');
$wpdb->fail = null;
verify($wpdb->locks === $wpdb->releases, 'Storage error still releases lease');

$wpdb->pdo->exec("UPDATE wp_oj_microbit_projects SET updated_at='2000-01-01 00:00:00' WHERE user_id=20");
$wpdb->pdo->exec("UPDATE wp_oj_microbit_attempts SET created_at='2000-01-01 00:00:00' WHERE user_id=20");
$records->purge(365);
verify($records->project(1, 20) === null && $records->attempts(1, 20) === [], 'Retention removes old learner code and attempts');
verify($records->project(1, 21) !== null && count($records->attempts(1, 21)) === 1 && $records->exercise(2) !== null, 'Retention preserves recent data and authored exercise');
$wpdb->fail = 'SELECT scope_key FROM `wp_oj_microbit_limits`';
throws(fn () => $records->purge(365), RuntimeException::class, 'Limit cleanup failure is reported');
$wpdb->fail = null;

// WordPress privacy callbacks execute the real prepared deletes against the fixture DB.
$records->saveProject(2, 10, $project, 0); $records->saveAttempt(2, 1, 10, $project, $result);
$wpdb->pdo->exec("INSERT INTO wp_oj_microbit_limits VALUES ('run:10',0,1)");
Privacy::eraseUser(10);
verify($records->exercise(2) === null && $records->project(2, 10) === null && $records->attempts(2, 10) === [], 'Privacy erases owned projects, attempts and authored exercises');
verify($records->project(1, 21) !== null && count($records->attempts(1, 21)) === 1, 'Teacher erasure preserves independently owned learner records');
verify((int) $wpdb->pdo->query("SELECT COUNT(*) FROM wp_oj_microbit_limits WHERE scope_key='run:10'")->fetchColumn() === 0, 'Privacy also clears personal quota key');
$testUsers['student@test.invalid'] = (object) ['ID' => 21];
function completePrivacyExport(string $email): array
{
    $data = [];
    for ($page = 1; $page < 1000; $page++) {
        $response = Privacy::exportData($email, $page);
        if ($response instanceof WP_Error) { throw new RuntimeException('Unexpected privacy export error'); }
        $data = array_merge($data, $response['data']);
        if ($response['done']) { return ['data' => $data, 'done' => true]; }
    }
    throw new RuntimeException('Privacy export did not complete');
}
$export = completePrivacyExport('student@test.invalid');
verify($export['done'] === true && count($export['data']) === 2, 'Privacy export includes learner project and attempt');
verify(!str_contains(json_encode($export), 'run:10'), 'Export scoped to requested user');
$exportedAttempt = json_decode($export['data'][1]['data'][1]['value'], true, 64, JSON_THROW_ON_ERROR);
$exportedResult = json_decode($exportedAttempt['result_json'], true, 64, JSON_THROW_ON_ERROR);
verify(!isset($exportedResult['_visibility'], $exportedResult['scenarios'][1]['assertions']) && $exportedResult['scenarios'][1]['name'] === '비공개 테스트', 'Personal-data export cannot bypass hidden-test redaction');
$busExercise = fixtureProtocolExercise(); $busSaved = $records->saveExercise($busExercise, 30, null, 0);
$busResult = fixtureResult($project, $busExercise); $busResult['_visibility'] = ['public' => true, 'secret' => false]; $busResult['traceScenarioId'] = 'secret';
$records->saveAttempt($busSaved['id'], 1, 21, $project, $busResult);
$protocolExport = completePrivacyExport('student@test.invalid');
$protocolRow = json_decode($protocolExport['data'][2]['data'][1]['value'], true, 64, JSON_THROW_ON_ERROR);
$protocolResult = json_decode($protocolRow['result_json'], true, 64, JSON_THROW_ON_ERROR);
verify(!isset($protocolResult['scenarios'][1]['i2cAssertions']) && $protocolResult['i2cTrace'] === [], 'Privacy export also protects hidden protocol assertions and bus telemetry');
$sequenceExercise = fixtureSequenceExercise(); $sequenceSaved = $records->saveExercise($sequenceExercise, 40, null, 0);
$sequenceResult = fixtureResult($sequenceExercise['starterProject'], $sequenceExercise); $sequenceResult['_visibility'] = ['public' => true, 'secret' => false];
$records->saveAttempt($sequenceSaved['id'], 1, 21, $sequenceExercise['starterProject'], $sequenceResult);
$sequenceExport = completePrivacyExport('student@test.invalid');
$sequenceRow = json_decode($sequenceExport['data'][3]['data'][1]['value'], true, 64, JSON_THROW_ON_ERROR);
$sequenceExportedResult = json_decode($sequenceRow['result_json'], true, 64, JSON_THROW_ON_ERROR);
verify(count($sequenceExportedResult['scenarios'][0]['outputSequences'][0]['steps']) === 2 && !isset($sequenceExportedResult['scenarios'][1]['outputSequences']), 'Personal-data export preserves public sequence feedback and redacts hidden sequence steps');
$wpdb->fail = 'SELECT record.*';
verify(Privacy::exportData('student@test.invalid') instanceof WP_Error, 'Export storage error returned as error');
$wpdb->fail = 'DELETE FROM';
verify(Privacy::eraseData('student@test.invalid') instanceof WP_Error, 'Erasure storage failure cannot claim success');
$wpdb->fail = null;
verify(Privacy::eraseData('student@test.invalid')['done'] === true && $records->project(1, 21) === null, 'Privacy retry actually erases learner data');
$wpdb->fail = 'SELECT id,owner_id';
throws(fn () => $records->exercises(null, false), RuntimeException::class, 'Listing query failure is not an empty successful list');
echo '[storage] ' . $testChecks . " checks passed (real SQLite SQL; MySQL named locks adapted, no live WordPress/MySQL)\n";
