<?php
/**
 * G4-IMP-06-S04 C43 correction transaction and CAS contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-release-storage-g4-imp-06-s01.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 correction storage fixture.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $fingerprint Fingerprint.
 * @return array{db:G4_Result_Release_Database,repository:WordPress_Submission_Result_Release_Repository,application:Release_Submission_Result,snapshot:array<string,mixed>,first:OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Release_Result}
 */
function g4_correction_storage_fixture( array $source, array $snapshot, Canonical_Json $canonical, string $fingerprint ): array {
	$db                              = new G4_Result_Release_Database( g4_release_storage_row( $source, $snapshot, $canonical ) );
	$repository                      = new WordPress_Submission_Result_Release_Repository( $canonical, $db );
	$application                     = new Release_Submission_Result( new G4_Feedback_Review_Source( $source ), $repository, new Submission_Result_Projection_Builder( new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 23:40:00' );
	$first                           = $application->execute( g4_release_command( $source, $snapshot, $canonical, 'command-g4-correction-storage-first', $fingerprint ) );
	$next                            = $snapshot;
	$next['submission_lock_version'] = $db->source_row['submission_lock_version'];
	$next['current_release_pk']      = $db->source_row['current_release_pk'];
	$next['current_release_id']      = $db->source_row['current_release_id'];
	$next['current_release_hash']    = $db->source_row['current_release_hash'];
	$next['current_release_no']      = (int) $db->source_row['current_release_no'];
	$next['current_release_state']   = $db->source_row['current_release_state'];
	$next['current_projection_hash'] = $db->source_row['current_projection_hash'];
	$next['release_count']           = (int) $db->source_row['release_count'];
	return array(
		'db'          => $db,
		'repository'  => $repository,
		'application' => $application,
		'snapshot'    => $next,
		'first'       => $first,
	);
}

$correction_storage_canonical  = new Canonical_Json();
$correction_storage_base       = g4_release_fixture( $correction_storage_canonical );
$correction_storage            = g4_correction_storage_fixture( $correction_storage_base['source'], $correction_storage_base['snapshot'], $correction_storage_canonical, str_repeat( 'a', 64 ) );
$correction_storage_db         = $correction_storage['db'];
$correction_storage_db->events = array();
$correction_storage_command    = g4_release_command( $correction_storage_base['source'], $correction_storage['snapshot'], $correction_storage_canonical, 'command-g4-correction-storage', str_repeat( 'b', 64 ), 'total_and_criteria', 'shown', 'correction', 'data_correction', 'The official result source was corrected.' );
$correction_storage_result     = $correction_storage['application']->execute( $correction_storage_command );

g4_release_same( array( 'START', 'INSERT:oj_ai_submission_release_revisions', 'UPDATE_POINTER', 'INSERT:oj_ai_audit_events', 'INSERT:oj_ai_outbox_events', 'COMMIT' ), $correction_storage_db->events, 'C43 correction transaction order drifted.' );
g4_release_same( 'corrected', $correction_storage_result->state(), 'Stored correction state drifted.' );
g4_release_same( 2, $correction_storage_result->release_no(), 'Stored correction release number drifted.' );
g4_release_same( 2, count( $correction_storage_db->releases ), 'Correction must preserve the first release and append one row.' );
$stored_correction = $correction_storage_db->release;
if ( null === $stored_correction ) {
	throw new RuntimeException( 'Stored C43 correction is missing.' );
}
$stored_first = $correction_storage_db->releases[ $correction_storage['first']->release_id() ];
g4_release_same( $stored_first['id'], $stored_correction['previous_release_id'], 'Stored correction must link the previous release primary key.' );
g4_release_same( 'data_correction', $stored_correction['release_reason_code'], 'Stored correction reason code drifted.' );
g4_release_same( 'The official result source was corrected.', $stored_correction['release_reason_note'], 'Stored correction reason note drifted.' );
g4_release_same( $correction_storage_result->release_id(), $correction_storage_db->source_row['current_release_id'], 'Correction pointer did not advance.' );
g4_release_same( $correction_storage_result->release_hash(), $correction_storage_db->source_row['current_release_hash'], 'Correction pointer hash did not advance.' );

$correction_audit = $correction_storage_db->audit[ count( $correction_storage_db->audit ) - 1 ];
g4_release_same( 'submission_result.corrected', $correction_audit['action_key'], 'Correction audit event drifted.' );
g4_release_same( $correction_storage['first']->release_hash(), $correction_audit['before_digest'], 'Correction audit must pin the previous release hash.' );
$correction_metadata = json_decode( $correction_audit['metadata_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( 'data_correction', $correction_metadata['reason_code'], 'Correction audit reason code drifted.' );
g4_release_assert( ! array_key_exists( 'reason_note', $correction_metadata ) && ! str_contains( $correction_audit['metadata_json'], 'The official result source was corrected.' ), 'Correction audit must not duplicate the internal reason note.' );

$correction_outbox = $correction_storage_db->outbox;
if ( null === $correction_outbox ) {
	throw new RuntimeException( 'C43 correction outbox is missing.' );
}
g4_release_same( 'submission_result.corrected', $correction_outbox['event_type'], 'Correction outbox event drifted.' );
$correction_payload = json_decode( $correction_outbox['payload_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' ), array_keys( $correction_payload ), 'Correction outbox allowlist drifted.' );
foreach ( array( 'reason_code', 'reason_note', 'visible_score', 'feedback', 'projection' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $correction_outbox['payload_json'], $forbidden ), 'Correction outbox leaked private content: ' . $forbidden );
}

$correction_storage_event_count = count( $correction_storage_db->events );
$correction_storage_replay      = $correction_storage['application']->execute( $correction_storage_command );
g4_release_same( true, $correction_storage_replay->replayed(), 'Stored correction must replay durably.' );
g4_release_same( $correction_storage_event_count, count( $correction_storage_db->events ), 'Stored correction replay must open no transaction.' );

$correction_race_base                = g4_release_fixture( $correction_storage_canonical );
$correction_race                     = g4_correction_storage_fixture( $correction_race_base['source'], $correction_race_base['snapshot'], $correction_storage_canonical, str_repeat( 'c', 64 ) );
$correction_race['db']->events       = array();
$correction_race['db']->fail_pointer = true;
$race_first_id                       = $correction_race['first']->release_id();
$race_first_hash                     = $correction_race['first']->release_hash();
g4_release_failure( static fn () => $correction_race['application']->execute( g4_release_command( $correction_race_base['source'], $correction_race['snapshot'], $correction_storage_canonical, 'command-g4-correction-race', str_repeat( 'd', 64 ), 'total', 'hidden', 'correction', 'publication_error', null ) ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'Correction pointer race must return release_source_stale.' );
g4_release_assert( in_array( 'ROLLBACK', $correction_race['db']->events, true ) && ! in_array( 'COMMIT', $correction_race['db']->events, true ), 'Correction pointer race must roll back.' );
g4_release_same( 1, count( $correction_race['db']->releases ), 'Rolled-back correction must preserve only the first release.' );
g4_release_same( $race_first_id, $correction_race['db']->source_row['current_release_id'], 'Rollback must restore the previous current release ID.' );
g4_release_same( $race_first_hash, $correction_race['db']->source_row['current_release_hash'], 'Rollback must restore the previous current release hash.' );

$correction_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Release_Repository.php' );
g4_release_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_release_revisions\b/i', $correction_repository_source ), 'Correction release history must remain append-only.' );
g4_release_assert( ! str_contains( $correction_repository_source, '$wpdb->prefix' ) && ! str_contains( $correction_repository_source, 'wp_oj_ai_' ), 'Correction storage must use literal oj_ai_* table names.' );

fwrite( STDOUT, "G4-IMP-06-S04 C43 storage passed: corrected append, previous chain, pointer CAS, audit, body-free outbox, durable replay, and rollback are atomic.\n" );
