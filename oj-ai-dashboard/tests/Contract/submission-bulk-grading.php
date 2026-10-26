<?php
/**
 * C30 preserves existing work while identifying failed official evaluations for retry.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-evaluation-enqueue-g4-imp-01-s04.php';

$guard_page          = $page;
$guard_page['items'] = array(
	array_merge( $items[0], array( 'has_evaluation' => true ) ),
	array_merge( $items[1], array( 'has_evaluation' => false ) ),
);
$guard_source        = new G4_S04_Source_Port( $guard_page );
$guard_enqueuer      = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $id_generator );
$guard_application   = new OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations(
	$guard_source,
	$guard_enqueuer,
	new G4_S04_Receipt_Port(),
	$id_generator,
	$canonical,
	static fn (): string => '2026-08-23 02:00:00'
);
$guard_result        = $guard_application->execute( g4_s04_command( $guard_page, $canonical, $hash( 'existing-guard' ) ) );
g4_s04_same( 'evaluation_exists', $guard_result->items()[0]['reason'], 'Existing evaluation must be skipped before C29 input preparation.' );
g4_s04_same( 'skipped', $guard_result->items()[0]['status'], 'Existing evaluation is not a newly created job.' );
g4_s04_same( 1, count( $guard_enqueuer->commands ), 'Only the new official submission may enter C29.' );
g4_s04_same( $submission_ids[1], $guard_enqueuer->commands[0]->submission_id(), 'New submission must still be registered.' );

$assert_item = new ReflectionMethod( $guard_application, 'assert_source_item' );
g4_s04_failure(
	static fn () => $assert_item->invoke( $guard_application, array_merge( $items[0], array( 'has_evaluation' => 'false' ) ) ),
	OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure::STORED_DATA_INVALID,
	'Invalid guard values must fail closed.'
);

$map_item  = new ReflectionMethod( $batch_source, 'item' );
$guard_row = array(
	'target_id'                   => $target_ids[0],
	'target_state'                => 'active',
	'submission_id'               => $submission_ids[0],
	'submission_lock_version'     => '3',
	'submission_state'            => 'submitted',
	'official_revision_public_id' => $revision_ids[0],
	'official_revision_hash'      => $hash( 'revision-0' ),
	'submitted_at_utc'            => '2026-08-23 00:10:00',
	'has_evaluation'              => '1',
);
g4_s04_same( true, $map_item->invoke( $batch_source, $guard_row )['has_evaluation'], 'SQL existence guard must reach the application.' );
$guard_row['has_evaluation'] = '0';
g4_s04_same( false, $map_item->invoke( $batch_source, $guard_row )['has_evaluation'], 'Unevaluated official revision must remain eligible.' );
unset( $guard_row['has_evaluation'] );
g4_s04_failure(
	static fn () => $map_item->invoke( $batch_source, $guard_row ),
	OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure::STORED_DATA_INVALID,
	'Missing database guard must not silently regrade.'
);
g4_s04_assert( str_contains( $source_database->last_sql, 'e.submission_revision_public_id = s.official_revision_public_id' ) && str_contains( $source_database->last_sql, 'e.submission_revision_hash = r.content_hash' ), 'Existence must use the current official revision and its hash, not the learner name or old revision.' );
fwrite( STDOUT, "PASS bulk grading: existing official evaluation skip, new official submission, strict source guard, revision-scoped SQL.\n" );

// Run the actual production SELECT to cover joins and old-revision exclusions.
$source_database->assignment['assignment_id'] = $assignment_id;
$batch_source->page( $assignment_id, 'class', $context_id, $context_revision, $cutoff, '', 100 );
$pdo = new PDO( 'sqlite::memory:' );
$pdo->exec( 'CREATE TABLE oj_ai_assignment_targets (public_id TEXT, state TEXT, assignment_id INTEGER, learner_user_id INTEGER)' );
$pdo->exec( 'CREATE TABLE oj_ai_submissions (id INTEGER, public_id TEXT, lock_version INTEGER, state TEXT, official_revision_public_id TEXT, submitted_at_utc TEXT, assignment_id INTEGER, learner_user_id INTEGER)' );
$pdo->exec( 'CREATE TABLE oj_ai_submission_revisions (submission_id INTEGER, public_id TEXT, content_hash TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_submission_evaluations (submission_revision_public_id TEXT, submission_revision_hash TEXT, state TEXT, id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT)' );
$insert_target     = $pdo->prepare( 'INSERT INTO oj_ai_assignment_targets VALUES (?, ?, 71, ?)' );
$insert_submission = $pdo->prepare( 'INSERT INTO oj_ai_submissions VALUES (?, ?, 3, ?, ?, ?, 71, ?)' );
$insert_revision   = $pdo->prepare( 'INSERT INTO oj_ai_submission_revisions VALUES (?, ?, ?)' );
$insert_evaluation = $pdo->prepare( 'INSERT INTO oj_ai_submission_evaluations (submission_revision_public_id, submission_revision_hash, state, public_id) VALUES (?, ?, ?, ?)' );
foreach ( array( 'queued', 'running', 'failed', 'review_required', 'reviewed', 'cancelled', 'old_revision', 'wrong_hash' ) as $index => $evaluation_state ) {
	$insert_target->execute( array( $target_ids[ $index ], 'active', $index ) );
	$insert_submission->execute( array( $index, $submission_ids[ $index ], 'submitted', $revision_ids[ $index ], $cutoff, $index ) );
	$insert_revision->execute( array( $index, $revision_ids[ $index ], $hash( 'revision-' . $index ) ) );
	$insert_evaluation->execute( array( 'old_revision' === $evaluation_state ? $revision_ids[8] : $revision_ids[ $index ], 'wrong_hash' === $evaluation_state ? $hash( 'other' ) : $hash( 'revision-' . $index ), $evaluation_state, $target_ids[ $index ] ) );
}
$observed = array();
foreach ( $pdo->query( $source_database->last_sql )->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
	$observed[ $row['submission_id'] ] = (int) $row['has_evaluation'];
	g4_s04_same( $row['submission_id'] === $submission_ids[2] ? $target_ids[2] : null, $row['failed_evaluation_id'], 'Only the failed official source is a retry candidate.' );
	$mapped = $map_item->invoke( $batch_source, $row );
	g4_s04_same( $row['failed_evaluation_id'], $mapped['failed_evaluation_id'], 'Failed identity must reach C30 without changing the existing guard.' );
}
foreach ( range( 0, 7 ) as $index ) {
	g4_s04_same( $index < 6 ? 1 : 0, $observed[ $submission_ids[ $index ] ] ?? null, 'Production SQL must skip every existing state, but not a different official revision/hash.' );
}
$insert_evaluation->execute( array( $revision_ids[2], $hash( 'revision-2' ), 'failed', $target_ids[8] ) );
$observed_rows = array_column( $pdo->query( $source_database->last_sql )->fetchAll( PDO::FETCH_ASSOC ), null, 'submission_id' );
g4_s04_same( $target_ids[8], $observed_rows[ $submission_ids[2] ]['failed_evaluation_id'], 'Several failed evaluations select one latest retry, not every historical failure.' );
$insert_evaluation->execute( array( $revision_ids[2], $hash( 'revision-2' ), 'reviewed', $target_ids[7] ) );
$observed_rows = array_column( $pdo->query( $source_database->last_sql )->fetchAll( PDO::FETCH_ASSOC ), null, 'submission_id' );
g4_s04_same( null, $observed_rows[ $submission_ids[2] ]['failed_evaluation_id'], 'A completed evaluation of the same source prevents retrying an older failed evaluation.' );
fwrite( STDOUT, "PASS bulk grading SQL: failed-source retry identity, latest failure selection, protected states, revision/hash isolation.\n" );
