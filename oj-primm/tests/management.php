<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
require_once __DIR__ . '/Support/SqlWpdb.php';
require_once __DIR__ . '/Support/ReleaseFixture.php';
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

use OjPrimm\Application\ActivityManagement;
use OjPrimm\Application\PrimmService;
use OjPrimm\Storage\WpdbManagementStore;
use OjPrimm\Storage\WpdbRepository;
use OjPrimmTests\Platform;
use OjPrimmTests\SqlWpdb;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void { ++$checks; if (! $condition) { throw new RuntimeException($label); } };
$reject = static function (callable $work, string $label) use ($assert): void { try { $work(); } catch (DomainException) { $assert(true, $label); return; } $assert(false, $label); };
$db = SqlWpdb::create(); $records = new WpdbRepository($db, true); $store = new WpdbManagementStore($db, $records); $platform = new Platform();
$manager = new ActivityManagement($records, $store, $platform); $lesson = OjPrimmTests\lesson();
$original = $store->version(null, OjPrimmTests\activity($lesson), 'first-lesson');
$assert($original['content_version'] === 1 && $original['revision'] === 1, 'New lessons have explicit immutable content version and revision.');
$edit = $manager->edit($original['id'], ['expected_revision' => 1, 'patch' => ['title' => '새 질문', 'lesson' => $lesson], 'request_key' => 'edit-key-0001']);
$assert($edit['state'] === 'draft' && $edit['activity']['id'] !== $original['id'] && $edit['activity']['content_version'] === 2, 'Editing creates a new unpublished version.');
$assert($records->findActivity($original['id'])['lesson']['verification']['status'] === 'verified' && $records->findActivity($original['id'])['title'] === '합계 수업', 'Existing students keep the original immutable material.');
$assert($edit['lesson']['verification']['status'] === 'not_executed', 'Browser supplied verified certificate cannot survive editing.');
$again = $manager->edit($original['id'], ['expected_revision' => 1, 'patch' => ['title' => '새 질문', 'lesson' => $lesson], 'request_key' => 'edit-key-0001']);
$assert($again['activity']['id'] === $edit['activity']['id'], 'Repeated edit request reuses one content version.');
$reject(fn () => $manager->edit($original['id'], ['expected_revision' => 1, 'patch' => ['title' => '다른 내용', 'lesson' => $lesson], 'request_key' => 'edit-key-0001']), 'Reusing a request key for another edit is rejected.');
$reject(fn () => $manager->publish($edit['activity']['id'], ['expected_revision' => 1]), 'Unverified draft cannot publish.');
$reject(fn () => $manager->edit($original['id'], ['expected_revision' => 9, 'patch' => ['title' => 'wrong']]), 'Stale editor revisions cannot make a version.');
$db->query('START TRANSACTION');
$validated = $manager->acceptGenerated(['id' => 99, 'creator_user_id' => 10, 'context_type' => 'class', 'context_id' => 'class:7',
    'target_problem_id' => 100, 'target_hash' => 'problem-one', 'draft_activity_id' => $edit['activity']['id'], 'draft_revision' => 1,
    'profile' => ['auto_publish' => true]], $lesson, ['status' => 'needs_review', 'reasons' => ['수정 요구를 확인하세요.']]);
$db->query('COMMIT');
$assert($validated['id'] === $edit['activity']['id'] && $validated['revision'] === 2 && $validated['status'] === 'review', 'Validated draft is updated inside generation transaction and semantic review gate is preserved.');
$reject(fn () => $manager->publish($validated['id'], ['expected_revision' => 2]), 'Review reasons require explicit acknowledgement.');
$published = $manager->publish($validated['id'], ['expected_revision' => 2, 'acknowledge_review' => true]);
$assert($published['state'] === 'active' && $published['revision'] === 3, 'Reviewed verified version may publish.');
$clone = $manager->cloneActivity($original['id'], ['context_type' => 'class', 'context_id' => 'class:8', 'request_key' => 'reuse-key-0001']);
$assert($clone['state'] === 'draft' && $clone['activity']['context_id'] === 'class:8' && $clone['lesson'] === $lesson, 'Another class reuses verified content without AI regeneration.');
$assert($records->findAttempt($clone['activity']['id'], 20) === null, 'Reuse never copies learner attempts.');
$platform->actor = 20; $service = new PrimmService($records, $platform, $store);
$reject(fn () => $manager->manage($original['id']), 'Students cannot retrieve editable lesson or reference solution.');
$reject(fn () => $service->activity($clone['activity']['id']), 'Unpublished reused version is not publicly readable.');
$first = $service->activity($original['id']);
$assert(! isset($first['demo']['expected_output']) && ! str_contains(json_encode($first), 'quality'), 'Predict projection still hides answer and internal quality evidence.');
$view = $service->progress($original['id'], ['stage' => 'predict', 'expected_version' => 0, 'responses' => ['predict' => '3'], 'advance' => true]);
$review = $service->activity($original['id'], 'predict');
$assert($review['review_only'] && $review['display_stage'] === 'predict' && $review['attempt']['current_stage'] === 'run', 'Completed-stage review keeps actual progress immutable.');
$reject(fn () => $service->activity($original['id'], 'make'), 'Future stages cannot be reviewed early.');
$platform->actor = 10;
$withdrawn = $manager->withdraw($original['id'], ['expected_revision' => 1]);
$platform->actor = 20;
$assert($service->activity($original['id'])['review_only'] && $records->findAttempt($original['id'], 20)['first_prediction'] === '3', 'Withdrawal preserves existing learner evidence for read-only access.');
$reject(fn () => $service->progress($original['id'], ['stage' => 'run', 'expected_version' => 1, 'responses' => ['run' => '비교'], 'advance' => true]), 'Withdrawn activities cannot accept new progress.');
$platform->actor = 21;
$reject(fn () => $service->activity($original['id']), 'New learners cannot enter a withdrawn activity.');
$platform->actor = 10;
$privateSubmissionHash = hash('sha256', 'private-submitted-source');
$privateRecentHash = hash('sha256', 'private-recent-source');
for ($i = 20; $i < 270; ++$i) {
    $platform->learners[] = ['user_id' => $i, 'display_name' => $i === 20 ? '=FORMULA' : '학생 ' . $i, 'management_name' => '관리 ' . $i];
    if ($i < 230) { $records->saveAttempt($published['activity']['id'], $i, 0, ['status' => 'in_progress', 'current_stage' => 'modify',
        'responses' => [], 'is_accepted' => false, 'verified_at' => null] + ($i === 20 ? [
            'make_submission_watch' => ['active' => true, 'started_at' => '2026-10-25 12:00:00', 'baseline_id' => 99887766],
            'make_submission_linked' => ['id' => 99887767, 'source_hash' => $privateSubmissionHash],
            'make_submission_recent' => ['id' => 99887768, 'source_hash' => $privateRecentHash],
        ] : []), ['stage' => 'modify', 'type' => 'draft_saved', 'payload' => []], hash('sha256', 'attempt-' . $i)); }
}
$input = ['context_type' => 'class', 'context_id' => 'class:7', 'activity_id' => $published['activity']['id'], 'limit' => 100];
$total = []; $page = $manager->report($input);
$assert($page['total'] === 250 && $page['counts']['not_started'] === 40 && $page['counts']['in_progress'] === 210, 'Report includes all roster members and more than 200 attempts.');
$storedPrivateAttempt = $records->findAttempt($published['activity']['id'], 20);
$assert($storedPrivateAttempt['make_submission_watch']['baseline_id'] === 99887766
    && $storedPrivateAttempt['make_submission_linked']['source_hash'] === $privateSubmissionHash
    && $storedPrivateAttempt['make_submission_recent']['source_hash'] === $privateRecentHash
    && $storedPrivateAttempt['last_request_hash'] === hash('sha256', 'attempt-20'), 'Production storage retains the private cursor, source fingerprints and request hash before report projection.');
$reportedAttempt = array_column($page['items'], null, 'user_id')[20]['attempt'];
$reportedJson = json_encode($reportedAttempt, JSON_THROW_ON_ERROR);
$assert(array_intersect(array_keys($reportedAttempt), ['make_submission_watch', 'make_submission_linked', 'make_submission_recent', 'last_request_hash']) === []
    && !str_contains($reportedJson, '99887766') && !str_contains($reportedJson, $privateSubmissionHash)
    && !str_contains($reportedJson, $privateRecentHash) && !str_contains($reportedJson, hash('sha256', 'attempt-20'))
    && $reportedAttempt['current_stage'] === 'modify' && $reportedAttempt['status'] === 'in_progress', 'Teacher report retains progress while excluding private watch cursors, submitted-source fingerprints and request hashes.');
do { $page = $manager->report($input); $total = array_merge($total, $page['items']); $input['cursor'] = $page['next_cursor']; } while ($input['cursor'] !== '');
$assert(count($total) === 250 && count(array_unique(array_column($total, 'user_id'))) === 250, 'Stable cursor pagination neither drops nor duplicates learners.');
$search = $manager->report(array_replace($input, ['cursor' => '', 'search' => '학생 269']));
$assert($search['total'] === 1 && $search['items'][0]['user_id'] === 269, 'Teacher report searches the full roster, including nonstarters.');
$csv = $manager->exportCsv($input);
$assert(substr_count($csv, "\r\n") === 251 && str_contains($csv, "'=FORMULA"), 'Full CSV export covers all learners and neutralizes spreadsheet formulas.');
$badCursor = $manager->report(array_replace($input, ['cursor' => '']))['next_cursor'];
$platform->learners[] = ['user_id' => 999, 'display_name' => '새 학생', 'management_name' => '새 학생'];
$reject(fn () => $manager->report(array_replace($input, ['cursor' => $badCursor])), 'Membership changes invalidate the old report cursor.');
for ($i = 0; $i < 101; ++$i) { $store->version(null, OjPrimmTests\activity($lesson), 'catalog-' . $i); }
$seen = []; $cursor = '';
do { $catalog = $service->teacherActivities('class', 'class:7', $cursor, 25); $seen = array_merge($seen, array_column($catalog['activities'], 'id')); $cursor = $catalog['next_cursor']; } while ($cursor !== '');
$assert(count($seen) === 103 && count(array_unique($seen)) === 103, 'Teacher catalogue paginates beyond 100 activities.');
$manual = OjPrimmTests\activity($lesson); unset($manual['lesson']);
$manual = array_replace($manual, ['support_kind' => 'code_ordering', 'support_problem_id' => 101, 'support_hash' => 'old-support']);
$manual = $store->version(null, $manual, 'manual-source'); $platform->problemHashes[101] = 'new-support';
$reject(fn () => $manager->cloneActivity($manual['id'], ['context_type' => 'class', 'context_id' => 'class:8']), 'Changed support template blocks reuse before creating a broken activity.');
$reject(fn () => $manager->publish($manual['id'], ['expected_revision' => 1]), 'Changed support template cannot be published.');
$platform->hash = 'changed';
$reject(fn () => $manager->cloneActivity($published['activity']['id'], ['context_type' => 'class', 'context_id' => 'class:8']), 'Changed original problem blocks reuse of stale teaching content.');
echo 'PRIMM management and full-roster checks passed: ' . $checks . " (production repositories over SQLite)\n";
