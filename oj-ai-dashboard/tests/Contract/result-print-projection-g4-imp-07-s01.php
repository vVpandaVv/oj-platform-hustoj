<?php
/**
 * G4-IMP-07-S01 result_print.v1 public projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Result_Pdf_Failure;
use OJAI\Dashboard\Reporting\ResultPdf\Infrastructure\Q33_Own_Result_Pdf_Source_Query;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Own_Result_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Own_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

ob_start();
require __DIR__ . '/submission-result-query-g4-imp-06-s02.php';
ob_end_clean();

/**
 * G4 result print assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_print_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 result print same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_print_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 result print failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_print_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Result_Pdf_Failure $failure ) {
		g4_result_print_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 result print invalid.
 *
 * @param callable $callback Callback.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_print_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-07-S01 result_print.v1 public projection contract.
 */
final class G4_Result_Print_Source implements Own_Result_Pdf_Source_Query_Port_Interface {
	/**
	 * Initialize g4 result print source dependencies and state.
	 *
	 * @param array<string,mixed>|null $source Source.
	 */
	public function __construct( public ?array $source ) {
	}
	/**
	 * Find current for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @return array|null
	 */
	public function find_current_for_actor( int $actor_user_id, string $release_id ): ?array {
		unset( $actor_user_id, $release_id );
		return $this->source;
	}
}

$result_print_canonical = new Canonical_Json();
$result_print_adapter   = new Q33_Own_Result_Pdf_Source_Query( $q33_read, $result_print_canonical );
$result_print_builder   = new Build_Result_Print_Projection( $result_print_adapter, $result_print_canonical );
$result_print           = $result_print_builder->build( 42, $q33_released->release_id(), $q33_released->release_hash() );

g4_result_print_same( 'submission_release', $result_print['source']['type'], 'Result print source type drifted.' );
g4_result_print_same( $q33_released->release_id(), $result_print['source']['id'], 'Result print must pin the exact current release ID.' );
g4_result_print_same( $q33_released->release_hash(), $result_print['source']['hash'], 'Result print must pin the exact current release hash.' );
g4_result_print_same( 'released', $result_print['source']['state'], 'Released source state drifted.' );
g4_result_print_same( 'result_print.v1', $result_print['print']['artifact_profile_version'], 'Result artifact profile drifted.' );
g4_result_print_same( 'oj_ai.result_print', $result_print['print']['schema_id'], 'Result print schema drifted.' );
g4_result_print_same( '1.0.0', $result_print['print']['schema_version'], 'Result print schema version drifted.' );
g4_result_print_same( 'student_result', $result_print['print']['profile'], 'Result print profile drifted.' );
g4_result_print_same( 'worksheet_a4@1', $result_print['print']['page_profile'], 'Result page profile drifted.' );
g4_result_print_same( 'ko-KR', $result_print['print']['locale'], 'Result print locale drifted.' );

$result_print_projection = $result_print['print']['projection'];
$result_print_envelope   = $result_print_projection['result_print_projection'];
g4_result_print_same( $q33_released->release_hash(), $result_print_envelope['source_hash'], 'Result envelope source hash drifted.' );
g4_result_print_same(
	$result_print_canonical->encode( $q33_result['projection'] ),
	$result_print_canonical->encode( $result_print_envelope['payload']['result'] ),
	'Result PDF must carry the exact Q33 public projection.'
);
g4_result_print_same(
	hash( 'sha256', $result_print_canonical->encode( $result_print_envelope['payload'] ) ),
	$result_print_envelope['payload_hash'],
	'Result print payload hash drifted.'
);
g4_result_print_same(
	hash( 'sha256', $result_print_canonical->encode( $result_print_envelope ) ),
	$result_print['print']['profile_hash'],
	'Result print profile hash drifted.'
);
g4_result_print_same(
	hash( 'sha256', $result_print_canonical->encode( $result_print_projection ) ),
	$result_print['print']['projection_hash'],
	'Result print projection hash drifted.'
);
g4_result_print_same(
	$result_print_canonical->encode( $result_print ),
	$result_print_canonical->encode( $result_print_builder->build( 42, $q33_released->release_id(), $q33_released->release_hash() ) ),
	'Result print projection must be deterministic.'
);

$result_print_json = $result_print_canonical->encode( $result_print_projection );
foreach ( array( 'answer', 'answer_key', 'binding_key', 'confidence', 'model_key', 'provider_key', 'reason_code', 'reason_note', 'rubric', 'safety_flags', 'student_note', 'teacher_memo' ) as $forbidden ) {
	g4_result_print_assert( ! str_contains( $result_print_json, '"' . $forbidden . '"' ), 'Result print leaked a forbidden field: ' . $forbidden );
}

g4_result_print_failure(
	static fn () => $result_print_builder->build( 42, $q33_released->release_id(), hash( 'sha256', 'old-release-hash' ) ),
	Result_Pdf_Failure::SOURCE_STALE,
	'A stale expected release hash must fail.'
);
g4_result_print_failure(
	static fn () => $result_print_builder->build( 99, $q33_released->release_id(), $q33_released->release_hash() ),
	Result_Pdf_Failure::NOT_FOUND,
	'Another learner must not build a result PDF projection.'
);

$q33_database->pointer_current = false;
g4_result_print_failure(
	static fn () => $result_print_builder->build( 42, $q33_released->release_id(), $q33_released->release_hash() ),
	Result_Pdf_Failure::NOT_FOUND,
	'A historical release must not build a result PDF projection.'
);
$q33_database->pointer_current = true;

$q33_contexts->revision = 'context-race';
g4_result_print_failure(
	static fn () => $result_print_builder->build( 42, $q33_released->release_id(), $q33_released->release_hash() ),
	Result_Pdf_Failure::NOT_FOUND,
	'A changed learner Context must invalidate the result PDF source.'
);
$q33_contexts->revision = $q33_fixture['source']['context_revision'];

$stale_result_print = new Build_Result_Print_Projection(
	new Q33_Own_Result_Pdf_Source_Query(
		new Read_Own_Submission_Result( $q33_stale_query, $q33_contexts ),
		$result_print_canonical
	),
	$result_print_canonical
);
g4_result_print_failure(
	static fn () => $stale_result_print->build( 42, $q33_stale_database->record['release_public_id'], $q33_stale_database->record['content_hash'] ),
	Result_Pdf_Failure::SOURCE_STALE,
	'A stale current release must expose no result print projection.'
);

$withdrawn_result_print = new Build_Result_Print_Projection(
	new Q33_Own_Result_Pdf_Source_Query(
		new Read_Own_Submission_Result( $q33_withdrawn_query, $q33_contexts ),
		$result_print_canonical
	),
	$result_print_canonical
);
g4_result_print_failure(
	static fn () => $withdrawn_result_print->build( 42, $q33_withdrawn_database->record['release_public_id'], $q33_withdrawn_database->record['content_hash'] ),
	Result_Pdf_Failure::SOURCE_STALE,
	'A withdrawn current release must expose no result print projection.'
);

$corrupt_result_print = new Build_Result_Print_Projection(
	new Q33_Own_Result_Pdf_Source_Query(
		new Read_Own_Submission_Result( $q33_corrupt_query, $q33_contexts ),
		$result_print_canonical
	),
	$result_print_canonical
);
g4_result_print_failure(
	static fn () => $corrupt_result_print->build( 42, $q33_corrupt_database->record['release_public_id'], $q33_corrupt_database->record['content_hash'] ),
	Result_Pdf_Failure::SOURCE_INVALID,
	'A hash-consistent Q33 projection outside the allowlist must fail closed.'
);

$result_print_source = $result_print_adapter->find_current_for_actor( 42, $q33_released->release_id() );
if ( null === $result_print_source ) {
	throw new RuntimeException( 'Result print source fixture is missing.' );
}
$corrected_source                          = $result_print_source;
$corrected_source['release_hash']          = hash( 'sha256', 'corrected-current-release' );
$corrected_source['release_state']         = 'corrected';
$corrected_source['released_at_utc']       = '2026-08-25 00:20:00';
$corrected_source['projection']['release'] = array(
	'released_at_utc' => '2026-08-25 00:20:00',
	'state'           => 'corrected',
);
$corrected_source['projection_hash']       = hash( 'sha256', $result_print_canonical->encode( $corrected_source['projection'] ) );
$corrected_builder                         = new Build_Result_Print_Projection( new G4_Result_Print_Source( $corrected_source ), $result_print_canonical );
$corrected_result                          = $corrected_builder->build( 42, $corrected_source['release_id'], $corrected_source['release_hash'], 'en-US' );
g4_result_print_same( 'corrected', $corrected_result['source']['state'], 'A corrected current release must remain printable.' );
g4_result_print_same( 'corrected', $corrected_result['print']['projection']['result_print_projection']['payload']['result']['release']['state'], 'Corrected projection state drifted.' );
g4_result_print_same( 'en-US', $corrected_result['print']['locale'], 'Pinned result print locale drifted.' );

$private_source                               = $corrected_source;
$private_source['projection']['student_note'] = array( 'text' => 'private' );
$private_source['projection_hash']            = hash( 'sha256', $result_print_canonical->encode( $private_source['projection'] ) );
$private_builder                              = new Build_Result_Print_Projection( new G4_Result_Print_Source( $private_source ), $result_print_canonical );
g4_result_print_failure(
	static fn () => $private_builder->build( 42, $private_source['release_id'], $private_source['release_hash'] ),
	Result_Pdf_Failure::SOURCE_INVALID,
	'A private field must fail even when its projection hash is recomputed.'
);

$hash_mismatch_source                    = $corrected_source;
$hash_mismatch_source['projection_hash'] = hash( 'sha256', 'wrong-public-projection' );
$hash_mismatch_builder                   = new Build_Result_Print_Projection( new G4_Result_Print_Source( $hash_mismatch_source ), $result_print_canonical );
g4_result_print_failure(
	static fn () => $hash_mismatch_builder->build( 42, $hash_mismatch_source['release_id'], $hash_mismatch_source['release_hash'] ),
	Result_Pdf_Failure::SOURCE_INVALID,
	'A public projection hash mismatch must fail.'
);

$total_result_print = new Build_Result_Print_Projection(
	new Q33_Own_Result_Pdf_Source_Query(
		new Read_Own_Submission_Result( new WordPress_Submission_Result_Query( $result_print_canonical, $q33_total_database ), $q33_total_contexts ),
		$result_print_canonical
	),
	$result_print_canonical
);
$total_print        = $total_result_print->build( 42, $q33_total_release->release_id(), $q33_total_release->release_hash() );
g4_result_print_same( 'total', $total_print['print']['projection']['result_print_projection']['payload']['result']['score']['visibility'], 'Total-only public visibility must be preserved.' );
g4_result_print_same( array(), $total_print['print']['projection']['result_print_projection']['payload']['result']['score']['criteria'], 'Hidden criterion scores must remain absent.' );

g4_result_print_invalid(
	static fn () => $result_print_builder->build( 42, $q33_released->release_id(), $q33_released->release_hash(), 'ko_kr' ),
	'An invalid locale must be rejected before source access.'
);

$runtime_files  = array(
	$addon_root . '/src/Reporting/ResultPdf/Port/Own_Result_Pdf_Source_Query_Port_Interface.php',
	$addon_root . '/src/Reporting/ResultPdf/Infrastructure/Q33_Own_Result_Pdf_Source_Query.php',
	$addon_root . '/src/Reporting/ResultPdf/Application/Build_Result_Print_Projection.php',
	$addon_root . '/src/Reporting/ResultPdf/Application/Result_Pdf_Failure.php',
);
$runtime_source = '';
foreach ( $runtime_files as $runtime_file ) {
	$runtime_source .= (string) file_get_contents( $runtime_file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_result_print_assert( ! str_contains( $runtime_source, $forbidden ), 'Result print boundary contains a forbidden dependency: ' . $forbidden );
}
g4_result_print_assert( 1 !== preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $runtime_source ), 'S01 result print boundary must remain mutation-free.' );
g4_result_print_assert( str_contains( $runtime_source, 'Read_Own_Submission_Result' ) && ! str_contains( (string) file_get_contents( $runtime_files[1] ), 'SELECT ' ), 'The result PDF source adapter must reuse Q33 instead of creating a parallel SQL path.' );

fwrite( STDOUT, "G4-IMP-07-S01 passed: Q33 current-release reuse, exact release ID/hash, result_print.v1 canonical public projection, corrected visibility, stale/withdrawn suppression, and private-field rejection are frozen.\n" );
