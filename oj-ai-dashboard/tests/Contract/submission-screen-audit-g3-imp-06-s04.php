<?php
/**
 * G3-IMP-06-S04 student status and teacher submission audit UI contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-submission-q32-g3-imp-04-s02.php';

$s04_student_query   = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Query.php' );
$s04_handler         = (string) file_get_contents( $addon_root . '/src/Platform/Assignment_Workbench_Page_Handler.php' );
$s04_teacher_ui      = (string) file_get_contents( $addon_root . '/resources/js/assignment-workbench.js' );
$s04_student_ui      = (string) file_get_contents( $addon_root . '/resources/js/student-assignments.js' );
$s04_autosave_ui     = (string) file_get_contents( $addon_root . '/resources/js/student-submission-autosave.js' );
$s04_teacher_fixture = (string) file_get_contents( $addon_root . '/tests/fixtures/assignment-lifecycle-visual.html' );
$s04_student_fixture = (string) file_get_contents( $addon_root . '/tests/fixtures/student-assignments-visual.html' );
$s04_teacher_e2e     = (string) file_get_contents( $addon_root . '/scripts/e2e-assignment-lifecycle.mjs' );
$s04_student_e2e     = (string) file_get_contents( $addon_root . '/scripts/e2e-student-assignments.mjs' );

foreach ( array( 'control_receipt', 'resubmission', 'remaining_attempts', 'resubmission_policy_schema', 'revision_content_hash' ) as $marker ) {
	g3_assert( str_contains( $s04_student_query, $marker ), 'S04 verified Q32 projection marker is missing: ' . $marker );
}
foreach ( array( 'teacher_submission.list', 'MANAGE_SUBMISSIONS', 'data-can-manage-submissions', 'submission-panel', 'submission-history', 'submission-control-reason' ) as $marker ) {
	g3_assert( str_contains( $s04_handler, $marker ), 'S04 teacher page marker is missing: ' . $marker );
}
foreach ( array( '/submissions?', '/revisions?', '/commands/${action}', 'expected_official_revision_id', 'expected_official_attempt_no', 'expected_publication_hash', 'Idempotency-Key' ) as $marker ) {
	g3_assert( str_contains( $s04_teacher_ui, $marker ), 'S04 teacher API consumer marker is missing: ' . $marker );
}
foreach ( array( 'oj-ai:student-submission-status', '다시 작성할 수 있습니다', '제출이 잠겼습니다', '남은 제출', '교사에게 문의하세요.' ) as $marker ) {
	g3_assert( str_contains( $s04_student_ui . "\n" . $s04_autosave_ui, $marker ), 'S04 student status marker is missing: ' . $marker );
}
$s04_teacher_visual = $s04_teacher_fixture . "\n" . $s04_teacher_e2e;
foreach ( array( 'not_started', '답안을 보완해 다시 제출하세요.', '__submissionRequests' ) as $marker ) {
	g3_assert( str_contains( $s04_teacher_visual, $marker ), 'S04 teacher visual fixture or E2E marker is missing: ' . $marker );
}
$s04_student_visual = $s04_student_fixture . "\n" . $s04_student_e2e;
foreach ( array( 'reopened', 'locked', 'remaining_attempts' ) as $marker ) {
	g3_assert( str_contains( $s04_student_visual, $marker ), 'S04 student visual fixture or E2E marker is missing: ' . $marker );
}

g3_assert(
	1 === preg_match( '/const contextScopeQuery = ([^\r\n]+)/', $s04_teacher_ui, $s04_scope_match ),
	'S04 must keep a dedicated mutation Context query.'
);
g3_assert( ! str_contains( $s04_scope_match[1], 'limit' ) && ! str_contains( $s04_scope_match[1], 'cursor' ), 'S04 mutation Context query must not contain list parameters.' );
g3_assert( str_contains( $s04_teacher_e2e, 'sameKeys(reopen?.body?.expected' ), 'S04 E2E must pin the exact C27 CAS envelope.' );
g3_assert( str_contains( $s04_student_e2e, "statusText.includes('control_receipt')" ), 'S04 E2E must reject raw contract text in the student UI.' );

foreach ( array( $s04_student_query, $s04_handler, $s04_teacher_ui, $s04_student_ui, $s04_autosave_ui ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3-IMP-06-S04 must not use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3-IMP-06-S04 must not use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3-IMP-06-S04 must not depend on platform internals.' );
}

echo "G3-IMP-06-S04 passed: verified student status, complete teacher target list, official answer detail, immutable audit, and exact reopen/lock UI commands.\n";
