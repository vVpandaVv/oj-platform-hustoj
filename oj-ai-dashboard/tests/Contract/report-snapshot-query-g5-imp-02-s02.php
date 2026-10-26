<?php
/**
 * G5-IMP-02-S02 Q22 immutable report snapshot query contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Metrics;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Scope;
use OJAI\Dashboard\Reporting\Report\Infrastructure\Report_Snapshot_Object_Authorization;
use OJAI\Dashboard\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Query;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Controller;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

$GLOBALS['g5_snapshot_q22_routes'] = array();
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
		$GLOBALS['g5_snapshot_q22_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

/**
 * G5 snapshot q22 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_q22_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 snapshot q22 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_q22_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 snapshot q22 id.
 *
 * @param int $value Value.
 * @return string
 */
function g5_snapshot_q22_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * G5 snapshot q22 record.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{record:array<string,mixed>,snapshot_hash:string,projection:array<string,mixed>,filter:array<string,mixed>,worksheet_ids:array<int,string>}
 */
function g5_snapshot_q22_record( Canonical_Json $canonical ): array {
	$profiles         = new Report_Snapshot_Profile( $canonical );
	$metrics          = new Report_Metrics();
	$filter           = Report_Filter::from_query( '2026-08-01', '2026-09-01', 'Asia/Seoul', array( 'open' ), array( 'official' ) );
	$context_type     = 'class';
	$context_id       = 'class-g5-snapshot';
	$context_revision = 'class-g5-snapshot-r1';
	$generated_at     = '2026-08-25 13:00:00';
	$snapshot_id      = g5_snapshot_q22_id( 900 );
	$items            = array();
	$entries          = array();
	$worksheet_ids    = array();
	for ( $number = 1; $number <= 2; ++$number ) {
		$publication_id  = g5_snapshot_q22_id( 100 + $number );
		$worksheet_id    = g5_snapshot_q22_id( 400 + $number );
		$worksheet_ids[] = $worksheet_id;
		$hashes          = array(
			'worksheet'  => hash( 'sha256', 'worksheet-source-' . $number ),
			'progress'   => hash( 'sha256', 'progress-source-' . $number ),
			'assessment' => hash( 'sha256', 'assessment-source-' . $number ),
		);
		$entries[]       = array(
			'publication_id' => $publication_id,
			'sources'        => array(
				array(
					'owner'          => 'Worksheet',
					'schema_id'      => 'oj_ai.worksheet_report_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['worksheet'],
				),
				array(
					'owner'          => 'AssignmentSubmission',
					'schema_id'      => 'oj_ai.assignment_progress_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['progress'],
				),
				array(
					'owner'          => 'Assessment',
					'schema_id'      => 'oj_ai.official_assessment_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['assessment'],
				),
			),
		);
		$items[]         = array(
			'worksheet'      => array(
				'id'            => $worksheet_id,
				'title'         => 'Worksheet ' . $number,
				'state'         => 'active',
				'subject_label' => 'Math',
			),
			'publication'    => array(
				'id'               => $publication_id,
				'hash'             => hash( 'sha256', 'publication-' . $number ),
				'number'           => 1,
				'state'            => 'active',
				'published_at_utc' => '2026-08-20 09:00:00',
			),
			'rubric'         => array(
				'revision_id'     => g5_snapshot_q22_id( 300 + $number ),
				'content_hash'    => hash( 'sha256', 'rubric-' . $number ),
				'total_points'    => '10.0000',
				'criterion_count' => 1,
			),
			'progress'       => array(
				'assignment_count'      => 1,
				'target_count'          => 8,
				'submission_rate'       => $metrics->ratio( 6, 8 ),
				'assessment_rate'       => $metrics->ratio( 0, 6 ),
				'release_rate'          => $metrics->ratio( 0, 0 ),
				'average_score'         => $metrics->average( 0, 0 ),
				'review_required_count' => 6,
			),
			'updated_at_utc' => '2026-08-25 09:0' . $number . ':00',
			'source'         => array(
				'worksheet'           => array(
					'schema_id'      => 'oj_ai.worksheet_report_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['worksheet'],
				),
				'assignment_progress' => array(
					'schema_id'      => 'oj_ai.assignment_progress_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['progress'],
				),
				'official_assessment' => array(
					'schema_id'      => 'oj_ai.official_assessment_source',
					'schema_version' => '1.0.0',
					'hash'           => $hashes['assessment'],
				),
			),
		);
	}
	$manifest       = array(
		'schema_id'      => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
		'schema_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION,
		'entries'        => $entries,
	);
	$filter_profile = $profiles->filter( $context_type, $context_id, $context_revision, $filter );
	$snapshot       = $profiles->snapshot(
		$snapshot_id,
		$generated_at,
		$context_type,
		$context_id,
		$context_revision,
		$filter,
		$filter_profile,
		array(
			'items'                => $items,
			'source_manifest'      => $manifest,
			'source_manifest_hash' => hash( 'sha256', $canonical->encode( $manifest ) ),
		)
	);
	$record         = array(
		'public_id'               => $snapshot_id,
		'report_type'             => 'worksheet_progress',
		'subject_type'            => 'context',
		'subject_public_id'       => null,
		'state'                   => 'ready',
		'context_type'            => $context_type,
		'context_id'              => $context_id,
		'context_revision'        => $context_revision,
		'filter_schema'           => Report_Filter::SCHEMA_ID,
		'filter_version'          => Report_Snapshot_Profile::FILTER_DATABASE_VERSION,
		'filter_json'             => $snapshot['filter_json'],
		'filter_hash'             => $snapshot['filter_hash'],
		'source_watermark'        => $snapshot['source_manifest_hash'],
		'source_manifest_schema'  => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
		'source_manifest_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_DATABASE_VERSION,
		'source_manifest_json'    => $snapshot['source_manifest_json'],
		'source_manifest_hash'    => $snapshot['source_manifest_hash'],
		'projection_schema'       => Report_Snapshot_Profile::PROJECTION_SCHEMA,
		'projection_version'      => Report_Snapshot_Profile::PROJECTION_DATABASE_VERSION,
		'projection_json'         => $snapshot['projection_json'],
		'projection_hash'         => $snapshot['projection_hash'],
		'generated_by_user_id'    => 77,
		'generated_at_utc'        => $generated_at,
		'expires_at_utc'          => null,
	);
	return array(
		'record'        => $record,
		'snapshot_hash' => $snapshot['snapshot_hash'],
		'projection'    => json_decode( $snapshot['projection_json'], true, 128, JSON_THROW_ON_ERROR ),
		'filter'        => json_decode( $snapshot['filter_json'], true, 128, JSON_THROW_ON_ERROR ),
		'worksheet_ids' => $worksheet_ids,
	);
}

/**
 * G5-IMP-02-S02 Q22 immutable report snapshot query contract.
 */
final class G5_Snapshot_Q22_Query implements Report_Snapshot_Query_Port_Interface {
	/**
	 * Initialize g5 snapshot q22 query dependencies and state.
	 *
	 * @param array|null     $record Record.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( public ?array $record, private Canonical_Json $canonical ) {
	}
	/**
	 * Scope.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return Report_Snapshot_Scope|null
	 */
	public function scope( string $snapshot_id ): ?Report_Snapshot_Scope {
		if ( null === $this->record || $snapshot_id !== $this->record['public_id'] ) {
			return null;
		}
		$identity = $this->canonical->encode(
			array(
				'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
				'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
				'snapshot_id'          => $snapshot_id,
				'filter_hash'          => $this->record['filter_hash'],
				'source_manifest_hash' => $this->record['source_manifest_hash'],
				'projection_hash'      => $this->record['projection_hash'],
			)
		);
		return new Report_Snapshot_Scope( $snapshot_id, $this->record['state'], hash( 'sha256', $identity ), $this->record['context_type'], $this->record['context_id'], $this->record['context_revision'] );
	}
	/**
	 * Find record.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return array|null
	 */
	public function find_record( string $snapshot_id ): ?array {
		return null !== $this->record && $snapshot_id === $this->record['public_id'] ? $this->record : null;
	}
}

/**
 * G5-IMP-02-S02 Q22 immutable report snapshot query contract.
 */
final class G5_Snapshot_Q22_Objects implements Object_Authorization_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();
	/**
	 * Deny id.
	 *
	 * @var string|null
	 */
	public ?string $deny_id = null;
	/**
	 * Unavailable id.
	 *
	 * @var string|null
	 */
	public ?string $unavailable_id = null;
	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize( int $actor_user_id, string $action_key, ?Context_Authorization_Result $context, Object_Scope $object ): Object_Authorization_Result {
		$this->calls[] = $object->id();
		g5_snapshot_q22_same( Read_Report_Snapshot::ACTION_READ, $action_key, 'Q22 source action drifted.' );
		g5_snapshot_q22_same( Object_Scope::TYPE_WORKSHEET, $object->type(), 'Q22 must authorize worksheet owner objects.' );
		g5_snapshot_q22_same( 'view', $object->action(), 'Q22 source object action drifted.' );
		if ( $object->id() === $this->deny_id ) {
			return Object_Authorization_Result::denied();
		}
		if ( $object->id() === $this->unavailable_id ) {
			return Object_Authorization_Result::unavailable();
		}
		return Object_Authorization_Result::allowed( hash( 'sha256', $object->id() ), 'active', 'owner' );
	}
}

/**
 * G5-IMP-02-S02 Q22 immutable report snapshot query contract.
 */
final class G5_Snapshot_Q22_Wpdb {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Initialize g5 snapshot q22 wpdb dependencies and state.
	 *
	 * @param array|null $record Record.
	 */
	public function __construct( private ?array $record ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		return str_replace( '%s', "'" . str_replace( "'", "''", (string) ( $values[0] ?? '' ) ) . "'", $sql );
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
		if ( 'ARRAY_A' !== $format || ! str_contains( $sql, 'FROM oj_ai_report_snapshots' ) ) {
			$this->last_error = 'unexpected query';
			return null;
		}
		if ( null === $this->record || ! str_contains( $sql, "'" . $this->record['public_id'] . "'" ) ) {
			return null;
		}
		if ( str_contains( $sql, 'filter_json' ) ) {
			return $this->record;
		}
		$keys = array( 'public_id', 'report_type', 'subject_type', 'state', 'context_type', 'context_id', 'context_revision', 'filter_hash', 'source_manifest_hash', 'projection_hash' );
		return array_intersect_key( $this->record, array_fill_keys( $keys, true ) );
	}
}

$canonical = new Canonical_Json();
$fixture   = g5_snapshot_q22_record( $canonical );
$query     = new G5_Snapshot_Q22_Query( $fixture['record'], $canonical );
$objects   = new G5_Snapshot_Q22_Objects();
$reader    = new Read_Report_Snapshot( $query, $objects, new Report_Snapshot_Profile( $canonical ), $canonical );
$context   = Context_Authorization_Result::allowed( 'class', 'class-g5-snapshot', 'active', 'class-g5-snapshot-r2', 'educator', 'correlation-q22' );
$scope     = $reader->scope( $fixture['record']['public_id'] );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $scope->snapshot_hash(), 'Q22 scope identity hash drifted.' );
$result  = $reader->read( 77, $fixture['record']['public_id'], $context );
$payload = $result->payload();
g5_snapshot_q22_same( $fixture['projection'], $payload['projection'], 'Q22 must return the exact immutable projection.' );
g5_snapshot_q22_same( $fixture['filter'], $payload['filter'], 'Q22 must return the exact canonical filter.' );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $payload['snapshot_hash'], 'Q22 snapshot identity drifted.' );
g5_snapshot_q22_same( 2, $payload['row_count'], 'Q22 row count drifted.' );
g5_snapshot_q22_same( $fixture['worksheet_ids'], $objects->calls, 'Q22 did not reauthorize every worksheet source.' );
g5_snapshot_q22_assert( ! array_key_exists( 'source_manifest', $payload ), 'Q22 exposed the internal source manifest instead of its safe hash.' );

$wrong_context = Context_Authorization_Result::allowed( 'class', 'another-class', 'active', 'another-r1', 'educator' );
try {
	$reader->read( 77, $fixture['record']['public_id'], $wrong_context );
	throw new RuntimeException( 'Q22 disclosed a snapshot across Contexts.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'Q22 Context denial code drifted.' );
}

$objects->deny_id = $fixture['worksheet_ids'][1];
try {
	$reader->read( 77, $fixture['record']['public_id'], $context );
	throw new RuntimeException( 'Q22 disclosed a snapshot after source access was revoked.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'Q22 source revocation must fail closed.' );
}
$objects->deny_id        = null;
$objects->unavailable_id = $fixture['worksheet_ids'][0];
try {
	$reader->read( 77, $fixture['record']['public_id'], $context );
	throw new RuntimeException( 'Q22 disclosed a snapshot while source policy was unavailable.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE, $failure->reason(), 'Q22 unavailable source policy code drifted.' );
}

$tampered                             = $fixture['record'];
$tampered_projection                  = json_decode( $tampered['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$tampered_projection['student_email'] = 'hidden@example.test';
$tampered['projection_json']          = $canonical->encode( $tampered_projection );
$tampered['projection_hash']          = hash( 'sha256', $tampered['projection_json'] );
try {
	( new Read_Report_Snapshot( new G5_Snapshot_Q22_Query( $tampered, $canonical ), new G5_Snapshot_Q22_Objects(), new Report_Snapshot_Profile( $canonical ), $canonical ) )->read( 77, $tampered['public_id'], $context );
	throw new RuntimeException( 'Q22 returned a projection containing an unknown private field.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::STORED_DATA_INVALID, $failure->reason(), 'Q22 unknown projection field must fail closed.' );
}

$unsupported                      = $fixture['record'];
$unsupported['projection_schema'] = 'oj_ai.report_projection.v2';
try {
	( new Read_Report_Snapshot( new G5_Snapshot_Q22_Query( $unsupported, $canonical ), new G5_Snapshot_Q22_Objects(), new Report_Snapshot_Profile( $canonical ), $canonical ) )->read( 77, $unsupported['public_id'], $context );
	throw new RuntimeException( 'Q22 accepted an unsupported stored profile.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED, $failure->reason(), 'Q22 unsupported profile code drifted.' );
}

$object_authorizer = new Report_Snapshot_Object_Authorization( $query );
$report_object     = $object_authorizer->authorize( 77, Read_Report_Snapshot::ACTION_READ, $context, new Object_Scope( Object_Scope::TYPE_REPORT, $fixture['record']['public_id'], 'view' ) );
g5_snapshot_q22_assert( $report_object->is_allowed(), 'Q22 report object authorization rejected a current Context relation.' );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $report_object->current_revision(), 'Q22 report object revision must be the snapshot identity hash.' );
$cross_context_object = $object_authorizer->authorize( 77, Read_Report_Snapshot::ACTION_READ, $wrong_context, new Object_Scope( Object_Scope::TYPE_REPORT, $fixture['record']['public_id'], 'view' ) );
g5_snapshot_q22_assert( $cross_context_object->is_denied(), 'Q22 report object authorization crossed Contexts.' );

$wpdb            = new G5_Snapshot_Q22_Wpdb( $fixture['record'] );
$wordpress_query = new WordPress_Report_Snapshot_Query( $canonical, $wpdb );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $wordpress_query->scope( $fixture['record']['public_id'] )?->snapshot_hash(), 'Literal query adapter scope drifted.' );
g5_snapshot_q22_same( $fixture['record'], $wordpress_query->find_record( $fixture['record']['public_id'] ), 'Literal query adapter full row drifted.' );
g5_snapshot_q22_same( null, $wordpress_query->find_record( g5_snapshot_q22_id( 999 ) ), 'Literal query adapter must return null for a missing snapshot.' );
foreach ( $wpdb->queries as $sql ) {
	g5_snapshot_q22_assert( str_contains( $sql, 'oj_ai_report_snapshots' ), 'Q22 query did not use the literal owner table.' );
}

$permissions  = ( new ReflectionClass( Rest_Permission_Handoff::class ) )->newInstanceWithoutConstructor();
$envelopes    = ( new ReflectionClass( Rest_Envelope_Factory::class ) )->newInstanceWithoutConstructor();
$correlations = ( new ReflectionClass( Correlation_Id_Factory::class ) )->newInstanceWithoutConstructor();
$controller   = new Report_Snapshot_Query_Rest_Controller( $permissions, $reader, $envelopes, $correlations );
$controller->register_routes();
g5_snapshot_q22_same( 1, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'Q22 must register exactly one REST route.' );
$route = $GLOBALS['g5_snapshot_q22_routes'][0];
g5_snapshot_q22_same( 'oj-ai/v1', $route[0], 'Q22 REST namespace drifted.' );
g5_snapshot_q22_same( '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})', $route[1], 'Q22 REST path drifted.' );
g5_snapshot_q22_same( 'GET', $route[2]['methods'], 'Q22 must remain GET.' );

$production_files = array(
	$root . '/src/Reporting/Report/Application/Read_Report_Snapshot.php',
	$root . '/src/Reporting/Report/Infrastructure/WordPress_Report_Snapshot_Query.php',
	$root . '/src/Reporting/Report/Infrastructure/Report_Snapshot_Object_Authorization.php',
	$root . '/src/Reporting/Report/Presentation/Report_Snapshot_Query_Rest_Controller.php',
	$root . '/src/Core/Composition_Root.php',
);
foreach ( $production_files as $path ) {
	$source = (string) file_get_contents( $path );
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_snapshot_q22_assert( ! str_contains( $source, $forbidden ), 'Q22 crossed a frozen boundary: ' . $forbidden );
	}
}

echo "G5-IMP-02-S02 passed: Q22 canonical profile validation, immutable safe projection, current Context and every worksheet source authorization, literal-table query and REST route are frozen.\n";
