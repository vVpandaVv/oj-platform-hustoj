<?php
/**
 * G4-IMP-05-S02 student-note Q18/Q19 query contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Canonical_Json.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Application/Read_Worksheets.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Infrastructure/Signed_Cursor_Codec.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Submission_Evaluation_Failure.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Student_Note_Input_Builder.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Student_Note_Normalized_Result.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Student_Note_Output_Normalizer.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Student_Note_Query_Projector.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Student_Note_Query_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Read_Student_Notes.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Query.php';
require_once ABSPATH . 'src/Security/Policy/Policy_Value.php';
require_once ABSPATH . 'src/Security/Policy/Context_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Object_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Context_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Object_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Port/Object_Authorization_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/Student_Note_Object_Authorization.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Student_Notes;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Student_Note_Object_Authorization;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

/**
 * G4 note q assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_q_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 note q same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_q_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 note q id.
 *
 * @return string
 */
function g4_note_q_id(): string {
	static $counter = 93000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_note_q_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 note q fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{root:array<string,mixed>,audit:array<string,mixed>}
 */
function g4_note_q_fixture( Canonical_Json $canonical ): array {
	$note_id          = g4_note_q_id();
	$revision_id      = g4_note_q_id();
	$evaluation_id    = g4_note_q_id();
	$review_id        = g4_note_q_id();
	$run_id           = g4_note_q_id();
	$submission_id    = g4_note_q_id();
	$assignment_id    = g4_note_q_id();
	$publication_id   = g4_note_q_id();
	$review_hash      = str_repeat( 'c', 64 );
	$source_hash      = str_repeat( 'd', 64 );
	$publication_hash = str_repeat( 'e', 64 );
	$evidence_key     = str_repeat( 'a', 64 );
	$display_json     = $canonical->encode( array( 'display_name' => 'Student A' ) );
	$profile          = array(
		'evidence'            => array(
			array(
				'evidence_key' => $evidence_key,
				'observation'  => 'The submitted work shows a complete sequence of observable steps.',
			),
		),
		'note_ref'            => 'SN_' . str_repeat( 'A', 32 ),
		'safety_flags'        => array(),
		'schema_id'           => Student_Note_Input_Builder::RESULT_SCHEMA,
		'schema_version'      => Student_Note_Input_Builder::RESULT_VERSION,
		'source_hash'         => $review_hash,
		'strengths'           => array(
			array(
				'evidence_keys' => array( $evidence_key ),
				'text'          => 'The explanation links each step to observable work.',
			),
		),
		'summary'             => array(
			'evidence_keys' => array( $evidence_key ),
			'text'          => 'The response explains the selected approach with clear supporting details.',
		),
		'support_suggestions' => array(
			array(
				'evidence_keys' => array( $evidence_key ),
				'text'          => 'Add one concrete example to make the explanation easier to verify.',
			),
		),
	);
	$profile_json     = $canonical->encode( $profile );
	$root             = array(
		'note_pk'                             => '21',
		'note_revision_id'                    => $revision_id,
		'note_content_hash'                   => str_repeat( '1', 64 ),
		'note_created_at_utc'                 => '2026-08-24 10:01:00',
		'note_root_id'                        => $note_id,
		'note_evaluation_pk'                  => '11',
		'note_source_run_id'                  => $run_id,
		'note_revision_no'                    => '1',
		'note_state'                          => 'ai_draft',
		'include_in_reports'                  => '0',
		'note_schema'                         => Student_Note_Input_Builder::RESULT_SCHEMA,
		'note_version'                        => '1',
		'note_json'                           => $profile_json,
		'note_hash'                           => hash( 'sha256', $profile_json ),
		'approved_by_user_id'                 => null,
		'approved_at_utc'                     => null,
		'previous_note_revision_id'           => null,
		'note_review_id'                      => $review_id,
		'note_review_hash'                    => $review_hash,
		'note_review_evaluation_pk'           => '11',
		'joined_source_run_id'                => $run_id,
		'source_run_subject_type'             => 'submission_evaluation',
		'source_run_evaluation_id'            => $evaluation_id,
		'source_run_operation_key'            => Student_Note_Input_Builder::OPERATION,
		'source_run_state'                    => 'succeeded',
		'source_run_result_type'              => 'student_note_revision',
		'source_run_result_id'                => $revision_id,
		'evaluation_pk'                       => '11',
		'evaluation_id'                       => $evaluation_id,
		'evaluation_state'                    => 'reviewed',
		'evaluation_submission_revision_hash' => $source_hash,
		'evaluation_publication_id'           => $publication_id,
		'evaluation_publication_hash'         => $publication_hash,
		'source_revision_content_hash'        => $source_hash,
		'submission_id'                       => $submission_id,
		'submission_state'                    => 'submitted',
		'official_review_id'                  => $review_id,
		'official_review_hash'                => $review_hash,
		'assignment_id'                       => $assignment_id,
		'assignment_title'                    => 'Writing worksheet',
		'assignment_state'                    => 'open',
		'context_type'                        => 'class',
		'context_id'                          => 'class-g4-note',
		'context_revision'                    => 'context-r1',
		'assignment_publication_id'           => $publication_id,
		'assignment_publication_hash'         => $publication_hash,
		'target_content_hash'                 => str_repeat( '2', 64 ),
		'learner_user_id'                     => '71',
		'membership_revision'                 => 'member-r1',
		'display_snapshot_schema'             => 'oj_ai.assignment_target_display',
		'display_snapshot_version'            => '1',
		'display_snapshot_json'               => $display_json,
		'display_snapshot_hash'               => hash( 'sha256', $display_json ),
		'target_snapshot_hash'                => str_repeat( '3', 64 ),
		'target_state'                        => 'active',
		'revision_count'                      => '1',
	);
	$metadata         = array(
		'error_code'               => null,
		'student_note_revision_id' => $revision_id,
		'input_digest'             => str_repeat( '4', 64 ),
		'official_review_id'       => $review_id,
		'run_id'                   => $run_id,
	);
	$metadata_json    = $canonical->encode( $metadata );
	$audit            = array(
		'audit_id'               => g4_note_q_id(),
		'audit_occurred_at_utc'  => '2026-08-24 10:01:00',
		'audit_action'           => 'student_note.draft_created',
		'audit_object_type'      => 'submission_evaluation',
		'audit_object_id'        => $evaluation_id,
		'audit_context_type'     => 'class',
		'audit_context_id'       => 'class-g4-note',
		'audit_context_revision' => 'context-r1',
		'audit_before_digest'    => null,
		'audit_after_digest'     => str_repeat( '5', 64 ),
		'audit_metadata_schema'  => 'oj_ai.student_note_audit',
		'audit_metadata_version' => '1',
		'audit_metadata_json'    => $metadata_json,
		'audit_metadata_hash'    => hash( 'sha256', $metadata_json ),
	);
	return compact( 'root', 'audit' );
}

/**
 * G4 note q stored failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_q_stored_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_q_same( Submission_Evaluation_Failure::STORED_DATA_INVALID, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': expected stored-data failure was not thrown.' );
}

/**
 * G4-IMP-05-S02 student-note Q18/Q19 query contract.
 */
final class G4_Note_Query_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Result pages.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public array $result_pages;
	/**
	 * Row pages.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	public array $row_pages;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();

	/**
	 * Initialize g4 note query database dependencies and state.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $result_pages Result pages.
	 * @param array<int,array<string,mixed>|null>       $row_pages Row pages.
	 */
	public function __construct( array $result_pages, array $row_pages = array() ) {
		$this->result_pages = $result_pages;
		$this->row_pages    = $row_pages;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/%(s|d)/', $sql, $match ) ) {
				return '';
			}
			$replacement = 'd' === $match[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%' . $match[1] . '/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		unset( $format );
		$this->last_error = '';
		$this->sql[]      = $sql;
		return array_shift( $this->result_pages ) ?? array();
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		unset( $format );
		$this->last_error = '';
		$this->sql[]      = $sql;
		return array_shift( $this->row_pages );
	}
}

/**
 * G4-IMP-05-S02 student-note Q18/Q19 query contract.
 */
final class G4_Note_Fake_Query implements Student_Note_Query_Port_Interface {
	/**
	 * Initialize g4 note fake query dependencies and state.
	 *
	 * @param array<string,mixed>|null $snapshot Snapshot.
	 */
	public function __construct( private ?array $snapshot ) {
	}
	/**
	 * List context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $state State.
	 * @param string $report_status Report status.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_context( int $actor_user_id, string $context_type, string $context_id, string $context_revision, string $assignment_id, string $state, string $report_status, string $cursor, int $limit ): array {
		unset( $actor_user_id, $context_type, $context_id, $context_revision, $assignment_id, $state, $report_status, $cursor, $limit );
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}
	/**
	 * Find context.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 */
	public function find_context( string $note_id, string $context_type, string $context_id, string $context_revision ): ?array {
		unset( $note_id, $context_type, $context_id, $context_revision );
		return null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 */
	public function authorization_snapshot( string $note_id, string $context_type, string $context_id, string $context_revision ): ?array {
		unset( $note_id, $context_type, $context_id, $context_revision );
		return $this->snapshot;
	}
}

$canonical  = new Canonical_Json();
$normalizer = new Student_Note_Output_Normalizer( $canonical );
$projector  = new Student_Note_Query_Projector( $canonical, $normalizer );
$fixture    = g4_note_q_fixture( $canonical );

$list = $projector->list_item( $fixture['root'] );
g4_note_q_same( 'Student A', $list['learner']['display_name'], 'Q18 learner projection drifted.' );
g4_note_q_same( 'ai_draft', $list['latest_revision']['state'], 'Q18 latest state drifted.' );
g4_note_q_same( 'excluded', $list['status']['report_status'], 'AI draft must remain excluded from reports.' );
g4_note_q_same( true, $list['status']['review_required'], 'AI draft must remain reviewable.' );

$detail = $projector->detail( $fixture['root'], array( $fixture['root'] ), array( $fixture['audit'] ) );
g4_note_q_same( 1, count( $detail['revisions'] ), 'Q19 revision history drifted.' );
g4_note_q_same( 1, count( $detail['audit'] ), 'Q19 safe audit history drifted.' );
g4_note_q_same( $fixture['root']['note_review_id'], $detail['revisions'][0]['source']['official_review_id'], 'Q19 official review provenance drifted.' );
g4_note_q_same( $fixture['root']['note_content_hash'], $detail['precondition']['latest_revision_hash'], 'Q19 exact revision precondition drifted.' );
$encoded = $canonical->encode( $detail );
foreach ( array( 'approved_by_user_id', 'actor_user_id', 'correlation_id', 'provider_key', 'model_key', 'raw_prompt', 'raw_response' ) as $forbidden ) {
	g4_note_q_assert( ! str_contains( $encoded, $forbidden ), 'Teacher safe projection leaked: ' . $forbidden );
}

$included_draft                       = $fixture['root'];
$included_draft['include_in_reports'] = '1';
g4_note_q_stored_failure( static fn () => $projector->list_item( $included_draft ), 'Unapproved report inclusion must fail closed.' );
$wrong_review                             = $fixture['root'];
$corrected_review                         = $fixture['root'];
$corrected_review['official_review_id']   = g4_note_q_id();
$corrected_review['official_review_hash'] = str_repeat( '7', 64 );
$historical_note                          = $projector->detail( $corrected_review, array( $corrected_review ), array( $fixture['audit'] ) );
g4_note_q_same( $fixture['root']['note_review_id'], $historical_note['revisions'][0]['source']['official_review_id'], 'A score correction must preserve readable historical note provenance.' );
$wrong_review['note_review_hash'] = str_repeat( '9', 64 );
g4_note_q_stored_failure( static fn () => $projector->list_item( $wrong_review ), 'Official review provenance tampering must fail closed.' );
$private_profile               = $fixture['root'];
$decoded_profile               = json_decode( $private_profile['note_json'], true, 128, JSON_THROW_ON_ERROR );
$decoded_profile['raw_prompt'] = 'private';
$private_profile['note_json']  = $canonical->encode( $decoded_profile );
$private_profile['note_hash']  = hash( 'sha256', $private_profile['note_json'] );
g4_note_q_stored_failure( static fn () => $projector->list_item( $private_profile ), 'Unknown profile keys must fail closed.' );

$second                         = $fixture['root'];
$second['note_root_id']         = g4_note_q_id();
$second['note_revision_id']     = g4_note_q_id();
$second['note_source_run_id']   = g4_note_q_id();
$second['joined_source_run_id'] = $second['note_source_run_id'];
$second['source_run_result_id'] = $second['note_revision_id'];
$second['note_content_hash']    = str_repeat( '6', 64 );
$second['note_created_at_utc']  = '2026-08-24 10:00:00';
$database                       = new G4_Note_Query_Database( array( array( $fixture['root'], $second ), array( $second ) ) );
$cursors                        = new Signed_Cursor_Codec( $canonical, 'g4-note-query-signed-cursor-secret' );
$query                          = new WordPress_Student_Note_Query( $canonical, $normalizer, $cursors, $database );
$page                           = $query->list_context( 42, 'class', 'class-g4-note', 'context-r1', '', '', '', '', 1 );
g4_note_q_same( 1, count( $page['items'] ), 'Q18 first page size drifted.' );
g4_note_q_assert( '' !== $page['next_cursor'] && strlen( $page['next_cursor'] ) <= 191, 'Q18 signed cursor is missing or too long.' );
g4_note_q_same( array( 'f', 'i', 't' ), array_keys( $cursors->decode( $page['next_cursor'], 'q18' ) ), 'Q18 cursor fields drifted.' );
$next = $query->list_context( 42, 'class', 'class-g4-note', 'context-r1', '', '', '', $page['next_cursor'], 1 );
g4_note_q_same( $second['note_root_id'], $next['items'][0]['id'], 'Q18 cursor continuation drifted.' );
g4_note_q_assert( str_contains( $database->sql[1], 'note.created_at_utc <' ), 'Q18 keyset predicate must remain server-side.' );
foreach ( array( 'oj_ai_student_note_revisions', 'oj_ai_submission_evaluations', 'oj_ai_assignments', 'oj_ai_assignment_targets' ) as $table ) {
	g4_note_q_assert( str_contains( $database->sql[0], $table ), 'Q18 literal table join is missing: ' . $table );
}
try {
	$query->list_context( 43, 'class', 'class-g4-note', 'context-r1', '', '', '', $page['next_cursor'], 1 );
	throw new RuntimeException( 'Q18 cursor must not cross actor scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}
try {
	$query->list_context( 42, 'class', 'class-g4-note', 'context-r1', '', '', 'included', $page['next_cursor'], 1 );
	throw new RuntimeException( 'Q18 cursor must not cross report-filter scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$detail_database = new G4_Note_Query_Database( array( array( $fixture['root'] ), array( $fixture['audit'] ) ), array( $fixture['root'] ) );
$detail_query    = new WordPress_Student_Note_Query( $canonical, $normalizer, $cursors, $detail_database );
$stored_detail   = $detail_query->find_context( $fixture['root']['note_root_id'], 'class', 'class-g4-note', 'context-r1' );
g4_note_q_assert( is_array( $stored_detail ) && 1 === count( $stored_detail['revisions'] ), 'Q19 repository detail projection drifted.' );
g4_note_q_assert( str_contains( implode( "\n", $detail_database->sql ), 'oj_ai_audit_events' ), 'Q19 must load safe audit history from the literal table.' );

$snapshot      = array(
	'context_type'     => 'class',
	'context_id'       => 'class-g4-note',
	'context_revision' => 'context-r1',
	'current_revision' => str_repeat( '1', 64 ),
	'state'            => 'ai_draft',
	'relation'         => 'context_staff',
);
$object_policy = new Student_Note_Object_Authorization( new G4_Note_Fake_Query( $snapshot ) );
$context       = Context_Authorization_Result::allowed( 'class', 'class-g4-note', 'active', 'context-r1', 'teacher' );
$object        = new Object_Scope( Object_Scope::TYPE_STUDENT_NOTE, $fixture['root']['note_root_id'], 'view' );
$authorized    = $object_policy->authorize( 42, 'student_note.view', $context, $object );
g4_note_q_assert( $authorized->is_allowed(), 'Q19 note object authorization must accept an exact Context snapshot.' );
g4_note_q_assert( $object->requires_context(), 'Student-note object authorization must never be Context-free.' );

$reader = new Read_Student_Notes( new G4_Note_Fake_Query( null ) );
try {
	$reader->list( 42, 'class', 'class-g4-note', 'context-r1', '', '', 'automatic', '', 30 );
	throw new RuntimeException( 'Unknown report status must be rejected.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$repository_source  = file_get_contents( ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Query.php' );
$rest_source        = file_get_contents( ABSPATH . 'src/Submission/Assessment/Presentation/Student_Note_Query_Rest_Controller.php' );
$composition_source = file_get_contents( ABSPATH . 'src/Core/Composition_Root.php' );
g4_note_q_assert( is_string( $repository_source ) && ! str_contains( $repository_source, '$wpdb->prefix' ), 'Q18/Q19 must not use a WordPress table prefix.' );
g4_note_q_assert( 1 !== preg_match( '/\b(?:INSERT|UPDATE|DELETE)\b/i', (string) $repository_source ), 'Q18/Q19 query adapter must remain read-only.' );
g4_note_q_assert( is_string( $rest_source ) && 2 === Ojai_Php_Source_Contract::count( $rest_source, "'methods' => 'GET'" ), 'Q18/Q19 must register exactly two GET routes.' );
g4_note_q_assert( ! str_contains( (string) $rest_source, '/me/' ) && ! Ojai_Php_Source_Contract::contains( (string) $rest_source, "'methods' => 'POST'" ), 'S02 must add no student or mutation route.' );
foreach ( array( 'MANAGE_STUDENT_NOTES', 'OPERATION_REPORT', 'TYPE_STUDENT_NOTE', 'student_visible', 'report_auto_inclusion' ) as $marker ) {
	g4_note_q_assert( str_contains( (string) $rest_source, $marker ), 'Q18/Q19 REST boundary marker is missing: ' . $marker );
}
foreach ( array( 'WordPress_Student_Note_Query', 'Student_Note_Object_Authorization', 'Student_Note_Query_Rest_Module' ) as $marker ) {
	g4_note_q_assert( is_string( $composition_source ) && str_contains( $composition_source, $marker ), 'Composition Root marker is missing: ' . $marker );
}

fwrite( STDOUT, "G4-IMP-05-S02 passed: Q18/Q19 teacher-safe list, revision evidence, provenance, audit, signed cursor, and zero student routes are frozen.\n" );
