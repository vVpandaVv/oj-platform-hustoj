<?php
/**
 * G2-IMP-07-S01 exact worksheet print projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Print_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-07-S01 exact worksheet print projection contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			return $value;
		}
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G2s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G2s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G2s01 failure.
 *
 * @param callable $callback Callback.
 * @param string   $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s01_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * G2s01 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g2s01_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g2s01_assert( Public_Id::is_valid( $id ), 'Fixture ID must use PublicIdV1.' );
	return $id;
}

/**
 * G2s01 element.
 *
 * @param string                              $type Type.
 * @param int                                 $id Id.
 * @param array<string,mixed>                 $properties Properties.
 * @param array<int,array<string,mixed>>|null $children Children.
 * @return array<string,mixed>
 */
function g2s01_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => g2s01_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-07-S01 exact worksheet print projection contract.
 */
final class G2S01_Worksheet_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize g2 s01 worksheet repository dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}
	/**
	 * Find owned worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function find_owned_worksheet( int $actor_user_id, string $worksheet_id ): ?array {
		return null;
	}
	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_owned_revisions( int $actor_user_id, string $worksheet_id, string $cursor, int $limit ): ?array {
		return null;
	}
	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		if ( 42 !== $actor_user_id || $revision_id !== ( $this->source['revision']['id'] ?? null ) ) {
			return null;
		}
		return $this->source;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $object_type Object type.
	 * @param string $object_id Object id.
	 * @return array|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $object_type, string $object_id ): ?array {
		return null;
	}
}

/**
 * G2-IMP-07-S01 exact worksheet print projection contract.
 */
final class G2S01_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Last arguments.
	 *
	 * @var array<int,mixed>
	 */
	public array $last_arguments = array();
	/**
	 * Last sql.
	 *
	 * @var string
	 */
	public string $last_sql = '';
	/**
	 * Initialize g2 s01 database dependencies and state.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public function __construct( public array $row ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, ...$arguments ): string {
		$this->last_sql       = $sql;
		$this->last_arguments = $arguments;
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $output ): ?array {
		$this->last_sql = $sql;
		if ( array_key_exists( 1, $this->last_arguments ) && 42 !== $this->last_arguments[1] ) {
			return null;
		}
		return $this->row;
	}
}

/**
 * G2s01 builder.
 *
 * @param G2S01_Worksheet_Repository       $worksheets Worksheets.
 * @param array<string,mixed>              $row Row.
 * @param Canonical_Json                   $canonical Canonical JSON encoder used for stable digests.
 * @param Worksheet_Document_Canonicalizer $documents Documents.
 * @param Worksheet_Preview_Projector      $previews Previews.
 * @param G2S01_Database|null              &$database Database.
 * @return Build_Worksheet_Print_Projection
 */
function g2s01_builder(
	G2S01_Worksheet_Repository $worksheets,
	array $row,
	Canonical_Json $canonical,
	Worksheet_Document_Canonicalizer $documents,
	Worksheet_Preview_Projector $previews,
	?G2S01_Database &$database = null
): Build_Worksheet_Print_Projection {
	$database = new G2S01_Database( $row );
	return new Build_Worksheet_Print_Projection(
		new WordPress_Worksheet_Print_Source_Query( $worksheets, $canonical, $database ),
		$documents,
		$previews,
		new Worksheet_Projection_Safety_Inspector( $canonical ),
		$canonical
	);
}

$worksheet_id     = g2s01_id( 1 );
$revision_id      = g2s01_id( 2 );
$publication_id   = g2s01_id( 3 );
$response_id      = g2s01_id( 4 );
$criterion_id     = g2s01_id( 5 );
$teacher_document = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => g2s01_id( 6 ),
	'locale'         => 'ko-KR',
	'title'          => '봄꽃 관찰 활동지',
	'nodes'          => array(
		g2s01_element( 'h1', 10, array(), array( array( 'text' => '봄꽃 관찰' ) ) ),
		g2s01_element( 'p', 11, array(), array( array( 'text' => '관찰한 내용을 자세히 적으세요.' ) ) ),
		g2s01_element(
			'oj_ai_response',
			12,
			array(
				'response_id' => $response_id,
				'kind'        => 'long_text',
				'label'       => '관찰 내용',
				'required'    => true,
				'constraints' => array(
					'min_chars' => 10,
					'max_chars' => 1000,
				),
				'grading'     => array(
					'grading_enabled'      => true,
					'max_points'           => '5',
					'example_answer'       => '교사용 예시 답안',
					'rubric_criterion_ids' => array( $criterion_id ),
					'teacher_note'         => '학생 PDF에 노출되면 안 되는 메모',
				),
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);

$canonical        = new Canonical_Json();
$ids              = new Public_Id_Generator( static fn ( int $length ): string => str_repeat( "\x29", $length ) );
$documents        = new Worksheet_Document_Canonicalizer( $canonical, $ids );
$receipt          = $documents->canonicalize( $teacher_document );
$previews         = new Worksheet_Preview_Projector( $canonical );
$published_print  = $previews->print( $receipt, 'student_blank' );
$print_envelope   = $published_print->projection()['print_projection'];
$print_json       = $canonical->encode( $print_envelope );
$publication_hash = hash( 'sha256', 'g2-s01-publication-manifest' );

$source     = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'approved',
		'document_hash'   => $receipt->document_hash(),
		'validation_hash' => $receipt->validation_hash(),
	),
	'teacher_document' => $receipt->document(),
	'questions'        => array(),
);
$row        = array(
	'publication_public_id'       => $publication_id,
	'content_hash'                => $publication_hash,
	'publication_state'           => 'active',
	'projection_manifest_version' => '1.0.0',
	'publication_hash'            => $publication_hash,
	'print_projection_schema'     => Worksheet_Preview_Projector::PRINT_SCHEMA,
	'print_projection_version'    => 1,
	'print_projection_json'       => $print_json,
	'print_projection_hash'       => hash( 'sha256', $print_json ),
	'revision_public_id'          => $revision_id,
	'revision_document_hash'      => $receipt->document_hash(),
	'revision_validation_hash'    => $receipt->validation_hash(),
	'worksheet_public_id'         => $worksheet_id,
);
$worksheets = new G2S01_Worksheet_Repository( $source );
$builder    = g2s01_builder( $worksheets, $row, $canonical, $documents, $previews, $database );

$revision_result = $builder->build( 42, 'revision', $revision_id, $receipt->document_hash(), 'teacher_blank' );
g2s01_same( 'revision', $revision_result['source']['type'], 'Revision source type drifted.' );
g2s01_same( $receipt->document_hash(), $revision_result['source']['hash'], 'Revision source must pin document hash.' );
g2s01_same( null, $revision_result['publication'], 'Revision print must not invent publication provenance.' );
g2s01_same( 'teacher_blank', $revision_result['print']['profile'], 'Revision print profile drifted.' );
g2s01_same( 'worksheet_print.v1', $revision_result['print']['artifact_profile_version'], 'Artifact print profile drifted.' );
g2s01_same( 'worksheet_a4@1', $revision_result['print']['page_profile'], 'Page profile drifted.' );
g2s01_same( 'ko-KR', $revision_result['print']['locale'], 'Print locale drifted.' );
$revision_json = $canonical->encode( $revision_result['print']['projection'] );
foreach ( array( 'grading', 'example_answer', 'teacher_note', 'rubric_criterion_ids' ) as $forbidden ) {
	g2s01_assert( ! str_contains( $revision_json, '"' . $forbidden . '"' ), 'Revision print leaked ' . $forbidden . '.' );
}

$revision_conflict = g2s01_failure(
	static fn () => $builder->build( 42, 'revision', $revision_id, hash( 'sha256', 'stale revision' ), 'student_blank' ),
	'Stale revision source hash must fail.'
);
g2s01_assert( $revision_conflict instanceof Worksheet_Failure, 'Revision source conflict must use Worksheet failure.' );
g2s01_same( Worksheet_Failure::PRINT_SOURCE_CONFLICT, $revision_conflict->reason(), 'Revision conflict reason drifted.' );
g2s01_same( $receipt->document_hash(), $revision_conflict->safe_context()['current_source_hash'], 'Conflict must expose only the safe current hash.' );

$publication_result = $builder->build( 42, 'publication', $publication_id, $publication_hash, 'student_blank' );
g2s01_same( $publication_hash, $publication_result['source']['hash'], 'Publication source must pin publication hash, not revision hash.' );
g2s01_same( $receipt->document_hash(), $publication_result['revision']['document_hash'], 'Publication must retain source revision hash.' );
g2s01_assert( $publication_hash !== $publication_result['revision']['document_hash'], 'The two provenance hashes must remain distinct.' );
g2s01_same( $print_json, $canonical->encode( $publication_result['print']['projection']['print_projection'] ), 'Publication P05 canonical bytes must be returned exactly as stored.' );
g2s01_same( hash( 'sha256', $print_json ), $publication_result['print']['profile_hash'], 'Stored P05 profile hash drifted.' );
g2s01_same( 'active', $publication_result['publication']['state'], 'Publication state drifted.' );
g2s01_assert( str_contains( $database->last_sql, 'FROM oj_ai_worksheet_publications p' ), 'Publication query must use the literal table.' );
g2s01_assert( str_contains( $database->last_sql, 'w.owner_user_id = %d' ), 'Publication query must bind owner authorization.' );
g2s01_same( array( $publication_id, 42 ), $database->last_arguments, 'Publication query binding drifted.' );

$exact_database    = new G2S01_Database( $row );
$exact_sources     = new WordPress_Worksheet_Print_Source_Query( $worksheets, $canonical, $exact_database );
$exact_publication = $exact_sources->find_exact_publication( $publication_id, $publication_hash );
g2s01_same( $publication_hash, $exact_publication['source_hash'], 'Cross-owner publication source must pin the exact immutable hash.' );
g2s01_assert( ! str_contains( $exact_database->last_sql, 'owner_user_id' ), 'Cross-owner exact publication query must not manufacture worksheet ownership.' );
g2s01_same( array( $publication_id ), $exact_database->last_arguments, 'Cross-owner exact publication query must bind only the public ID.' );
$exact_conflict = g2s01_failure(
	static fn () => $exact_sources->find_exact_publication( $publication_id, hash( 'sha256', 'stale-publication' ) ),
	'Stale cross-owner publication hash must fail.'
);
g2s01_assert( $exact_conflict instanceof Worksheet_Failure, 'Exact publication conflict must use Worksheet failure.' );
g2s01_same( Worksheet_Failure::PRINT_SOURCE_CONFLICT, $exact_conflict->reason(), 'Exact publication conflict reason drifted.' );
g2s01_same( $publication_hash, $exact_conflict->safe_context()['current_source_hash'], 'Exact publication conflict may expose only the current hash.' );

$immutable_profile = g2s01_failure(
	static fn () => $builder->build( 42, 'publication', $publication_id, $publication_hash, 'teacher_blank' ),
	'Publication profile substitution must fail.'
);
g2s01_assert( $immutable_profile instanceof InvalidArgumentException, 'Publication profile substitution must be a caller error.' );

$foreign_actor = g2s01_failure(
	static fn () => $builder->build( 99, 'publication', $publication_id, $publication_hash, 'student_blank' ),
	'Foreign actor publication access must fail.'
);
g2s01_assert( $foreign_actor instanceof Worksheet_Failure, 'Foreign actor must fail through the stable boundary.' );
g2s01_same( Worksheet_Failure::NOT_FOUND, $foreign_actor->reason(), 'Foreign actor must be hidden as not found.' );

$hash_drift_row                          = $row;
$hash_drift_row['print_projection_hash'] = hash( 'sha256', 'tampered bytes' );
$hash_drift_builder                      = g2s01_builder( $worksheets, $hash_drift_row, $canonical, $documents, $previews, $unused_database );
$hash_drift                              = g2s01_failure(
	static fn () => $hash_drift_builder->build( 42, 'publication', $publication_id, $publication_hash, 'student_blank' ),
	'P05 byte/hash drift must fail.'
);
g2s01_same( Worksheet_Failure::STORED_DATA_INVALID, $hash_drift->reason(), 'P05 byte/hash drift reason drifted.' );

$float_envelope                                      = $print_envelope;
$float_envelope['payload']['layout']['unsafe_scale'] = 1.5;
$float_json                         = json_encode( $float_envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
$float_row                          = $row;
$float_row['print_projection_json'] = $float_json;
$float_row['print_projection_hash'] = hash( 'sha256', $float_json );
$float_builder                      = g2s01_builder( $worksheets, $float_row, $canonical, $documents, $previews, $unused_database );
$float_failure                      = g2s01_failure(
	static fn () => $float_builder->build( 42, 'publication', $publication_id, $publication_hash, 'student_blank' ),
	'Non-canonical stored numbers must fail through the stable corruption boundary.'
);
g2s01_assert( $float_failure instanceof Worksheet_Failure, 'Stored float failure must not leak an encoder exception.' );
g2s01_same( Worksheet_Failure::STORED_DATA_INVALID, $float_failure->reason(), 'Stored float failure reason drifted.' );

$private_envelope = $print_envelope;
$private_envelope['payload']['document']['nodes'][2]['grading'] = array( 'teacher_note' => 'injected' );
$private_envelope['payload_hash']                               = hash( 'sha256', $canonical->encode( $private_envelope['payload'] ) );
$private_json                         = $canonical->encode( $private_envelope );
$private_row                          = $row;
$private_row['print_projection_json'] = $private_json;
$private_row['print_projection_hash'] = hash( 'sha256', $private_json );
$private_builder                      = g2s01_builder( $worksheets, $private_row, $canonical, $documents, $previews, $unused_database );
$private_failure                      = g2s01_failure(
	static fn () => $private_builder->build( 42, 'publication', $publication_id, $publication_hash, 'student_blank' ),
	'Rehashed private-field injection must fail.'
);
g2s01_same( Worksheet_Failure::STORED_DATA_INVALID, $private_failure->reason(), 'Private-field injection must fail closed.' );

$source_drift_envelope                     = $print_envelope;
$source_drift_envelope['source_hash']      = hash( 'sha256', 'different revision' );
$source_drift_json                         = $canonical->encode( $source_drift_envelope );
$source_drift_row                          = $row;
$source_drift_row['print_projection_json'] = $source_drift_json;
$source_drift_row['print_projection_hash'] = hash( 'sha256', $source_drift_json );
$source_drift_builder                      = g2s01_builder( $worksheets, $source_drift_row, $canonical, $documents, $previews, $unused_database );
$source_drift                              = g2s01_failure(
	static fn () => $source_drift_builder->build( 42, 'publication', $publication_id, $publication_hash, 'student_blank' ),
	'P05 revision source drift must fail.'
);
g2s01_same( Worksheet_Failure::STORED_DATA_INVALID, $source_drift->reason(), 'P05 revision source drift reason drifted.' );

$invalid_request = g2s01_failure(
	static fn () => $builder->build( 42, 'worksheet', $worksheet_id, $receipt->document_hash(), 'student_blank' ),
	'Unsupported source type must fail before storage.'
);
g2s01_assert( $invalid_request instanceof InvalidArgumentException, 'Unsupported source type must be a caller error.' );

$adapter_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Print_Source_Query.php' );
g2s01_assert( is_string( $adapter_source ), 'Print source adapter must be readable.' );
g2s01_assert( ! str_contains( $adapter_source, '$wpdb->prefix' ), 'Print source adapter must not use WordPress table prefixes.' );
g2s01_assert( ! str_contains( $adapter_source, 'wp_oj_ai_' ), 'Print source adapter must not use legacy prefixed tables.' );
foreach ( array( 'INSERT ', 'UPDATE ', 'DELETE ', 'START TRANSACTION' ) as $mutation ) {
	g2s01_assert( ! str_contains( $adapter_source, $mutation ), 'S01 source adapter must remain read-only: ' . $mutation );
}

echo "G2-IMP-07-S01 passed: exact revision/publication provenance, stored P05 immutability, structural safety, owner-only literal-table reads and source conflicts.\n";
