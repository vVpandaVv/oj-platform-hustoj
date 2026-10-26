<?php
/**
 * G5-IMP-05-S02 Q28 safe AI routing diagnostics contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as Platform_Autoloader;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_System_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\Addon_Runtime_Ai_Routing_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;

$root          = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild' . DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) || ! is_file( $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AddonApi' . DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'AddonAiTaskGatewayV1.php' ) ) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel' . DIRECTORY_SEPARATOR . 'Autoloader.php';
Platform_Autoloader::register( $platform_root );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Q28 ai assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_ai_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q28 ai same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_ai_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5-IMP-05-S02 Q28 safe AI routing diagnostics contract.
 */
final class Q28_Ai_Gateway implements AddonAiTaskGatewayV1 {
	/**
	 * Decision calls.
	 *
	 * @var array<int,string>
	 */
	public array $decision_calls = array();
	/**
	 * Snapshot calls.
	 *
	 * @var array<int,string>
	 */
	public array $snapshot_calls = array();
	/**
	 * Execute calls.
	 *
	 * @var int
	 */
	public int $execute_calls = 0;

	/**
	 * Initialize q28 ai gateway dependencies and state.
	 *
	 * @param Closure $decisions Decisions.
	 * @param Closure $snapshots Snapshots.
	 */
	public function __construct( private Closure $decisions, private Closure $snapshots ) {
	}

	/**
	 * Can route.
	 *
	 * @param string $taskKey Task key.
	 * @return AddonAiTaskRouteDecisionV1
	 */
	public function canRoute( string $taskKey ): AddonAiTaskRouteDecisionV1 {
		$this->decision_calls[] = $taskKey;
		return ( $this->decisions )( $taskKey );
	}

	/**
	 * Snapshot.
	 *
	 * @param string $taskKey Task key.
	 * @return AddonAiTaskRoutingSnapshotV1
	 */
	public function snapshot( string $taskKey ): AddonAiTaskRoutingSnapshotV1 {
		$this->snapshot_calls[] = $taskKey;
		return ( $this->snapshots )( $taskKey );
	}

	/**
	 * Execute q28 ai gateway.
	 *
	 * @param AddonAiTaskRequestV1 $request Request.
	 * @return AddonAiTaskResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( AddonAiTaskRequestV1 $request ): AddonAiTaskResultV1 {
		++$this->execute_calls;
		throw new RuntimeException( 'Q28 diagnostics must not execute AI work.' );
	}
}

/**
 * G5-IMP-05-S02 Q28 safe AI routing diagnostics contract.
 */
final class Q28_Ai_Bridge implements Bridge_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	public function inspect(): Bridge_Diagnostics_Result {
		return new Bridge_Diagnostics_Result( Bridge_Diagnostics_Result::READY, 'addon_runtime_ready', '2.0.0-dev.35', '1.1.0' );
	}
}

/**
 * G5-IMP-05-S02 Q28 safe AI routing diagnostics contract.
 */
final class Q28_Ai_Schema implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Verify q28 ai schema.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		return new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::READY, 'schema_runtime_ready' );
	}
}

/**
 * G5-IMP-05-S02 Q28 safe AI routing diagnostics contract.
 */
final class Q28_Ai_Queue implements Queue_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 */
	public function inspect(): Queue_Diagnostics_Result {
		return new Queue_Diagnostics_Result(
			Queue_Diagnostics_Result::READY,
			'queue_runtime_ready',
			array(
				'expected_workers'  => 4,
				'scheduled_workers' => 4,
				'pending'           => 0,
				'active'            => 0,
				'delayed'           => 0,
				'stale_leases'      => 0,
				'recent_failures'   => 0,
			)
		);
	}
}

/**
 * Q28 ai decision.
 *
 * @param bool   $routable Routable.
 * @param string $revision Revision.
 * @return AddonAiTaskRouteDecisionV1
 */
function q28_ai_decision( bool $routable, string $revision = 'route-r1' ): AddonAiTaskRouteDecisionV1 {
	return new AddonAiTaskRouteDecisionV1(
		$routable,
		$routable ? '' : 'addon_ai_task_unroutable',
		$revision,
		'route-correlation-0001'
	);
}

/**
 * Q28 ai ready snapshot.
 *
 * @param string $task_key Task key.
 * @param string $revision Revision.
 * @return AddonAiTaskRoutingSnapshotV1
 */
function q28_ai_ready_snapshot( string $task_key, string $revision = 'route-r1' ): AddonAiTaskRoutingSnapshotV1 {
	return new AddonAiTaskRoutingSnapshotV1(
		true,
		$task_key,
		'provider-secret-marker',
		'model-secret-marker',
		'profile-fingerprint-r1',
		$revision,
		null
	);
}

/**
 * Q28 ai unavailable snapshot.
 *
 * @param string $task_key Task key.
 * @param bool   $retryable Retryable.
 * @return AddonAiTaskRoutingSnapshotV1
 */
function q28_ai_unavailable_snapshot( string $task_key, bool $retryable ): AddonAiTaskRoutingSnapshotV1 {
	return new AddonAiTaskRoutingSnapshotV1(
		false,
		$task_key,
		'',
		'',
		'',
		'',
		new AddonApiProblemV1(
			$retryable ? 'addon_ai_task_failed' : 'addon_ai_task_unroutable',
			'raw platform message secret-marker',
			'problem-correlation-0001',
			$retryable,
			array(
				'endpoint'      => 'https://secret.example',
				'authorization' => 'Bearer hidden',
			)
		)
	);
}

/**
 * Q28 ai adapter.
 *
 * @param Q28_Ai_Gateway $gateway Gateway.
 * @return Addon_Runtime_Ai_Routing_Diagnostics
 */
function q28_ai_adapter( Q28_Ai_Gateway $gateway ): Addon_Runtime_Ai_Routing_Diagnostics {
	return new Addon_Runtime_Ai_Routing_Diagnostics(
		new Addon_Runtime_Bridge(),
		static fn () => $gateway
	);
}

$task_keys     = array( Addon_Manifest::TASK_WORKSHEET_QUALITY, Addon_Manifest::TASK_STUDENT_ASSESSMENT );
$ready_gateway = new Q28_Ai_Gateway(
	static fn ( string $task_key ): AddonAiTaskRouteDecisionV1 => q28_ai_decision( true ),
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => q28_ai_ready_snapshot( $task_key )
);
$ready_adapter = q28_ai_adapter( $ready_gateway );
$ready_result  = $ready_adapter->inspect();
q28_ai_same( 'ready', $ready_result->status(), 'Two routable AI tasks did not produce a ready aggregate.' );
q28_ai_same( 'ai_routing_ready', $ready_result->code(), 'Ready AI routing code drifted.' );
q28_ai_same( $task_keys, $ready_gateway->decision_calls, 'Q28 did not inspect both AI task route decisions exactly once.' );
q28_ai_same( $task_keys, $ready_gateway->snapshot_calls, 'Q28 did not inspect both AI task snapshots exactly once.' );
q28_ai_same( 0, $ready_gateway->execute_calls, 'Q28 diagnostics executed AI work.' );

$query      = new Read_System_Diagnostics(
	new Q28_Ai_Bridge(),
	new Q28_Ai_Schema(),
	new Q28_Ai_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	$ready_adapter
);
$projection = $query->execute();
q28_ai_same( array( 'bridge', 'schema', 'queue', 'ai_routing' ), array_keys( $projection['checks'] ), 'Q28 did not append the AI routing diagnostic after the S01 checks.' );
q28_ai_same( '연결됨', $projection['checks']['ai_routing']['tasks']['worksheet_quality']['label'], 'Worksheet routing label drifted.' );
q28_ai_same( '연결됨', $projection['checks']['ai_routing']['tasks']['student_submission']['label'], 'Submission routing label drifted.' );
q28_ai_same( 'route-r1', $projection['checks']['ai_routing']['tasks']['worksheet_quality']['details']['routing_revision'], 'Safe routing revision was lost.' );
q28_ai_same( 'none', $projection['checks']['ai_routing']['next_action'], 'Ready AI routing exposed an unnecessary action.' );

$safe_json = json_encode( $projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
foreach ( array( 'provider-secret-marker', 'model-secret-marker', 'raw platform message', 'secret.example', 'authorization', 'Bearer hidden', 'provider_key', 'model_key' ) as $forbidden ) {
	q28_ai_assert( ! str_contains( strtolower( $safe_json ), strtolower( $forbidden ) ), 'Q28 AI routing leaked a forbidden value: ' . $forbidden );
}

$unroutable_gateway    = new Q28_Ai_Gateway(
	static fn ( string $task_key ): AddonAiTaskRouteDecisionV1 => Addon_Manifest::TASK_WORKSHEET_QUALITY === $task_key
		? q28_ai_decision( false, 'unassigned' )
		: q28_ai_decision( true ),
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => Addon_Manifest::TASK_WORKSHEET_QUALITY === $task_key
		? q28_ai_unavailable_snapshot( $task_key, false )
		: q28_ai_ready_snapshot( $task_key )
);
$unroutable            = new Read_System_Diagnostics(
	new Q28_Ai_Bridge(),
	new Q28_Ai_Schema(),
	new Q28_Ai_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	q28_ai_adapter( $unroutable_gateway )
);
$unroutable_projection = $unroutable->execute();
q28_ai_same( 'attention', $unroutable_projection['status'], 'An unassigned AI task did not raise attention.' );
q28_ai_same( '설정 필요', $unroutable_projection['checks']['ai_routing']['tasks']['worksheet_quality']['label'], 'Unassigned route label drifted.' );
q28_ai_same( array(), $unroutable_projection['checks']['ai_routing']['tasks']['worksheet_quality']['details'], 'Unassigned route exposed routing details.' );
q28_ai_same( 'open_platform_ai_assignments', $unroutable_projection['checks']['ai_routing']['next_action'], 'Unassigned route did not point to Platform AI assignments.' );

$transient_gateway = new Q28_Ai_Gateway(
	static fn ( string $task_key ): AddonAiTaskRouteDecisionV1 => Addon_Manifest::TASK_STUDENT_ASSESSMENT === $task_key
		? q28_ai_decision( false, 'unassigned' )
		: q28_ai_decision( true ),
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => Addon_Manifest::TASK_STUDENT_ASSESSMENT === $task_key
		? q28_ai_unavailable_snapshot( $task_key, true )
		: q28_ai_ready_snapshot( $task_key )
);
$transient_result  = q28_ai_adapter( $transient_gateway )->inspect();
q28_ai_same( 'unavailable', $transient_result->status(), 'A retryable platform routing problem did not fail closed.' );
q28_ai_same( '확인 불가', $transient_result->tasks()['student_submission']['label'], 'Transient route label drifted.' );
q28_ai_same( 'ai_routing_temporarily_unavailable', $transient_result->code(), 'Transient aggregate code drifted.' );
$transient_projection = ( new Read_System_Diagnostics(
	new Q28_Ai_Bridge(),
	new Q28_Ai_Schema(),
	new Q28_Ai_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	q28_ai_adapter( $transient_gateway )
) )->execute();
q28_ai_same( 'retry_ai_routing', $transient_projection['checks']['ai_routing']['next_action'], 'Transient route did not retain the retry action.' );

$stale_gateway = new Q28_Ai_Gateway(
	static fn ( string $task_key ): AddonAiTaskRouteDecisionV1 => q28_ai_decision( true, 'decision-r1' ),
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => Addon_Manifest::TASK_WORKSHEET_QUALITY === $task_key
		? q28_ai_ready_snapshot( $task_key, 'snapshot-r2' )
		: q28_ai_ready_snapshot( $task_key, 'decision-r1' )
);
$stale_result  = q28_ai_adapter( $stale_gateway )->inspect();
q28_ai_same( 'attention', $stale_result->status(), 'Routing revision drift did not raise attention.' );
q28_ai_same( '다시 확인 필요', $stale_result->tasks()['worksheet_quality']['label'], 'Stale route label drifted.' );
q28_ai_same( 'ai_routing_refresh_required', $stale_result->code(), 'Stale aggregate code drifted.' );

$wrong_task_gateway = new Q28_Ai_Gateway(
	static fn ( string $task_key ): AddonAiTaskRouteDecisionV1 => q28_ai_decision( true ),
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => Addon_Manifest::TASK_WORKSHEET_QUALITY === $task_key
		? q28_ai_ready_snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT )
		: q28_ai_ready_snapshot( $task_key )
);
q28_ai_same( 'routing_stale', q28_ai_adapter( $wrong_task_gateway )->inspect()->tasks()['worksheet_quality']['status'], 'A mismatched snapshot task key was accepted.' );

$throwing_gateway = new Q28_Ai_Gateway(
	static function ( string $task_key ): AddonAiTaskRouteDecisionV1 {
		if ( Addon_Manifest::TASK_WORKSHEET_QUALITY === $task_key ) {
			throw new RuntimeException( 'raw gateway exception secret-marker' );
		}
		return q28_ai_decision( true );
	},
	static fn ( string $task_key ): AddonAiTaskRoutingSnapshotV1 => q28_ai_ready_snapshot( $task_key )
);
$throwing_result  = q28_ai_adapter( $throwing_gateway )->inspect();
q28_ai_same( 'unavailable', $throwing_result->status(), 'A gateway exception did not fail closed.' );
q28_ai_same( 'runtime_unavailable', $throwing_result->tasks()['worksheet_quality']['status'], 'A gateway exception leaked its raw reason.' );
$throwing_projection = ( new Read_System_Diagnostics(
	new Q28_Ai_Bridge(),
	new Q28_Ai_Schema(),
	new Q28_Ai_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	q28_ai_adapter( $throwing_gateway )
) )->execute();
q28_ai_same( 'open_platform_operations', $throwing_projection['checks']['ai_routing']['next_action'], 'Runtime observation failure did not point to Platform status.' );
q28_ai_same( 0, $throwing_gateway->execute_calls, 'Exception handling invoked AI execution.' );

$cold_result = ( new Addon_Runtime_Ai_Routing_Diagnostics( new Addon_Runtime_Bridge() ) )->inspect();
q28_ai_same( 'unavailable', $cold_result->status(), 'A cold Runtime bridge was reported routable.' );
q28_ai_same( 'runtime_unavailable', $cold_result->tasks()['student_submission']['status'], 'Cold Runtime task status drifted.' );

$adapter_source = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Operations' . DIRECTORY_SEPARATOR . 'Diagnostics' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Addon_Runtime_Ai_Routing_Diagnostics.php' );
foreach ( array( '->canRoute(', '->snapshot(', 'AddonAiTaskGatewayV1', 'AddonApiProblemV1' ) as $required ) {
	q28_ai_assert( str_contains( $adapter_source, $required ), 'AI routing adapter lost a public Addon API marker: ' . $required );
}
foreach ( array( '->execute(', '->providerKey(', '->modelKey(', '->message(', '->details(', '->correlationId(', '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	q28_ai_assert( ! str_contains( $adapter_source, $forbidden ), 'AI routing adapter crossed a frozen safety boundary: ' . $forbidden );
}

$gateway_contract = (string) file_get_contents( $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AddonApi' . DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'AddonAiTaskGatewayV1.php' );
q28_ai_assert( str_contains( $gateway_contract, 'canRoute(string $taskKey): AddonAiTaskRouteDecisionV1' ), 'Platform canRoute public signature changed.' );
q28_ai_assert( str_contains( $gateway_contract, 'snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1' ), 'Platform snapshot public signature changed.' );
q28_ai_assert( str_contains( $gateway_contract, 'execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1' ), 'Platform execute public signature changed.' );

$composition_source = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php' );
q28_ai_assert( str_contains( $composition_source, 'new Addon_Runtime_Ai_Routing_Diagnostics( $runtime )' ), 'Composition Root did not inject the AI routing diagnostics adapter.' );

fwrite( STDOUT, "G5-IMP-05-S02 passed: Q28 safely reports both public AI task routes without provider data, raw errors, secrets, writes, or AI execution.\n" );
