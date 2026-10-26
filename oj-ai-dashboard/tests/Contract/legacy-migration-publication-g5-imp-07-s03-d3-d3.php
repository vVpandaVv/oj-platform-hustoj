<?php
/**
 * G5-IMP-07-S03-D3-D3 historical-only migration publication contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Publication_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Worksheet_Document_Converter;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Publication_Writer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Plate_Document_Service;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 d3d3 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d3_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d3 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d3_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d3 transform failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d3_transform_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_d3d3_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no transform failure was raised.' );
}

/**
 * G5 d3d3 storage failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d3_storage_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_d3d3_same( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no storage failure was raised.' );
}

/**
 * G5-IMP-07-S03-D3-D3 historical-only migration publication contract.
 */
final class G5_D3D3_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Fail publication insert.
	 *
	 * @var bool
	 */
	public bool $fail_publication_insert = false;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_import_items'           => array(),
		'oj_ai_worksheets'             => array(),
		'oj_ai_worksheet_revisions'    => array(),
		'oj_ai_worksheet_publications' => array(),
	);

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5D3D3:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_import_items'] as $row ) {
				if (
					(string) ( $row['import_run_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['source_type'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['source_key'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheets w INNER JOIN oj_ai_worksheet_revisions r' ) ) {
			$root     = $this->find( 'oj_ai_worksheets', 'public_id', $values[0] ?? null );
			$revision = $this->find( 'oj_ai_worksheet_revisions', 'public_id', $values[1] ?? null );
			if ( null === $root || null === $revision || (string) $root['current_revision_public_id'] !== (string) $revision['public_id'] ) {
				return null;
			}
			return array(
				'worksheet_database_id'        => $root['id'],
				'worksheet_public_id'          => $root['public_id'],
				'worksheet_state'              => $root['state'],
				'current_revision_public_id'   => $root['current_revision_public_id'],
				'latest_publication_public_id' => $root['latest_publication_public_id'],
				'revision_database_id'         => $revision['id'],
				'revision_public_id'           => $revision['public_id'],
				'revision_content_hash'        => $revision['content_hash'],
				'worksheet_id'                 => $revision['worksheet_id'],
				'revision_state'               => $revision['state'],
				'teacher_document_schema'      => $revision['teacher_document_schema'],
				'teacher_document_version'     => $revision['teacher_document_version'],
				'teacher_document_json'        => $revision['teacher_document_json'],
				'teacher_document_hash'        => $revision['teacher_document_hash'],
				'document_schema_version'      => $revision['document_schema_version'],
				'document_hash'                => $revision['document_hash'],
				'validation_schema_version'    => $revision['validation_schema_version'],
				'validation_hash'              => $revision['validation_hash'],
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_publications WHERE worksheet_revision_id = %d' ) ) {
			return $this->find( 'oj_ai_worksheet_publications', 'worksheet_revision_id', $values[0] ?? null );
		}
		throw new RuntimeException( 'Unexpected D3-D3 row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $prepared Prepared.
	 * @return int|string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $prepared ) {
		list( $sql, $values ) = $this->decode( $prepared );
		if ( ! str_contains( $sql, 'SELECT COALESCE(MAX(publication_no), 0) FROM oj_ai_worksheet_publications' ) ) {
			throw new RuntimeException( 'Unexpected D3-D3 scalar query: ' . $sql );
		}
		$maximum = 0;
		foreach ( $this->tables['oj_ai_worksheet_publications'] as $row ) {
			if ( (string) ( $row['worksheet_id'] ?? '' ) === (string) ( $values[0] ?? '' ) ) {
				$maximum = max( $maximum, (int) ( $row['publication_no'] ?? 0 ) );
			}
		}
		return $maximum;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( 'oj_ai_worksheet_publications' !== $table || $this->fail_publication_insert ) {
			$this->last_error = 'forced publication insert failure';
			return false;
		}
		$this->insert_id          = count( $this->tables[ $table ] ) + 1;
		$data['id']               = $this->insert_id;
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Decode g5 d3 d3 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5D3D3:' ) ) {
			throw new RuntimeException( 'D3-D3 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || ! is_string( $value[0] ?? null ) || ! is_array( $value[1] ?? null ) ) {
			throw new RuntimeException( 'D3-D3 prepared query is invalid.' );
		}
		return array( $value[0], $value[1] );
	}

	/**
	 * Find g5 d3 d3 database.
	 *
	 * @param string $table Table.
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>|null
	 */
	private function find( string $table, string $field, mixed $value ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( (string) ( $row[ $field ] ?? '' ) === (string) $value ) {
				return $row;
			}
		}
		return null;
	}
}

/**
 * G5 d3d3 fixture.
 *
 * @return array{0:G5_D3D3_Database,1:Canonical_Json,2:Public_Id_Generator,3:string,4:string,5:string}
 */
function g5_d3d3_fixture(): array {
	$canonical                              = new Canonical_Json();
	$ids                                    = new Public_Id_Generator();
	$documents                              = new Worksheet_Document_Canonicalizer( $canonical, $ids );
	$converter                              = new Legacy_Worksheet_Document_Converter(
		new Plate_Document_Service(),
		$documents,
		new Worksheet_Revision_Projector( $canonical, $ids ),
		$ids
	);
	$nodes                                  = array(
		array(
			'type'     => 'h1',
			'children' => array( array( 'text' => 'Migration Fractions' ) ),
		),
		array(
			'type'            => 'worksheet_response',
			'question_id'     => 'fraction_choice',
			'response_type'   => 'radio',
			'prompt'          => 'Which fraction is larger?',
			'required'        => true,
			'points'          => '2.00',
			'grading_enabled' => true,
			'correct_answer'  => 'one_half',
			'options'         => array(
				array(
					'value' => 'one_half',
					'label' => '1/2',
				),
				array(
					'value' => 'one_third',
					'label' => '1/3',
				),
			),
			'children'        => array( array( 'text' => '' ) ),
		),
	);
	$converted                              = $converter->convert(
		array(
			'title'                 => 'Migration Fractions',
			'plate_value_json'      => json_encode( $nodes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
			'worksheet_rubric_json' => '{}',
			'settings_json'         => '{}',
			'curriculum_links_json' => '[]',
			'rubric_mode'           => 'worksheet',
		)
	);
	$document                               = $converted['document'];
	$worksheet_id                           = $ids->create()->value();
	$revision_id                            = $ids->create()->value();
	$question_id                            = $ids->create()->value();
	$database                               = new G5_D3D3_Database();
	$database->tables['oj_ai_worksheets'][] = array(
		'id'                           => 11,
		'public_id'                    => $worksheet_id,
		'state'                        => 'draft',
		'current_revision_public_id'   => $revision_id,
		'latest_publication_public_id' => null,
	);
	$database->tables['oj_ai_worksheet_revisions'][] = array(
		'id'                        => 22,
		'public_id'                 => $revision_id,
		'content_hash'              => $document->document_hash(),
		'worksheet_id'              => 11,
		'state'                     => 'draft',
		'teacher_document_schema'   => Worksheet_Document_Profile::SCHEMA_ID,
		'teacher_document_version'  => 1,
		'teacher_document_json'     => $document->document_json(),
		'teacher_document_hash'     => $document->document_hash(),
		'document_schema_version'   => Worksheet_Document_Profile::SCHEMA_VERSION,
		'document_hash'             => $document->document_hash(),
		'validation_schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
		'validation_hash'           => $document->validation_hash(),
	);
	$metadata                                        = array(
		'document_hash'          => $document->document_hash(),
		'question_count'         => 1,
		'question_link_count'    => 0,
		'question_link_hashes'   => array(),
		'question_public_ids'    => array( hash( 'sha256', 'q_fraction_choice' ) => $question_id ),
		'question_source_hashes' => array( hash( 'sha256', 'q_fraction_choice' ) => hash( 'sha256', 'legacy-question' ) ),
		'revision_content_hash'  => $document->document_hash(),
		'revision_public_id'     => $revision_id,
		'worksheet_link_count'   => 0,
		'worksheet_link_hashes'  => array(),
	);
	$payload                                  = array(
		'profile'    => 'oj_ai.legacy_item_payload@1.0.0',
		'source'     => array(
			'checksum' => hash( 'sha256', 'template-10' ),
			'key'      => '10',
			'type'     => 'worksheet_templates',
		),
		'state'      => 'success',
		'target'     => array(
			'content_hash' => $document->document_hash(),
			'metadata'     => $metadata,
			'public_id'    => $worksheet_id,
			'type'         => 'worksheet',
		),
		'error_code' => null,
	);
	$payload_json                             = $canonical->encode( $payload );
	$database->tables['oj_ai_import_items'][] = array(
		'import_run_id'    => 41,
		'source_type'      => 'worksheet_templates',
		'source_key'       => '10',
		'state'            => 'success',
		'target_type'      => 'worksheet',
		'target_public_id' => $worksheet_id,
		'payload_schema'   => 'oj_ai.legacy_item_payload',
		'payload_version'  => 1,
		'payload_json'     => $payload_json,
		'payload_hash'     => hash( 'sha256', $payload_json ),
	);
	return array( $database, $canonical, $ids, $worksheet_id, $revision_id, $document->document_hash() );
}

list( $database, $canonical, $ids, $worksheet_id, $revision_id, $document_hash ) = g5_d3d3_fixture();
$writer          = new WordPress_Legacy_Migration_Publication_Writer( $canonical, $database, $ids );
$root_before     = $database->tables['oj_ai_worksheets'][0];
$revision_before = $database->tables['oj_ai_worksheet_revisions'][0];
$receipt_count   = count( $database->tables['oj_ai_import_items'] );
$created         = $writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:00:00' );

g5_d3d3_assert( ! $created->replayed(), 'First migration publication write was marked as replay.' );
g5_d3d3_same( $worksheet_id, $created->worksheet_public_id(), 'Migration publication lost the worksheet dependency.' );
g5_d3d3_same( $revision_id, $created->revision_public_id(), 'Migration publication lost the exact revision dependency.' );
g5_d3d3_same( $document_hash, $created->revision_document_hash(), 'Migration publication lost the exact document hash.' );
g5_d3d3_same( 1, count( $database->tables['oj_ai_worksheet_publications'] ), 'Migration publication was not inserted exactly once.' );
g5_d3d3_same( $root_before, $database->tables['oj_ai_worksheets'][0], 'Migration publication mutated the worksheet root.' );
g5_d3d3_same( $revision_before, $database->tables['oj_ai_worksheet_revisions'][0], 'Migration publication mutated the source revision.' );
g5_d3d3_same( $receipt_count, count( $database->tables['oj_ai_import_items'] ), 'D3-D3 consumed an assignment or extra import item receipt.' );

$row = $database->tables['oj_ai_worksheet_publications'][0];
g5_d3d3_same( 'retired', $row['state'], 'Historical-only publication was exposed as active.' );
g5_d3d3_same( null, $database->tables['oj_ai_worksheets'][0]['latest_publication_public_id'], 'Historical-only publication became the worksheet latest publication.' );
g5_d3d3_same( $row['content_hash'], $row['publication_hash'], 'Publication content and manifest hashes drifted.' );
g5_d3d3_same( $created->publication_hash(), $row['publication_hash'], 'Publication result hash drifted from storage.' );
foreach ( array( 'student_document', 'response_schema', 'grading_schema', 'print_projection' ) as $profile_name ) {
	$json = (string) $row[ $profile_name . '_json' ];
	g5_d3d3_same( hash( 'sha256', $json ), $row[ $profile_name . '_hash' ], $profile_name . ' physical hash drifted.' );
	g5_d3d3_same( 1, $row[ $profile_name . '_version' ], $profile_name . ' physical version drifted.' );
}
$student   = json_decode( (string) $row['student_document_json'], true, 128, JSON_THROW_ON_ERROR );
$responses = json_decode( (string) $row['response_schema_json'], true, 128, JSON_THROW_ON_ERROR );
$grading   = json_decode( (string) $row['grading_schema_json'], true, 128, JSON_THROW_ON_ERROR );
g5_d3d3_assert( ! str_contains( (string) $row['student_document_json'], 'correct_answer' ), 'Correct answer leaked into the student document.' );
g5_d3d3_assert( ! str_contains( (string) $row['response_schema_json'], 'correct_answer' ), 'Correct answer leaked into the response schema.' );
g5_d3d3_assert( str_contains( (string) $row['grading_schema_json'], 'correct_answer' ), 'Legacy correct answer was not retained in the private grading projection.' );
g5_d3d3_same( 'oj_ai.student_document', $student['schema_id'], 'Student document schema drifted.' );
g5_d3d3_same( 'oj_ai.student_response_schema', $responses['schema_id'], 'Response schema drifted.' );
g5_d3d3_same( 'oj_ai.grading_schema', $grading['schema_id'], 'Legacy grading projection is not report-compatible.' );
g5_d3d3_same( '1.1.0', $grading['schema_version'], 'Legacy grading semantic version drifted.' );
g5_d3d3_same( Legacy_Migration_Publication_Projector::PROJECTOR_VERSION, $grading['projector_version'], 'Migration-only grading marker is missing.' );
g5_d3d3_same( '2', $grading['payload']['rubric']['total_points'], 'Legacy grading total points drifted.' );
g5_d3d3_same( 1, count( $grading['payload']['bindings'] ), 'Legacy grading binding inventory drifted.' );

$manifest_profiles = array();
foreach ( array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' ) as $profile_name ) {
	$profile                            = json_decode( (string) $row[ $profile_name . '_json' ], true, 128, JSON_THROW_ON_ERROR );
	$manifest_profiles[ $profile_name ] = array(
		'payload_hash'      => $profile['payload_hash'],
		'projector_version' => $profile['projector_version'],
		'schema_id'         => $profile['schema_id'],
		'schema_version'    => $profile['schema_version'],
	);
}
$manifest = array(
	'manifest_version'     => '1.0.0',
	'profiles'             => $manifest_profiles,
	'source_revision_hash' => $document_hash,
);
g5_d3d3_same( hash( 'sha256', $canonical->encode( $manifest ) ), $row['publication_hash'], 'Stored publication hash is not the canonical manifest hash.' );

$replayed = $writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:01:00' );
g5_d3d3_assert( $replayed->replayed(), 'Second migration publication lookup was not marked as replay.' );
g5_d3d3_same( $created->publication_public_id(), $replayed->publication_public_id(), 'Replay changed the publication identity.' );
g5_d3d3_same( 1, count( $database->tables['oj_ai_worksheet_publications'] ), 'Replay inserted a duplicate publication.' );

$database->tables['oj_ai_worksheet_publications'][0]['state'] = 'active';
g5_d3d3_transform_failure(
	static fn () => $writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:02:00' ),
	Legacy_Target_Transformation_Failure::SOURCE_INVALID,
	'Active publication was accepted as a migration-only replay.'
);
$database->tables['oj_ai_worksheet_publications'][0]['state']               = 'retired';
$database->tables['oj_ai_worksheet_publications'][0]['grading_schema_hash'] = hash( 'sha256', 'tampered' );
g5_d3d3_transform_failure(
	static fn () => $writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:03:00' ),
	Legacy_Target_Transformation_Failure::SOURCE_INVALID,
	'Tampered migration projection was accepted as replay.'
);

list( $missing_database, $missing_canonical, $missing_ids ) = g5_d3d3_fixture();
$missing_database->tables['oj_ai_import_items']             = array();
$missing_writer = new WordPress_Legacy_Migration_Publication_Writer( $missing_canonical, $missing_database, $missing_ids );
g5_d3d3_transform_failure(
	static fn () => $missing_writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:04:00' ),
	Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING,
	'Missing worksheet receipt did not block publication creation.'
);

list( $failure_database, $failure_canonical, $failure_ids ) = g5_d3d3_fixture();
$failure_database->fail_publication_insert                  = true;
$failure_writer = new WordPress_Legacy_Migration_Publication_Writer( $failure_canonical, $failure_database, $failure_ids );
g5_d3d3_storage_failure(
	static fn () => $failure_writer->ensure_for_assignment( 41, 10, 77, '2026-08-25 16:05:00' ),
	'Publication insert failure did not close the migration item.'
);
g5_d3d3_same( 0, count( $failure_database->tables['oj_ai_worksheet_publications'] ), 'Failed publication insert left a partial row.' );

foreach ( array_merge( $database->queries, $missing_database->queries, $failure_database->queries ) as $sql ) {
	foreach ( array( 'wp_oj_ai_', '$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\\OjPlatform\\Kernel\\', 'START TRANSACTION', 'COMMIT', 'UPDATE oj_ai_worksheets', 'UPDATE oj_ai_worksheet_revisions' ) as $forbidden ) {
		g5_d3d3_assert( ! str_contains( $sql, $forbidden ), 'Migration publication SQL crossed a frozen boundary: ' . $forbidden );
	}
}
$writer_source     = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Publication_Writer.php' );
$assignment_source = file_get_contents( $root . '/src/Submission/Assignment/Infrastructure/WordPress_Assignment_Repository.php' );
foreach ( array( "'oj_ai_import_items'", "'oj_ai_worksheets'", "'oj_ai_worksheet_revisions'", "'oj_ai_worksheet_publications'" ) as $literal_table ) {
	g5_d3d3_assert( str_contains( (string) $writer_source, $literal_table ), 'Migration publication writer lost literal table ' . $literal_table );
}
g5_d3d3_assert( str_contains( (string) $assignment_source, "'active' !== ( \$row['state']" ), 'Normal assignment creation no longer rejects retired publications.' );
g5_d3d3_assert( str_contains( (string) $assignment_source, 'latest_publication_public_id' ), 'Normal assignment creation no longer requires the worksheet latest publication.' );

fwrite( STDOUT, "G5-IMP-07-S03-D3-D3 migration publication passed: exact receipt, canonical projections, retired storage, replay, conflict closure, and D3-D4 transaction hand-off are frozen.\n" );
