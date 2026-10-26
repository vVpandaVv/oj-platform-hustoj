<?php
/**
 * G5-IMP-08-S05 Q36 purge-job status query contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Preview_Builder;
use OJAI\Dashboard\Operations\Purge\Application\Read_Purge_Job;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Infrastructure\WordPress_Purge_Job_Query;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Job_Query_Rest_Controller;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

$GLOBALS['g5_s05_q36_routes'] = array();
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
		$GLOBALS['g5_s05_q36_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

/**
 * G5 s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s05_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s05 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s05_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Purge_Failure $failure ) {
		g5_s05_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s05 id.
 *
 * @param int $value Value.
 * @return string
 */
function g5_s05_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * G5 s05 preview.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g5_s05_preview( Schema_Catalog $catalog, Canonical_Json $canonical ): array {
	$scope                   = array(
		'type'      => Preview_Purge_Command::SCOPE_ADDON,
		'public_id' => null,
	);
	$scope_descriptor        = array(
		'addon_id'           => Schema_Catalog::ADDON_ID,
		'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
		'schema_fingerprint' => $catalog->fingerprint(),
		'table_names'        => $catalog->table_names(),
		'scope'              => $scope,
	);
	$owners                  = array_fill_keys( array_keys( $catalog->owner_counts() ), 0 );
	$owners['Operations']    = 10;
	$owners['Worksheet']     = 5;
	$owners['Storage']       = 2;
	$preview                 = array(
		'profile'         => Purge_Preview_Builder::PROFILE,
		'scope'           => $scope,
		'scope_hash'      => hash( 'sha256', $canonical->encode( $scope_descriptor ) ),
		'table_count'     => Schema_Catalog::TABLE_COUNT,
		'total_row_count' => 17,
		'owner_rows'      => $owners,
		'private_files'   => array(
			'file_count'       => 2,
			'blob_bytes'       => 4096,
			'legal_hold_count' => 0,
		),
		'incomplete_jobs' => array(
			'total'   => 0,
			'by_type' => array(
				'evaluation_runs' => 0,
				'export_jobs'     => 0,
				'zip_uploads'     => 0,
				'import_runs'     => 0,
				'purge_jobs'      => 0,
			),
		),
	);
	$preview['preview_hash'] = hash( 'sha256', $canonical->encode( $preview ) );
	return $preview;
}

/**
 * G5 s05 row.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $state State.
 * @return array<string,mixed>
 */
function g5_s05_row( Schema_Catalog $catalog, Canonical_Json $canonical, string $state = 'running' ): array {
	$preview          = g5_s05_preview( $catalog, $canonical );
	$preview_json     = $canonical->encode( $preview );
	$checkpoint_json  = null;
	$checkpoint_hash  = null;
	$completed_at_utc = null;
	$error_code       = null;
	if ( ! in_array( $state, array( 'approved', 'cancelled' ), true ) ) {
		$completed       = 'completed' === $state;
		$failed          = 'failed' === $state;
		$checkpoint      = array(
			'profile'            => Execute_Purge::CHECKPOINT_PROFILE,
			'phase'              => $state,
			'batch_size'         => Execute_Purge::BATCH_SIZE,
			'time_limit_seconds' => Execute_Purge::TIME_LIMIT_SECONDS,
			'scope_hash'         => $preview['scope_hash'],
			'preview_hash'       => $preview['preview_hash'],
			'cursor'             => array(
				'stage'       => $completed || $failed ? 'verify' : 'tables',
				'table_index' => $completed || $failed ? Purge_Target_Catalog::TARGET_TABLE_COUNT : 8,
			),
			'totals'             => array(
				'deleted_rows'  => $completed ? 7 : 3,
				'deleted_files' => $completed ? 2 : 1,
				'deleted_bytes' => $completed ? 4096 : 1024,
			),
			'expected'           => array(
				'target_table_count' => Purge_Target_Catalog::TARGET_TABLE_COUNT,
				'target_row_count'   => 7,
				'private_file_count' => 2,
				'private_blob_bytes' => 4096,
			),
			'maintenance'        => array( 'previous_installation_state' => Installation_State::RUNTIME_READY ),
			'postcondition'      => array(
				'remaining_rows'       => $completed ? 0 : 9,
				'remaining_files'      => $completed ? 0 : 1,
				'remaining_blob_bytes' => $completed ? 0 : 3072,
				'verified_at_utc'      => $completed || $failed ? '2026-08-26 10:20:00' : null,
			),
		);
		$checkpoint_json = $canonical->encode( $checkpoint );
		$checkpoint_hash = hash( 'sha256', $checkpoint_json );
		if ( $completed || $failed ) {
			$completed_at_utc = '2026-08-26 10:20:00';
		}
		if ( $failed ) {
			$error_code = 'purge_postcondition_mismatch';
		}
	}
	if ( 'cancelled' === $state ) {
		$completed_at_utc = '2026-08-26 10:10:00';
	}
	return array(
		'job_database_id'          => '81',
		'public_id'                => g5_s05_id( 805 ),
		'lock_version'             => '3',
		'created_at_utc'           => '2026-08-26 10:00:00',
		'updated_at_utc'           => '2026-08-26 10:20:00',
		'scope_type'               => Preview_Purge_Command::SCOPE_ADDON,
		'scope_public_id'          => null,
		'state'                    => $state,
		'preview_schema'           => Approve_Purge::PREVIEW_SCHEMA,
		'preview_version'          => '1',
		'preview_json'             => $preview_json,
		'preview_hash'             => hash( 'sha256', $preview_json ),
		'approved_at_utc'          => '2026-08-26 10:05:00',
		'checkpoint_schema'        => null === $checkpoint_json ? null : Execute_Purge::CHECKPOINT_SCHEMA,
		'checkpoint_version'       => null === $checkpoint_json ? null : '1',
		'checkpoint_json'          => $checkpoint_json,
		'checkpoint_hash'          => $checkpoint_hash,
		'completed_at_utc'         => $completed_at_utc,
		'error_code'               => $error_code,
		'current_legal_hold_count' => '2',
	);
}

/**
 * G5-IMP-08-S05 Q36 purge-job status query contract.
 */
final class G5_S05_Q36_Wpdb {
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
	 * Initialize g5 s05 q36 wpdb dependencies and state.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	public function __construct( public ?array $row ) {
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		return 'G5S05:' . base64_encode( serialize( array( $sql, $values ) ) );
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
			! str_contains( $sql, 'FROM oj_ai_purge_jobs j' )
			|| 1 !== substr_count( $sql, 'FROM oj_ai_private_files' )
			|| ! str_contains( $sql, 'legal_hold_until_utc IS NOT NULL' )
			|| ! str_contains( $sql, 'WHERE j.public_id = %s LIMIT 1' )
		) {
			$this->last_error = 'query contract drift';
			return null;
		}
		if ( null === $this->row || (string) ( $values[0] ?? '' ) !== (string) $this->row['public_id'] ) {
			return null;
		}
		return $this->row;
	}
}

$canonical = new Canonical_Json();
$catalog   = new Schema_Catalog();
$row       = g5_s05_row( $catalog, $canonical );
$database  = new G5_S05_Q36_Wpdb( $row );
$reader    = new Read_Purge_Job(
	new WordPress_Purge_Job_Query( $database ),
	$canonical,
	$catalog,
	static fn (): string => '2026-08-26 10:20:00'
);
$payload   = $reader->read( $row['public_id'] )->payload();

g5_s05_same( 'running', $payload['state'], 'Q36 purge state drifted.' );
g5_s05_same( 'valid', $payload['approval']['status'], 'Q36 approval validity drifted.' );
g5_s05_same( '2026-08-26 10:35:00', $payload['approval']['expires_at_utc'], 'Q36 approval expiry drifted.' );
g5_s05_same( 0, $payload['legal_hold']['preview_count'], 'Q36 preview legal hold drifted.' );
g5_s05_same( 2, $payload['legal_hold']['current_count'], 'Q36 current legal hold drifted.' );
g5_s05_same( true, $payload['legal_hold']['changed'], 'Q36 legal hold change was not reported.' );
g5_s05_same( true, $payload['legal_hold']['blocks_execution'], 'Q36 legal hold did not block execution.' );
g5_s05_same( 'tables', $payload['checkpoint']['cursor']['stage'], 'Q36 checkpoint stage drifted.' );
g5_s05_same( 3, $payload['checkpoint']['totals']['deleted_rows'], 'Q36 checkpoint total drifted.' );
g5_s05_same( $row['checkpoint_hash'], $payload['checkpoint']['hash'], 'Q36 checkpoint hash drifted.' );
foreach ( array( 'expected', 'maintenance', 'scope_hash', 'preview_hash' ) as $private ) {
	g5_s05_assert( ! array_key_exists( $private, $payload['checkpoint'] ), 'Q36 exposed checkpoint internals: ' . $private );
}
g5_s05_same( 1, count( $database->queries ), 'Q36 must use one consistent read statement.' );
foreach ( array( 'INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'RENAME ' ) as $write ) {
	g5_s05_assert( ! str_contains( strtoupper( $database->queries[0] ), $write ), 'Q36 query performed a write: ' . $write );
}

$encoded = json_encode( $payload, JSON_THROW_ON_ERROR );
foreach ( array( 'approved_by_user_id', 'approval_token_hash', 'idempotency_key', 'storage_key', 'absolute_path' ) as $private ) {
	g5_s05_assert( ! str_contains( $encoded, $private ), 'Q36 exposed a private purge field: ' . $private );
}

$expired = ( new Read_Purge_Job(
	new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $row ) ),
	$canonical,
	$catalog,
	static fn (): string => '2026-08-26 10:35:00'
) )->read( $row['public_id'] )->payload();
g5_s05_same( 'expired', $expired['approval']['status'], 'Q36 did not expire approval at the exact 1,800-second boundary.' );

$approved_row                             = g5_s05_row( $catalog, $canonical, 'approved' );
$approved_row['current_legal_hold_count'] = '0';
$approved                                 = ( new Read_Purge_Job(
	new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $approved_row ) ),
	$canonical,
	$catalog,
	static fn (): string => '2026-08-26 10:20:00'
) )->read( $approved_row['public_id'] )->payload();
g5_s05_same( null, $approved['checkpoint'], 'Q36 approved job must not invent a checkpoint.' );
g5_s05_same( false, $approved['legal_hold']['blocks_execution'], 'Q36 clear legal hold state drifted.' );

$completed_row                             = g5_s05_row( $catalog, $canonical, 'completed' );
$completed_row['current_legal_hold_count'] = '0';
$completed                                 = ( new Read_Purge_Job(
	new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $completed_row ) ),
	$canonical,
	$catalog,
	static fn (): string => '2026-08-26 10:20:00'
) )->read( $completed_row['public_id'] )->payload();
g5_s05_same( 'completed', $completed['checkpoint']['phase'], 'Q36 completed checkpoint phase drifted.' );
g5_s05_same( 0, $completed['checkpoint']['postcondition']['remaining_rows'], 'Q36 completed postcondition drifted.' );

g5_s05_failure(
	static fn () => $reader->read( g5_s05_id( 999 ) ),
	Purge_Failure::JOB_NOT_FOUND,
	'Q36 missing purge job did not return not found.'
);

$tampered                  = $row;
$tampered['preview_json'] .= ' ';
g5_s05_failure(
	static fn () => ( new Read_Purge_Job(
		new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $tampered ) ),
		$canonical,
		$catalog,
		static fn (): string => '2026-08-26 10:20:00'
	) )->read( $tampered['public_id'] ),
	Purge_Failure::JOB_DATA_INVALID,
	'Q36 accepted a tampered canonical preview.'
);

$unsupported                      = $row;
$unsupported['checkpoint_schema'] = 'oj_ai.purge_checkpoint.v2';
g5_s05_failure(
	static fn () => ( new Read_Purge_Job(
		new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $unsupported ) ),
		$canonical,
		$catalog,
		static fn (): string => '2026-08-26 10:20:00'
	) )->read( $unsupported['public_id'] ),
	Purge_Failure::JOB_PROFILE_UNSUPPORTED,
	'Q36 accepted an unsupported checkpoint profile.'
);

$inconsistent                                 = $row;
$checkpoint                                   = json_decode( (string) $inconsistent['checkpoint_json'], true, 128, JSON_THROW_ON_ERROR );
$checkpoint['expected']['private_file_count'] = 3;
$inconsistent['checkpoint_json']              = $canonical->encode( $checkpoint );
$inconsistent['checkpoint_hash']              = hash( 'sha256', $inconsistent['checkpoint_json'] );
g5_s05_failure(
	static fn () => ( new Read_Purge_Job(
		new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $inconsistent ) ),
		$canonical,
		$catalog,
		static fn (): string => '2026-08-26 10:20:00'
	) )->read( $inconsistent['public_id'] ),
	Purge_Failure::JOB_DATA_INVALID,
	'Q36 accepted checkpoint expectations that differ from the approved preview.'
);

$overflow                             = $row;
$overflow['current_legal_hold_count'] = (string) PHP_INT_MAX . '0';
g5_s05_failure(
	static fn () => ( new Read_Purge_Job(
		new WordPress_Purge_Job_Query( new G5_S05_Q36_Wpdb( $overflow ) ),
		$canonical,
		$catalog,
		static fn (): string => '2026-08-26 10:20:00'
	) )->read( $overflow['public_id'] ),
	Purge_Failure::JOB_DATA_INVALID,
	'Q36 accepted a truncated database count.'
);

$permissions  = ( new ReflectionClass( Rest_Permission_Handoff::class ) )->newInstanceWithoutConstructor();
$envelopes    = ( new ReflectionClass( Rest_Envelope_Factory::class ) )->newInstanceWithoutConstructor();
$correlations = ( new ReflectionClass( Correlation_Id_Factory::class ) )->newInstanceWithoutConstructor();
$controller   = new Purge_Job_Query_Rest_Controller( $permissions, $reader, $envelopes, $correlations );
$controller->register_routes();
g5_s05_same( 1, count( $GLOBALS['g5_s05_q36_routes'] ), 'Q36 must register exactly one route.' );
$route = $GLOBALS['g5_s05_q36_routes'][0];
g5_s05_same( 'oj-ai/v1', $route[0], 'Q36 REST namespace drifted.' );
g5_s05_same( '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})', $route[1], 'Q36 REST path drifted.' );
g5_s05_same( 'GET', $route[2]['methods'], 'Q36 must remain GET.' );

$production_files = array(
	$root . '/src/Operations/Purge/Application/Read_Purge_Job.php',
	$root . '/src/Operations/Purge/Infrastructure/WordPress_Purge_Job_Query.php',
	$root . '/src/Operations/Purge/Presentation/Purge_Job_Query_Rest_Controller.php',
	$root . '/src/Core/Composition_Root.php',
);
foreach ( $production_files as $path ) {
	$source = (string) file_get_contents( $path );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s05_assert( ! str_contains( $source, $forbidden ), 'Q36 crossed a frozen boundary: ' . $forbidden );
	}
}
$policy_source = (string) file_get_contents( $root . '/src/Security/Policy/Access_Policy.php' );
g5_s05_assert( str_contains( $policy_source, "'operations.purge.read'" ), 'Q36 is unavailable through purge recovery policy.' );

echo "G5-IMP-08-S05 passed: Q36 single-statement literal-table status, approval expiry, current legal hold summary, safe checkpoint, zero mutation, recovery policy, manage_migrations GET route, and zero platform writes are frozen.\n";
