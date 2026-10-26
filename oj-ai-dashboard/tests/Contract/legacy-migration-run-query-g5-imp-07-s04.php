<?php
/**
 * G5-IMP-07-S04 Q29 migration-run checkpoint query contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Run_Query;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Run_Query_Rest_Controller;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

$GLOBALS['g5_s04_q29_routes'] = array();
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
		$GLOBALS['g5_s04_q29_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

/**
 * G5 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s04_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s04 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s04_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s04 id.
 *
 * @param int $value Value.
 * @return string
 */
function g5_s04_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * G5 s04 plan.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g5_s04_plan( Canonical_Json $canonical ): array {
	$source = new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', 'g5-s04-source' ),
		array(
			array(
				'logical_table' => 'worksheet_templates',
				'source_table'  => 'wp_oj_ai_worksheet_templates',
				'disposition'   => 'migrate',
				'rows'          => 4,
			),
		),
		0,
		0,
		array(
			'context' => 0,
			'user'    => 0,
		),
		array(),
		array(),
		array()
	);
	return ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $source );
}

/**
 * G5 s04 row.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $state State.
 * @return array<string,mixed>
 */
function g5_s04_row( Canonical_Json $canonical, string $state = 'running' ): array {
	$plan            = g5_s04_plan( $canonical );
	$preview_json    = $canonical->encode(
		array(
			'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
			'state'   => 'approved',
			'plan'    => $plan,
		)
	);
	$success         = 'approved' === $state ? 0 : 2;
	$failure         = 'approved' === $state ? 0 : 1;
	$checkpoint_json = null;
	$checkpoint_hash = null;
	if ( 'approved' !== $state ) {
		$checkpoint      = array(
			'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
			'phase'      => $state,
			'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
			'source'     => array(
				'profile'     => $plan['source']['profile'],
				'fingerprint' => $plan['source']['fingerprint'],
			),
			'plan_hash'  => $plan['plan_hash'],
			'cursor'     => array(
				'table_index'     => 0,
				'after_source_id' => 3,
				'next_item_no'    => 4,
			),
			'totals'     => array(
				'success' => $success,
				'failure' => $failure,
				'skipped' => 0,
			),
		);
		$checkpoint_json = $canonical->encode( $checkpoint );
		$checkpoint_hash = hash( 'sha256', $checkpoint_json );
	}
	return array(
		'run_database_id'    => '41',
		'public_id'          => g5_s04_id( 704 ),
		'created_at_utc'     => '2026-08-25 12:00:00',
		'updated_at_utc'     => '2026-08-25 12:20:00',
		'source_kind'        => 'oj_ai_dashboard_wpdb_prefixed',
		'source_version'     => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint' => $plan['source']['fingerprint'],
		'state'              => $state,
		'preview_schema'     => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'    => '1',
		'preview_json'       => $preview_json,
		'preview_hash'       => hash( 'sha256', $preview_json ),
		'approved_at_utc'    => '2026-08-25 12:05:00',
		'checkpoint_schema'  => null === $checkpoint_json ? null : Execute_Legacy_Migration::CHECKPOINT_SCHEMA,
		'checkpoint_version' => null === $checkpoint_json ? null : '1',
		'checkpoint_json'    => $checkpoint_json,
		'checkpoint_hash'    => $checkpoint_hash,
		'item_total'         => '4',
		'success_total'      => (string) $success,
		'failure_total'      => (string) $failure,
		'skipped_total'      => '0',
		'error_code'         => null,
	);
}

/**
 * G5-IMP-07-S04 Q29 migration-run checkpoint query contract.
 */
final class G5_S04_Q29_Wpdb {
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
	 * Initialize g5 s04 q29 wpdb dependencies and state.
	 *
	 * @param array<string,mixed>|null       $row Row.
	 * @param array<int,array{state:string}> $items Items.
	 */
	public function __construct( public ?array $row, public array $items ) {
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		return 'G5S04:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $prepared, string $format ): ?array {
		$decoded = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 'ARRAY_A' !== $format ) {
			$this->last_error = 'unexpected query';
			return null;
		}
		$sql             = (string) $decoded[0];
		$values          = (array) $decoded[1];
		$this->queries[] = $sql;
		if (
			! str_contains( $sql, 'FROM oj_ai_import_runs r' )
			|| 4 !== substr_count( $sql, 'FROM oj_ai_import_items' )
			|| ! str_contains( $sql, 'WHERE r.public_id = %s LIMIT 1' )
		) {
			$this->last_error = 'query contract drift';
			return null;
		}
		if ( null === $this->row || (string) ( $values[0] ?? '' ) !== (string) $this->row['public_id'] ) {
			return null;
		}
		$counts = array(
			'success' => 0,
			'failure' => 0,
			'skipped' => 0,
		);
		foreach ( $this->items as $item ) {
			if ( array_key_exists( $item['state'], $counts ) ) {
				++$counts[ $item['state'] ];
			}
		}
		return array_merge(
			$this->row,
			array(
				'observed_item_total'    => (string) count( $this->items ),
				'observed_success_total' => (string) $counts['success'],
				'observed_failure_total' => (string) $counts['failure'],
				'observed_skipped_total' => (string) $counts['skipped'],
			)
		);
	}
}

$canonical = new Canonical_Json();
$row       = g5_s04_row( $canonical );
$items     = array( array( 'state' => 'success' ), array( 'state' => 'success' ), array( 'state' => 'failure' ) );
$database  = new G5_S04_Q29_Wpdb( $row, $items );
$reader    = new Read_Legacy_Migration_Run( new WordPress_Legacy_Migration_Run_Query( $database ), $canonical );
$payload   = $reader->read( $row['public_id'] )->payload();

g5_s04_same( 'running', $payload['state'], 'Q29 run state drifted.' );
g5_s04_same( 4, $payload['items']['total'], 'Q29 item total drifted.' );
g5_s04_same( 3, $payload['items']['processed'], 'Q29 processed total drifted.' );
g5_s04_same( 1, $payload['items']['pending'], 'Q29 pending total drifted.' );
g5_s04_same( 2, $payload['items']['success'], 'Q29 success total drifted.' );
g5_s04_same( 1, $payload['items']['failure'], 'Q29 failure total drifted.' );
g5_s04_same( 4, $payload['checkpoint']['cursor']['next_item_no'], 'Q29 safe checkpoint cursor drifted.' );
g5_s04_same( $row['checkpoint_hash'], $payload['checkpoint']['hash'], 'Q29 checkpoint hash drifted.' );
g5_s04_assert( ! array_key_exists( 'source', $payload['checkpoint'] ), 'Q29 exposed checkpoint source internals.' );
g5_s04_assert( ! array_key_exists( 'plan_hash', $payload['checkpoint'] ), 'Q29 duplicated internal checkpoint plan data.' );
g5_s04_same( 1, count( $database->queries ), 'Q29 must use one consistent read statement.' );

$encoded = json_encode( $payload, JSON_THROW_ON_ERROR );
foreach ( array( 'source_key', 'payload_json', 'target_public_id', 'approved_by_user_id', 'created_by_user_id', 'wp_oj_ai_' ) as $private ) {
	g5_s04_assert( ! str_contains( $encoded, $private ), 'Q29 exposed a private migration field: ' . $private );
}
foreach ( array( 'INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'RENAME ' ) as $write ) {
	g5_s04_assert( ! str_contains( strtoupper( $database->queries[0] ), $write ), 'Q29 query performed a write: ' . $write );
}

$approved_row = g5_s04_row( $canonical, 'approved' );
$approved     = ( new Read_Legacy_Migration_Run(
	new WordPress_Legacy_Migration_Run_Query( new G5_S04_Q29_Wpdb( $approved_row, array() ) ),
	$canonical
) )->read( $approved_row['public_id'] )->payload();
g5_s04_same( null, $approved['checkpoint'], 'Q29 approved run must not invent a checkpoint.' );
g5_s04_same( 4, $approved['items']['pending'], 'Q29 approved pending total drifted.' );

$compensated_row                                  = g5_s04_row( $canonical, 'compensated' );
$compensated_checkpoint                           = json_decode( $compensated_row['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR );
$compensated_checkpoint['cursor']['next_item_no'] = 5;
$compensated_checkpoint['totals']['success']      = 3;
$compensated_row['success_total']                 = '3';
$compensated_row['checkpoint_json']               = $canonical->encode( $compensated_checkpoint );
$compensated_row['checkpoint_hash']               = hash( 'sha256', $compensated_row['checkpoint_json'] );
$compensated_items                                = array_merge( $items, array( array( 'state' => 'success' ) ) );
$compensated                                      = ( new Read_Legacy_Migration_Run(
	new WordPress_Legacy_Migration_Run_Query( new G5_S04_Q29_Wpdb( $compensated_row, $compensated_items ) ),
	$canonical
) )->read( $compensated_row['public_id'] )->payload();
g5_s04_same( 'compensated', $compensated['state'], 'Q29 compensated run state drifted.' );
g5_s04_same( 'compensated', $compensated['checkpoint']['phase'], 'Q29 compensated checkpoint phase drifted.' );
g5_s04_same( 0, $compensated['items']['pending'], 'Q29 compensated run must not report pending items.' );

g5_s04_failure(
	static fn () => $reader->read( g5_s04_id( 999 ) ),
	Legacy_Migration_Failure::RUN_NOT_FOUND,
	'Q29 missing run did not return not found.'
);

$tampered                  = $row;
$tampered['preview_json'] .= ' ';
g5_s04_failure(
	static fn () => ( new Read_Legacy_Migration_Run( new WordPress_Legacy_Migration_Run_Query( new G5_S04_Q29_Wpdb( $tampered, $items ) ), $canonical ) )->read( $tampered['public_id'] ),
	Legacy_Migration_Failure::RUN_DATA_INVALID,
	'Q29 accepted a tampered canonical preview.'
);

$unsupported                      = $row;
$unsupported['checkpoint_schema'] = 'oj_ai.legacy_migration_checkpoint.v2';
g5_s04_failure(
	static fn () => ( new Read_Legacy_Migration_Run( new WordPress_Legacy_Migration_Run_Query( new G5_S04_Q29_Wpdb( $unsupported, $items ) ), $canonical ) )->read( $unsupported['public_id'] ),
	Legacy_Migration_Failure::RUN_PROFILE_UNSUPPORTED,
	'Q29 accepted an unsupported checkpoint profile.'
);

$unknown_items = array_merge( $items, array( array( 'state' => 'unknown' ) ) );
g5_s04_failure(
	static fn () => ( new Read_Legacy_Migration_Run( new WordPress_Legacy_Migration_Run_Query( new G5_S04_Q29_Wpdb( $row, $unknown_items ) ), $canonical ) )->read( $row['public_id'] ),
	Legacy_Migration_Failure::RUN_DATA_INVALID,
	'Q29 accepted an unknown item state or inconsistent aggregate.'
);

$permissions  = ( new ReflectionClass( Rest_Permission_Handoff::class ) )->newInstanceWithoutConstructor();
$envelopes    = ( new ReflectionClass( Rest_Envelope_Factory::class ) )->newInstanceWithoutConstructor();
$correlations = ( new ReflectionClass( Correlation_Id_Factory::class ) )->newInstanceWithoutConstructor();
$controller   = new Legacy_Migration_Run_Query_Rest_Controller( $permissions, $reader, $envelopes, $correlations );
$controller->register_routes();
g5_s04_same( 1, count( $GLOBALS['g5_s04_q29_routes'] ), 'Q29 must register exactly one route.' );
$route = $GLOBALS['g5_s04_q29_routes'][0];
g5_s04_same( 'oj-ai/v1', $route[0], 'Q29 REST namespace drifted.' );
g5_s04_same( '/migration-runs/(?P<run_id>[0-9A-HJKMNP-TV-Z]{26})', $route[1], 'Q29 REST path drifted.' );
g5_s04_same( 'GET', $route[2]['methods'], 'Q29 must remain GET.' );

$production_files = array(
	$root . '/src/Operations/LegacyMigration/Application/Read_Legacy_Migration_Run.php',
	$root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Run_Query.php',
	$root . '/src/Operations/LegacyMigration/Presentation/Legacy_Migration_Run_Query_Rest_Controller.php',
	$root . '/src/Core/Composition_Root.php',
);
foreach ( $production_files as $path ) {
	$source = (string) file_get_contents( $path );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s04_assert( ! str_contains( $source, $forbidden ), 'Q29 crossed a frozen boundary: ' . $forbidden );
	}
}

echo "G5-IMP-07-S04 passed: Q29 single-statement literal-table snapshot, canonical preview/checkpoint validation, safe item summary, zero mutation, manage_migrations REST route, and zero platform writes are frozen.\n";
