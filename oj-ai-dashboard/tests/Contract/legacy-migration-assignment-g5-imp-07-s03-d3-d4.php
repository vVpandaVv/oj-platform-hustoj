<?php
/**
 * G5-IMP-07-S03-D3-D4 historical assignment and exact target aggregate contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Publication;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Assignment_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 d3d4 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d4_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d4 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d4_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d4 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d4_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': no invalid argument was raised.' );
}

/**
 * G5 d3d4 storage failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d4_storage_failure( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( Legacy_Migration_Failure $failure ) {
		g5_d3d4_same( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': no storage failure was raised.' );
}

/**
 * G5-IMP-07-S03-D3-D4 historical assignment and exact target aggregate contract.
 */
final class G5_D3D4_Database {
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
	 * Fail target insert.
	 *
	 * @var bool
	 */
	public bool $fail_target_insert = false;
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
		'oj_ai_import_runs'            => array(),
		'oj_ai_assignments'            => array(),
		'oj_ai_assignment_targets'     => array(),
		'oj_ai_worksheet_publications' => array(),
	);
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array();
	/**
	 * Snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5D3D4:' . base64_encode( serialize( array( $sql, $values ) ) );
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
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE id = %d' ) ) {
			return $this->find( 'oj_ai_import_runs', 'id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments a WHERE a.id = %d' ) ) {
			$row = $this->find( 'oj_ai_assignments', 'id', $values[0] ?? null );
			if ( null === $row ) {
				return null;
			}
			$count = 0;
			foreach ( $this->tables['oj_ai_assignment_targets'] as $target ) {
				if ( (string) ( $target['assignment_id'] ?? '' ) === (string) $row['id'] ) {
					++$count;
				}
			}
			return array(
				'public_id'            => $row['public_id'],
				'publication_hash'     => $row['publication_hash'],
				'target_snapshot_hash' => $row['target_snapshot_hash'],
				'target_count'         => $count,
			);
		}
		throw new RuntimeException( 'Unexpected D3-D4 row query: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( ! array_key_exists( $table, $this->tables ) || ( $this->fail_target_insert && 'oj_ai_assignment_targets' === $table ) ) {
			$this->last_error = 'forced D3-D4 insert failure';
			return false;
		}
		$id                       = ( $this->next_ids[ $table ] ?? 0 ) + 1;
		$this->next_ids[ $table ] = $id;
		$this->insert_id          = $id;
		$data['id']               = $id;
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): bool {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return true;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->snapshot ) ) {
				$this->tables = $this->snapshot;
			}
			$this->snapshot = null;
			return true;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return true;
		}
		throw new RuntimeException( 'Unexpected D3-D4 transaction query: ' . $sql );
	}

	/**
	 * Decode g5 d3 d4 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5D3D4:' ) ) {
			throw new RuntimeException( 'D3-D4 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || ! is_string( $value[0] ?? null ) || ! is_array( $value[1] ?? null ) ) {
			throw new RuntimeException( 'D3-D4 prepared query is invalid.' );
		}
		return array( $value[0], $value[1] );
	}

	/**
	 * Find g5 d3 d4 database.
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
 * G5-IMP-07-S03-D3-D4 historical assignment and exact target aggregate contract.
 */
final class G5_D3D4_Publications implements Legacy_Migration_Publication_Writer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Missing.
	 *
	 * @var bool
	 */
	public bool $missing = false;
	/**
	 * Results.
	 *
	 * @var array<int,Legacy_Migration_Publication>
	 */
	private array $results = array();

	/**
	 * Initialize g5 d3 d4 publications dependencies and state.
	 *
	 * @param G5_D3D4_Database    $database Database.
	 * @param Public_Id_Generator $ids Ids.
	 */
	public function __construct( private G5_D3D4_Database $database, private Public_Id_Generator $ids ) {
	}

	/**
	 * Ensure for assignment.
	 *
	 * @param int    $run_database_id Run database id.
	 * @param int    $template_source_id Template source id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $published_at_utc Published at utc.
	 * @return Legacy_Migration_Publication
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function ensure_for_assignment( int $run_database_id, int $template_source_id, int $actor_user_id, string $published_at_utc ): Legacy_Migration_Publication {
		++$this->calls;
		if ( $this->missing ) {
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		if ( isset( $this->results[ $template_source_id ] ) ) {
			$current = $this->results[ $template_source_id ];
			return new Legacy_Migration_Publication(
				$current->worksheet_public_id(),
				$current->revision_public_id(),
				$current->revision_document_hash(),
				$current->publication_public_id(),
				$current->publication_hash(),
				$current->manifest_version(),
				true
			);
		}
		$publication = new Legacy_Migration_Publication(
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			hash( 'sha256', 'd3-d4-document-' . $template_source_id ),
			$this->ids->create()->value(),
			hash( 'sha256', 'd3-d4-publication-' . $template_source_id ),
			'1.0.0',
			false
		);
		if ( ! $this->database->insert( 'oj_ai_worksheet_publications', array( 'public_id' => $publication->publication_public_id() ) ) ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->results[ $template_source_id ] = $publication;
		return $publication;
	}
}

/**
 * G5-IMP-07-S03-D3-D4 historical assignment and exact target aggregate contract.
 */
final class G5_D3D4_Displays implements Learner_Display_Snapshot_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,int>
	 */
	public array $calls = array();
	/**
	 * Snapshot.
	 *
	 * @param int $learner_user_id Learner user id.
	 * @return array
	 */
	public function snapshot( int $learner_user_id ): array {
		$this->calls[] = $learner_user_id;
		return array( 'display_name' => 'Learner ' . $learner_user_id );
	}
}

/**
 * G5 d3d4 plan.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:array<string,mixed>,1:Legacy_Mapping_Manifest}
 */
function g5_d3d4_plan( Canonical_Json $canonical ): array {
	$mapping = new Legacy_Mapping_Manifest( $canonical, Legacy_Source_Profile_0_1_2::PROFILE );
	foreach ( array( 41, 42, 43 ) as $assignment_id ) {
		$mapping->observe(
			'worksheet_assignments',
			array(
				'id'            => (string) $assignment_id,
				'group_id'      => '0',
				'academic_year' => '2026',
				'grade'         => '5',
				'class_name'    => 'class-a',
			)
		);
	}
	$mapping->observe(
		'worksheet_submissions',
		array(
			'assignment_id'   => '41',
			'student_user_id' => '77',
		)
	);
	$mapping->observe(
		'worksheet_submissions',
		array(
			'assignment_id'   => '42',
			'student_user_id' => '88',
		)
	);
	$manifest  = $mapping->manifest();
	$snapshot  = new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', 'd3-d4-source' ),
		array(
			array(
				'logical_table' => 'worksheet_assignments',
				'source_table'  => 'wp_oj_ai_worksheet_assignments',
				'disposition'   => 'migrate',
				'rows'          => 3,
			),
			array(
				'logical_table' => 'worksheet_submissions',
				'source_table'  => 'wp_oj_ai_worksheet_submissions',
				'disposition'   => 'migrate',
				'rows'          => 2,
			),
		),
		0,
		0,
		$mapping->requirements(),
		array(),
		array(),
		$manifest
	);
	$decisions = array();
	foreach ( $manifest as $requirement ) {
		if ( 'context' === $requirement['kind'] ) {
			$target = array(
				'context_id'       => 'class-7',
				'context_revision' => 'context-r3',
				'context_type'     => 'class',
				'roster_revision'  => 'roster-r9',
			);
		} else {
			$user_id = $mapping->assignment_ref( 41 ) === $requirement['assignment_source_ref'] ? 707 : 808;
			$target  = array(
				'learner_user_id'     => $user_id,
				'membership_revision' => 'membership-' . $user_id,
			);
		}
		$decisions[] = array(
			'kind'       => $requirement['kind'],
			'source_ref' => $requirement['source_ref'],
			'target'     => $target,
		);
	}
	return array( ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $snapshot, $decisions ), $mapping );
}

/**
 * G5 d3d4 record.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param int                         $assignment_id Distribution public identifier.
 * @param string                      $status Status.
 * @return array<string,mixed>
 */
function g5_d3d4_record( Legacy_Source_Profile_0_1_2 $profile, int $assignment_id, string $status = 'assigned' ): array {
	$record = array_fill_keys( $profile->columns( 'worksheet_assignments' ), null );
	$values = array(
		'id'             => (string) $assignment_id,
		'tenant_context' => 'default',
		'template_id'    => '10',
		'title'          => 'Historical assignment ' . $assignment_id,
		'academic_year'  => '2026',
		'grade'          => '5',
		'class_name'     => 'class-a',
		'group_id'       => '0',
		'due_at'         => null,
		'status'         => $status,
		'settings_json'  => '{"auto_submit_enabled":true,"starts_at":"2026-08-20 09:00:00"}',
		'created_by'     => '7',
		'updated_by'     => '7',
		'created_at'     => '2026-08-20 08:00:00',
		'updated_at'     => '2026-08-20 10:00:00',
		'deleted_at'     => null,
		'source_type'    => '',
		'source_key'     => '',
		'source_id'      => '',
	);
	foreach ( $values as $key => $value ) {
		$record[ $key ] = $value;
	}
	return $record;
}

/**
 * G5 d3d4 item.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array          $record Record.
 * @param int            $item_no Item no.
 * @return Legacy_Source_Item
 */
function g5_d3d4_item( Canonical_Json $canonical, array $record, int $item_no ): Legacy_Source_Item {
	$source_id = (int) $record['id'];
	$checksum  = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) $source_id,
				'source_type' => 'worksheet_assignments',
				'record'      => $record,
			)
		)
	);
	return new Legacy_Source_Item( $item_no, 'worksheet_assignments', $source_id, $checksum, $record );
}

/**
 * G5 d3d4 fixture.
 *
 * @return array{0:G5_D3D4_Database,1:Canonical_Json,2:Legacy_Source_Profile_0_1_2,3:array<string,mixed>,4:G5_D3D4_Publications,5:G5_D3D4_Displays,6:WordPress_Legacy_Target_Writer}
 */
function g5_d3d4_fixture(): array {
	$canonical                               = new Canonical_Json();
	$profile                                 = new Legacy_Source_Profile_0_1_2();
	list( $plan )                            = g5_d3d4_plan( $canonical );
	$preview                                 = array(
		'plan'    => $plan,
		'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
		'state'   => 'approved',
	);
	$preview_json                            = $canonical->encode( $preview );
	$database                                = new G5_D3D4_Database();
	$database->tables['oj_ai_import_runs'][] = array(
		'id'                 => 51,
		'state'              => 'running',
		'source_kind'        => (string) strstr( Legacy_Source_Profile_0_1_2::PROFILE, '@', true ),
		'source_version'     => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint' => $plan['source']['fingerprint'],
		'preview_schema'     => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'    => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
		'preview_json'       => $preview_json,
		'preview_hash'       => hash( 'sha256', $preview_json ),
	);
	$ids                                     = new Public_Id_Generator();
	$publications                            = new G5_D3D4_Publications( $database, $ids );
	$displays                                = new G5_D3D4_Displays();
	$assignment_writer                       = new WordPress_Legacy_Migration_Assignment_Writer( $canonical, $publications, $displays, $database, $ids );
	$writer                                  = new WordPress_Legacy_Target_Writer( $canonical, $database, $ids, $profile, null, $assignment_writer );
	return array( $database, $canonical, $profile, $plan, $publications, $displays, $writer );
}

list( $database, $canonical, $profile, $plan, $publications, $displays, $writer ) = g5_d3d4_fixture();
$database->query( 'START TRANSACTION' );
$result = $writer->write( 51, g5_d3d4_item( $canonical, g5_d3d4_record( $profile, 41 ), 1 ), 99, '2026-08-25 18:00:00' );
$database->query( 'COMMIT' );

g5_d3d4_same( 'assignment', $result->target_type(), 'Assignment source was not dispatched to the D3-D4 aggregate writer.' );
g5_d3d4_same( 1, count( $database->tables['oj_ai_assignments'] ), 'Assignment root was not inserted exactly once.' );
g5_d3d4_same( 1, count( $database->tables['oj_ai_assignment_targets'] ), 'Assignment 41 did not receive its exact one learner target.' );
g5_d3d4_same( 707, $database->tables['oj_ai_assignment_targets'][0]['learner_user_id'], 'A learner from another assignment in the same Context leaked into assignment 41.' );
g5_d3d4_same( array( 707 ), $displays->calls, 'Display snapshots were read for learners outside assignment 41.' );
g5_d3d4_same( 'closed', $database->tables['oj_ai_assignments'][0]['state'], 'Historical assigned state remained actionable.' );
g5_d3d4_same( '2026-08-20 10:00:00', $database->tables['oj_ai_assignments'][0]['due_at_utc'], 'Missing legacy due time did not use the deterministic updated_at fallback.' );
g5_d3d4_same( '2026-08-20 10:00:00', $database->tables['oj_ai_assignments'][0]['closes_at_utc'], 'Historical assignment was not closed at its deterministic due time.' );
g5_d3d4_same( 1, $result->metadata()['target_count'], 'Assignment receipt target count drifted.' );
g5_d3d4_same( 1, $publications->calls, 'Assignment aggregate did not request exactly one migration publication.' );
g5_d3d4_same( 0, count( array_filter( $database->queries, static fn ( string $sql ): bool => 'START TRANSACTION' === $sql ) ) - 1, 'D3-D4 writer opened a nested transaction.' );

$database->query( 'START TRANSACTION' );
$zero = $writer->write( 51, g5_d3d4_item( $canonical, g5_d3d4_record( $profile, 43, 'draft' ), 2 ), 99, '2026-08-25 18:01:00' );
$database->query( 'COMMIT' );
g5_d3d4_same( 0, $zero->metadata()['target_count'], 'A zero-target historical assignment inferred learners from its Context.' );
g5_d3d4_same( 'withdrawn', $database->tables['oj_ai_assignments'][1]['state'], 'Historical draft was exposed as an actionable assignment.' );
g5_d3d4_same( 1, count( $database->tables['oj_ai_assignment_targets'] ), 'Zero-target assignment created an inferred target row.' );

$previous            = $plan;
$previous['profile'] = Legacy_Migration_Plan::PREVIOUS_PROFILE;
foreach ( $previous['mapping_manifest'] as &$requirement ) {
	if ( 'user' === $requirement['kind'] ) {
		unset( $requirement['assignment_source_ref'] );
	}
}
unset( $requirement );
unset( $previous['plan_hash'] );
$previous['plan_hash'] = hash( 'sha256', $canonical->encode( $previous ) );
$plans                 = new Legacy_Migration_Plan( $canonical );
$plans->assert_valid( $previous );
g5_d3d4_invalid( static fn () => $plans->assert_executable( $previous ), 'Context-only @1.1.0 mapping plan remained executable.' );

list( $rollback_database, $rollback_canonical, $rollback_profile, , $rollback_publications, , $rollback_writer ) = g5_d3d4_fixture();
$rollback_database->fail_target_insert = true;
$rollback_database->query( 'START TRANSACTION' );
g5_d3d4_storage_failure(
	static fn () => $rollback_writer->write( 51, g5_d3d4_item( $rollback_canonical, g5_d3d4_record( $rollback_profile, 41 ), 1 ), 99, '2026-08-25 18:02:00' ),
	'Target SQL failure was not surfaced as storage unavailable.'
);
$rollback_database->query( 'ROLLBACK' );
g5_d3d4_same( 0, count( $rollback_database->tables['oj_ai_worksheet_publications'] ), 'Target failure left a migration publication after rollback.' );
g5_d3d4_same( 0, count( $rollback_database->tables['oj_ai_assignments'] ), 'Target failure left an assignment root after rollback.' );
g5_d3d4_same( 0, count( $rollback_database->tables['oj_ai_assignment_targets'] ), 'Target failure left a learner target after rollback.' );
g5_d3d4_same( 1, $rollback_publications->calls, 'Rollback fixture did not reach the publication dependency.' );

$runtime = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Assignment_Writer.php' );
g5_d3d4_assert( is_string( $runtime ), 'D3-D4 runtime source could not be read.' );
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $forbidden ) {
	g5_d3d4_assert( ! str_contains( $runtime, $forbidden ), 'D3-D4 runtime crossed a frozen boundary: ' . $forbidden );
}
foreach ( array( "'oj_ai_import_runs'", "'oj_ai_assignments'", "'oj_ai_assignment_targets'" ) as $literal ) {
	g5_d3d4_assert( str_contains( $runtime, $literal ), 'D3-D4 literal target marker is missing: ' . $literal );
}

fwrite( STDOUT, "G5-IMP-07-S03-D3-D4 passed: assignment-bound mappings, closed historical roots, exact learner targets, and caller-owned rollback are frozen.\n" );
