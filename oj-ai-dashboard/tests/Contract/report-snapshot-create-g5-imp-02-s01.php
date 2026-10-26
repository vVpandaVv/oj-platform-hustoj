<?php
/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot_Command;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot_Result;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Worksheet_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Snapshot_Rest_Controller;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

$GLOBALS['g5_snapshot_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string $namespace Namespace.
	 * @param string $route Route.
	 * @param array  $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['g5_snapshot_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

/**
 * G5 snapshot assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 snapshot same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 snapshot id.
 *
 * @param int $value Value.
 * @return string
 */
function g5_snapshot_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * G5 snapshot rehash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $source Source.
 * @return array<string,mixed>
 */
function g5_snapshot_rehash( Canonical_Json $canonical, array $source ): array {
	unset( $source['source_hash'] );
	$source['source_hash'] = hash( 'sha256', $canonical->encode( $source ) );
	return $source;
}

/**
 * G5 snapshot sources.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param int            $number Number.
 * @return array{progress:array<string,mixed>,worksheet:array<string,mixed>,assessment:array<string,mixed>}
 */
function g5_snapshot_sources( Canonical_Json $canonical, int $number ): array {
	$publication_id   = g5_snapshot_id( 100 + $number );
	$publication_hash = hash( 'sha256', 'publication-' . $number );
	$progress         = g5_snapshot_rehash(
		$canonical,
		array(
			'schema_id'                 => 'oj_ai.assignment_progress_source',
			'schema_version'            => '1.0.0',
			'publication'               => array(
				'id'   => $publication_id,
				'hash' => $publication_hash,
			),
			'assignment_count'          => 1,
			'assignments'               => array(),
			'target_count'              => 8,
			'official_submission_count' => 6,
			'official_review_count'     => 0,
			'official_review_refs'      => array(),
			'updated_at_utc'            => '2026-08-25 09:0' . $number . ':00',
		)
	);
	$worksheet        = g5_snapshot_rehash(
		$canonical,
		array(
			'schema_id'      => 'oj_ai.worksheet_report_source',
			'schema_version' => '1.0.0',
			'publication'    => array(
				'id'               => $publication_id,
				'hash'             => $publication_hash,
				'number'           => 1,
				'state'            => 'active',
				'published_at_utc' => '2026-08-20 09:00:00',
				'content_hash'     => $publication_hash,
			),
			'revision'       => array( 'id' => g5_snapshot_id( 200 + $number ) ),
			'rubric'         => array(
				'revision_id'     => g5_snapshot_id( 300 + $number ),
				'content_hash'    => hash( 'sha256', 'rubric-' . $number ),
				'total_points'    => '10.0000',
				'criterion_count' => 1,
			),
			'updated_at_utc' => '2026-08-25 08:0' . $number . ':00',
			'worksheet'      => array(
				'id'            => g5_snapshot_id( 400 + $number ),
				'title'         => 'Worksheet ' . $number,
				'state'         => 'active',
				'subject_label' => 'Math',
			),
		)
	);
	$assessment       = g5_snapshot_rehash(
		$canonical,
		array(
			'schema_id'                   => 'oj_ai.official_assessment_source',
			'schema_version'              => '1.0.0',
			'publication'                 => array(
				'id'   => $publication_id,
				'hash' => $publication_hash,
			),
			'official_review_count'       => 0,
			'scored_review_count'         => 0,
			'released_count'              => 0,
			'normalized_basis_points_sum' => 0,
			'criterion_totals'            => array(),
			'reviews'                     => array(),
			'updated_at_utc'              => null,
		)
	);
	return array(
		'progress'   => $progress,
		'worksheet'  => $worksheet,
		'assessment' => $assessment,
	);
}

/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 */
final class G5_Snapshot_Progress implements Assignment_Progress_Source_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g5 snapshot progress dependencies and state.
	 *
	 * @param array<string,array{items:array<int,array<string,mixed>>,next_cursor:string}> $pages Pages.
	 * @param Canonical_Json                                                               $canonical Canonical JSON encoder used for stable digests.
	 * @param bool                                                                         $mutate_second_collection Mutate second collection.
	 */
	public function __construct( private array $pages, private Canonical_Json $canonical, private bool $mutate_second_collection = false ) {
	}

	/**
	 * List context.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array
	 */
	public function list_context( int $actor_user_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit, ?Report_Filter $filter = null ): array {
		++$this->calls;
		$page = $this->pages[ $cursor ] ?? array(
			'items'       => array(),
			'next_cursor' => '',
		);
		if ( $this->mutate_second_collection && $this->calls > count( $this->pages ) && '' === $cursor && isset( $page['items'][0] ) ) {
			$page['items'][0]['updated_at_utc'] = '2026-08-25 10:00:00';
			$page['items'][0]                   = g5_snapshot_rehash( $this->canonical, $page['items'][0] );
		}
		return $page;
	}
}

/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 */
final class G5_Snapshot_Worksheets implements Worksheet_Report_Source_Query_Port_Interface {
	/**
	 * Initialize g5 snapshot worksheets dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * By publications.
	 *
	 * @param array $publications Publications.
	 * @return array
	 */
	public function by_publications( array $publications ): array {
		return array_map( fn ( array $publication ): array => $this->sources[ $publication['id'] ], $publications );
	}
}

/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 */
final class G5_Snapshot_Assessments implements Official_Assessment_Source_Query_Port_Interface {
	/**
	 * Initialize g5 snapshot assessments dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * By official reviews.
	 *
	 * @param array $progress_sources Progress sources.
	 * @return array
	 */
	public function by_official_reviews( array $progress_sources ): array {
		return array_map( fn ( array $source ): array => $this->sources[ $source['publication']['id'] ], $progress_sources );
	}
}

/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 */
final class G5_Snapshot_Memory_Repository implements Report_Snapshot_Repository_Port_Interface {
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Create_Report_Snapshot_Result}>
	 */
	private array $receipts = array();
	/**
	 * Last record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_record = null;

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Report_Snapshot_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Create_Report_Snapshot_Result {
		$stored = $this->receipts[ $command_storage_key ] ?? null;
		if ( null === $stored ) {
			return null;
		}
		if ( ! hash_equals( $stored['digest'], $request_digest ) ) {
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $stored['result'];
		return new Create_Report_Snapshot_Result( $result->snapshot_id(), $result->state(), $result->snapshot_hash(), $result->filter_hash(), $result->source_manifest_hash(), $result->projection_hash(), $result->row_count(), $result->generated_at_utc(), true );
	}

	/**
	 * Create g5 snapshot memory repository.
	 *
	 * @param array $record Record.
	 * @return Create_Report_Snapshot_Result
	 */
	public function create( array $record ): Create_Report_Snapshot_Result {
		$this->last_record = $record;
		$result            = new Create_Report_Snapshot_Result( $record['snapshot_public_id'], 'ready', $record['snapshot_hash'], $record['filter_hash'], $record['source_manifest_hash'], $record['projection_hash'], $record['row_count'], $record['generated_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G5 snapshot command.
 *
 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
 * @param Report_Filter              $filter Filter.
 * @param Worksheet_Report_Projector $projector Projector.
 * @param array                      $first First.
 * @param string                     $fingerprint Fingerprint.
 * @param string                     $expected_manifest Expected manifest.
 * @return array{command:Create_Report_Snapshot_Command,filter_hash:string,request_hash:string,first_manifest_hash:string}
 */
function g5_snapshot_command( Canonical_Json $canonical, Report_Filter $filter, Worksheet_Report_Projector $projector, array $first, string $fingerprint = '', string $expected_manifest = '' ): array {
	$context             = array(
		'type'     => 'class',
		'id'       => 'class-g5-snapshot',
		'revision' => 'class-g5-snapshot-r1',
	);
	$profiles            = new Report_Snapshot_Profile( $canonical );
	$filter_hash         = $profiles->filter( $context['type'], $context['id'], $context['revision'], $filter )['hash'];
	$payload             = array(
		'lens'             => 'worksheet',
		'context'          => $context,
		'filter'           => $filter->payload(),
		'source_page_size' => 1,
	);
	$request_hash        = hash(
		'sha256',
		$canonical->encode(
			array(
				'schema_id'      => Create_Report_Snapshot_Command::REQUEST_SCHEMA,
				'schema_version' => Create_Report_Snapshot_Command::REQUEST_VERSION,
				'payload'        => $payload,
			)
		)
	);
	$first_projection    = $projector->project( array( $first['progress'] ), array( $first['worksheet'] ), array( $first['assessment'] ) );
	$first_manifest_hash = '' === $expected_manifest ? $first_projection['source_manifest_hash'] : $expected_manifest;
	$command             = new Create_Report_Snapshot_Command(
		77,
		g5_snapshot_id( 900 ),
		'context',
		$context['id'],
		$context['revision'],
		$first_manifest_hash,
		$filter_hash,
		$request_hash,
		$context['type'],
		$context['id'],
		$context['revision'],
		$filter,
		1,
		'' === $fingerprint ? str_repeat( 'a', 64 ) : $fingerprint,
		'correlation-g5-snapshot',
		'educator',
		$canonical
	);
	return array(
		'command'             => $command,
		'filter_hash'         => $filter_hash,
		'request_hash'        => $request_hash,
		'first_manifest_hash' => $first_projection['source_manifest_hash'],
	);
}

/**
 * G5-IMP-02-S01 C45 immutable report snapshot contract.
 */
final class G5_Snapshot_Wpdb {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Race outbox insert.
	 *
	 * @var bool
	 */
	public bool $race_outbox_insert = false;
	/**
	 * Race request digest.
	 *
	 * @var string|null
	 */
	public ?string $race_request_digest = null;
	/**
	 * Concurrent tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $concurrent_tables = null;
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
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
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		$this->queries[] = $sql;
		if ( 'ARRAY_A' !== $format || ! str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			$this->last_error = 'unexpected row query';
			return null;
		}
		preg_match( "/idempotency_key = '([^']+)'/", $sql, $matches );
		$key = str_replace( "''", "'", $matches[1] ?? '' );
		foreach ( $this->tables['oj_ai_outbox_events'] ?? array() as $row ) {
			if ( $row['idempotency_key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		$this->queries[] = $sql;
		$rows            = $this->tables['oj_ai_audit_events'] ?? array();
		return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
	}

	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		if ( 'oj_ai_outbox_events' === $table && $this->race_outbox_insert ) {
			$this->race_outbox_insert = false;
			if ( null !== $this->race_request_digest ) {
				$payload = preg_replace(
					'/"request_digest":"[a-f0-9]{64}"/D',
					'"request_digest":"' . $this->race_request_digest . '"',
					(string) $data['payload_json'],
					1
				);
				g5_snapshot_assert( is_string( $payload ), 'Concurrent C45 fixture could not replace the request digest.' );
				$data['payload_json'] = $payload;
				$data['payload_hash'] = hash( 'sha256', $payload );
			}
			$this->concurrent_tables             = $this->tables;
			$this->concurrent_tables[ $table ][] = $data;
			$this->last_error                    = 'duplicate idempotency key from concurrent winner';
			return false;
		}
		if ( $this->fail_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( $row['idempotency_key'] === $data['idempotency_key'] ) {
					$this->last_error = 'duplicate idempotency key';
					return false;
				}
			}
		}
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = $this->tables;
		} elseif ( 'ROLLBACK' === $sql ) {
			$this->tables = $this->backup ?? $this->tables;
			$this->backup = null;
			if ( null !== $this->concurrent_tables ) {
				$this->tables            = $this->concurrent_tables;
				$this->concurrent_tables = null;
			}
		} elseif ( 'COMMIT' === $sql ) {
			$this->backup = null;
		}
		return 1;
	}
}

$canonical      = new Canonical_Json();
$projector      = new Worksheet_Report_Projector( $canonical );
$filter         = Report_Filter::from_query( '2026-08-01', '2026-09-01', 'Asia/Seoul', array( 'open' ), array( 'official' ) );
$source_one     = g5_snapshot_sources( $canonical, 1 );
$source_two     = g5_snapshot_sources( $canonical, 2 );
$pages          = array(
	''       => array(
		'items'       => array( $source_one['progress'] ),
		'next_cursor' => 'page-2',
	),
	'page-2' => array(
		'items'       => array( $source_two['progress'] ),
		'next_cursor' => '',
	),
);
$worksheet_map  = array(
	$source_one['progress']['publication']['id'] => $source_one['worksheet'],
	$source_two['progress']['publication']['id'] => $source_two['worksheet'],
);
$assessment_map = array(
	$source_one['progress']['publication']['id'] => $source_one['assessment'],
	$source_two['progress']['publication']['id'] => $source_two['assessment'],
);
$progress       = new G5_Snapshot_Progress( $pages, $canonical );
$memory         = new G5_Snapshot_Memory_Repository();
$entropy        = 1;
$ids            = new Public_Id_Generator(
	static function ( int $length ) use ( &$entropy ): string {
		return str_repeat( chr( $entropy++ ), $length );
	}
);
$application    = new Create_Report_Snapshot(
	$progress,
	new G5_Snapshot_Worksheets( $worksheet_map ),
	new G5_Snapshot_Assessments( $assessment_map ),
	$projector,
	$memory,
	new Report_Snapshot_Profile( $canonical ),
	$ids,
	$canonical,
	static fn (): string => '2026-08-25 12:00:00'
);
$command_values = g5_snapshot_command( $canonical, $filter, $projector, $source_one );
$result         = $application->execute( $command_values['command'] );
g5_snapshot_same( 2, $result->row_count(), 'C45 must snapshot every page, not only the visible first page.' );
g5_snapshot_same( 4, $progress->calls, 'C45 must collect all source pages twice to detect a generation race.' );
g5_snapshot_same( $command_values['filter_hash'], $result->filter_hash(), 'C45 filter hash drifted from Q20.' );
g5_snapshot_assert( ! $result->replayed(), 'The first C45 result must be a mutation.' );
g5_snapshot_assert( is_array( $memory->last_record ), 'C45 did not create a persistence record.' );
g5_snapshot_same( 2, $memory->last_record['row_count'], 'C45 persistence row count drifted.' );
$stored_projection = json_decode( $memory->last_record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
g5_snapshot_same( Report_Snapshot_Profile::PROJECTION_SCHEMA, $stored_projection['schema_id'], 'Projection profile schema drifted.' );
g5_snapshot_same( 'oj_ai.reporting_metrics@1.0.0', $stored_projection['metric_contract'], 'Snapshot metric contract drifted.' );

$replay = $application->execute( $command_values['command'] );
g5_snapshot_assert( $replay->replayed(), 'An exact C45 retry must replay its durable receipt.' );
g5_snapshot_same( $result->snapshot_id(), $replay->snapshot_id(), 'An exact C45 retry created another snapshot.' );
g5_snapshot_same( 4, $progress->calls, 'A C45 replay must not read mutable owner sources again.' );

try {
	$conflicting = g5_snapshot_command( $canonical, $filter, $projector, $source_one, str_repeat( 'a', 64 ), str_repeat( 'f', 64 ) );
	$application->execute( $conflicting['command'] );
	throw new RuntimeException( 'A changed C45 request reused the same idempotency fingerprint.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT, $failure->reason(), 'Changed C45 replay must fail with idempotency conflict.' );
}

$race_progress    = new G5_Snapshot_Progress( $pages, $canonical, true );
$race_repository  = new G5_Snapshot_Memory_Repository();
$race_application = new Create_Report_Snapshot(
	$race_progress,
	new G5_Snapshot_Worksheets( $worksheet_map ),
	new G5_Snapshot_Assessments( $assessment_map ),
	$projector,
	$race_repository,
	new Report_Snapshot_Profile( $canonical ),
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-25 12:00:00'
);
try {
	$race_application->execute( g5_snapshot_command( $canonical, $filter, $projector, $source_one, str_repeat( 'b', 64 ) )['command'] );
	throw new RuntimeException( 'C45 stored a snapshot while source data changed during collection.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::SOURCE_STALE, $failure->reason(), 'A source race must fail as report_source_stale.' );
}
g5_snapshot_same( null, $race_repository->last_record, 'A stale C45 collection must not reach persistence.' );

$loop_progress = new G5_Snapshot_Progress(
	array(
		''       => array(
			'items'       => array( $source_one['progress'] ),
			'next_cursor' => 'repeat',
		),
		'repeat' => array(
			'items'       => array( $source_two['progress'] ),
			'next_cursor' => 'repeat',
		),
	),
	$canonical
);
$loop_app      = new Create_Report_Snapshot( $loop_progress, new G5_Snapshot_Worksheets( $worksheet_map ), new G5_Snapshot_Assessments( $assessment_map ), $projector, new G5_Snapshot_Memory_Repository(), new Report_Snapshot_Profile( $canonical ), new Public_Id_Generator(), $canonical );
try {
	$loop_app->execute( g5_snapshot_command( $canonical, $filter, $projector, $source_one, str_repeat( 'c', 64 ) )['command'] );
	throw new RuntimeException( 'C45 accepted a repeated source cursor.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::STORED_DATA_INVALID, $failure->reason(), 'Repeated source cursor must fail closed.' );
}

$record               = $memory->last_record;
$wpdb                 = new G5_Snapshot_Wpdb();
$wordpress_repository = new WordPress_Report_Snapshot_Repository( $canonical, $wpdb );
$stored               = $wordpress_repository->create( $record );
g5_snapshot_same( $result->snapshot_id(), $stored->snapshot_id(), 'Literal-table repository changed the snapshot identity.' );
g5_snapshot_same( 1, count( $wpdb->tables['oj_ai_report_snapshots'] ?? array() ), 'C45 must INSERT exactly one snapshot row.' );
g5_snapshot_same( 1, count( $wpdb->tables['oj_ai_audit_events'] ?? array() ), 'C45 must append one audit event.' );
g5_snapshot_same( 1, count( $wpdb->tables['oj_ai_outbox_events'] ?? array() ), 'C45 must append one durable outbox receipt.' );
g5_snapshot_same( null, $wpdb->tables['oj_ai_report_snapshots'][0]['expires_at_utc'], 'An immutable report snapshot must not expire implicitly.' );
g5_snapshot_same( 'oj_ai.report_source_manifest', $wpdb->tables['oj_ai_report_snapshots'][0]['source_manifest_schema'], 'Stored source manifest schema drifted.' );
g5_snapshot_assert( ! str_contains( implode( "\n", array_keys( $wpdb->tables ) ), 'wp_oj_ai_' ), 'C45 used a WordPress-prefixed table.' );
g5_snapshot_assert( ! str_contains( implode( "\n", $wpdb->queries ), 'UPDATE oj_ai_report_snapshots' ), 'C45 must never UPDATE a stored report snapshot.' );
$audit_metadata = $wpdb->tables['oj_ai_audit_events'][0]['metadata_json'];
g5_snapshot_assert( ! str_contains( $audit_metadata, 'Worksheet 1' ) && ! str_contains( $audit_metadata, 'items' ), 'C45 audit metadata must not copy report body rows.' );

$durable_replay = $wordpress_repository->find_command_receipt( $record['command_storage_key'], $record['request_digest'] );
g5_snapshot_assert( $durable_replay instanceof Create_Report_Snapshot_Result && $durable_replay->replayed(), 'C45 outbox receipt did not replay after persistence.' );
try {
	$wordpress_repository->find_command_receipt( $record['command_storage_key'], str_repeat( 'f', 64 ) );
	throw new RuntimeException( 'C45 durable receipt accepted another request digest.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT, $failure->reason(), 'Stored C45 digest conflict reason drifted.' );
}

$concurrent_db                     = new G5_Snapshot_Wpdb();
$concurrent_db->race_outbox_insert = true;
$concurrent_replay                 = ( new WordPress_Report_Snapshot_Repository( $canonical, $concurrent_db ) )->create( $record );
g5_snapshot_assert( $concurrent_replay->replayed(), 'A concurrent exact C45 winner must be returned as a durable replay.' );
g5_snapshot_same( $record['snapshot_public_id'], $concurrent_replay->snapshot_id(), 'Concurrent exact C45 replay changed snapshot identity.' );
g5_snapshot_same( 1, count( $concurrent_db->tables['oj_ai_report_snapshots'] ?? array() ), 'Concurrent C45 recovery must expose exactly one committed snapshot.' );
g5_snapshot_assert( in_array( 'ROLLBACK', $concurrent_db->queries, true ), 'Concurrent C45 loser must roll back before reading the winner receipt.' );

$concurrent_conflict_db                      = new G5_Snapshot_Wpdb();
$concurrent_conflict_db->race_outbox_insert  = true;
$concurrent_conflict_db->race_request_digest = hash_equals( $record['request_digest'], str_repeat( 'f', 64 ) )
	? str_repeat( 'e', 64 )
	: str_repeat( 'f', 64 );
try {
	( new WordPress_Report_Snapshot_Repository( $canonical, $concurrent_conflict_db ) )->create( $record );
	throw new RuntimeException( 'A concurrent C45 winner with another digest was not rejected.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT, $failure->reason(), 'Concurrent changed C45 winner must return idempotency conflict.' );
}

$rollback_db             = new G5_Snapshot_Wpdb();
$rollback_db->fail_table = 'oj_ai_audit_events';
try {
	( new WordPress_Report_Snapshot_Repository( $canonical, $rollback_db ) )->create( $record );
	throw new RuntimeException( 'C45 committed a partial transaction after audit failure.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_same( Report_Snapshot_Failure::STORAGE_UNAVAILABLE, $failure->reason(), 'C45 storage failure code drifted.' );
}
g5_snapshot_same( 0, count( $rollback_db->tables['oj_ai_report_snapshots'] ?? array() ), 'C45 rollback left a partial snapshot row.' );
g5_snapshot_assert( in_array( 'ROLLBACK', $rollback_db->queries, true ), 'C45 did not roll back a failed transaction.' );

$permissions  = ( new ReflectionClass( Rest_Permission_Handoff::class ) )->newInstanceWithoutConstructor();
$envelopes    = ( new ReflectionClass( Rest_Envelope_Factory::class ) )->newInstanceWithoutConstructor();
$correlations = ( new ReflectionClass( Correlation_Id_Factory::class ) )->newInstanceWithoutConstructor();
$controller   = new Report_Snapshot_Rest_Controller( $permissions, $application, $canonical, $envelopes, $correlations );
$controller->register_routes();
g5_snapshot_same( 1, count( $GLOBALS['g5_snapshot_routes'] ), 'C45 must register exactly one REST route.' );
$route = $GLOBALS['g5_snapshot_routes'][0];
g5_snapshot_same( 'oj-ai/v1', $route[0], 'C45 REST namespace drifted.' );
g5_snapshot_same( '/reports/commands/create-snapshot', $route[1], 'C45 REST path drifted.' );
g5_snapshot_same( 'POST', $route[2]['methods'], 'C45 must remain POST.' );

$input_method = new ReflectionMethod( $controller, 'input' );
$request_body = array(
	'command_id' => g5_snapshot_id( 900 ),
	'expected'   => array(
		'expected_source_type'               => 'context',
		'expected_source_id'                 => 'class-g5-snapshot',
		'expected_source_revision'           => 'class-g5-snapshot-r1',
		'expected_live_source_manifest_hash' => $command_values['first_manifest_hash'],
		'expected_filter_hash'               => $command_values['filter_hash'],
		'expected_request_schema'            => Create_Report_Snapshot_Command::REQUEST_SCHEMA,
		'expected_request_version'           => Create_Report_Snapshot_Command::REQUEST_VERSION,
		'expected_request_hash'              => $command_values['request_hash'],
	),
	'payload'    => $command_values['command']->request_payload(),
);
$request      = new class( $request_body ) {
	/**
	 * Initialize report-snapshot-create-g5-imp-02-s01 dependencies and state.
	 *
	 * @param array $body Body.
	 */
	public function __construct( private array $body ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return $this->body;
	}
	/**
	 * Get query params.
	 *
	 * @return array
	 */
	public function get_query_params(): array {
		return array();
	}
};
$parsed       = $input_method->invoke( $controller, $request );
g5_snapshot_same( $filter->payload(), $parsed['filter']->payload(), 'C45 REST did not reconstruct the canonical report filter.' );
$invalid_body = $request_body;
$invalid_body['payload']['filter']['period']['to_utc'] = '2026-09-01 00:00:00';
try {
	$input_method->invoke(
		$controller,
		new class( $invalid_body ) {
			/**
			 * Initialize report-snapshot-create-g5-imp-02-s01 dependencies and state.
			 *
			 * @param array $body Body.
			 */
			public function __construct( private array $body ) {
			}
			/**
			 * Get json params.
			 *
			 * @return array
			 */
			public function get_json_params(): array {
				return $this->body;
			}
		}
	);
	throw new RuntimeException( 'C45 REST trusted a client-supplied UTC boundary.' );
} catch ( ReflectionException $error ) {
	throw $error;
} catch ( Throwable $error ) {
	$previous = $error->getPrevious();
	g5_snapshot_assert( $error instanceof InvalidArgumentException || $previous instanceof InvalidArgumentException, 'C45 noncanonical UTC failed for the wrong reason.' );
}

$production_files = array(
	$root . '/src/Reporting/Report/Application/Create_Report_Snapshot.php',
	$root . '/src/Reporting/Report/Infrastructure/WordPress_Report_Snapshot_Repository.php',
	$root . '/src/Reporting/Report/Presentation/Report_Snapshot_Rest_Controller.php',
	$root . '/src/Core/Composition_Root.php',
);
foreach ( $production_files as $path ) {
	$source = (string) file_get_contents( $path );
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_snapshot_assert( ! str_contains( $source, $forbidden ), 'C45 crossed a frozen boundary: ' . $forbidden );
	}
}
$rest_source = (string) file_get_contents( $production_files[2] );
foreach ( array( 'Addon_Capabilities::VIEW_REPORTS', 'Access_Policy_Request::SHELL_CONSOLE', 'Context_Scope::OPERATION_REPORT', 'idempotency_key()', "'mutation_count'", "'student_visible'" ) as $marker ) {
	g5_snapshot_assert( str_contains( $rest_source, $marker ), 'C45 REST security marker is missing: ' . $marker );
}
$repository_source = (string) file_get_contents( $production_files[1] );
foreach ( array( "'oj_ai_report_snapshots'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", "'report.snapshot.created'", 'START TRANSACTION', 'ROLLBACK' ) as $marker ) {
	g5_snapshot_assert( str_contains( $repository_source, $marker ), 'C45 persistence marker is missing: ' . $marker );
}

echo "G5-IMP-02-S01 passed: C45 canonical request, two-pass source freeze, immutable literal-table transaction, durable replay, rollback and Console Context policy are frozen.\n";
