<?php
/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_System_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\Addon_Runtime_Bridge_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Evaluation_Queue_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Operations_Diagnostics_Rest_Controller;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Operations_Diagnostics_Rest_Module;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
	 */
	final class WP_REST_Response {
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}
		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct( private string $code, private string $message, private $data = array() ) {
		}
		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['q28_hooks']  = array();
$GLOBALS['q28_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['q28_hooks'][ $hook ][] = $callback;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['q28_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Q28 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q28 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Bridge implements Bridge_Diagnostics_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize q28 bridge dependencies and state.
	 *
	 * @param Bridge_Diagnostics_Result $result Result.
	 */
	public function __construct( public Bridge_Diagnostics_Result $result ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	public function inspect(): Bridge_Diagnostics_Result {
		++$this->calls;
		return $this->result;
	}
}

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Schema implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Initialize q28 schema dependencies and state.
	 *
	 * @param Schema_Runtime_Readiness_Result $result Result.
	 */
	public function __construct( public Schema_Runtime_Readiness_Result $result ) {
	}
	/**
	 * Verify q28 schema.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		return $this->result;
	}
}

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Queue implements Queue_Diagnostics_Port_Interface {
	/**
	 * Initialize q28 queue dependencies and state.
	 *
	 * @param Queue_Diagnostics_Result $result Result.
	 */
	public function __construct( public Queue_Diagnostics_Result $result ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 */
	public function inspect(): Queue_Diagnostics_Result {
		return $this->result;
	}
}

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Throwing_Queue implements Queue_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Queue_Diagnostics_Result {
		throw new RuntimeException( 'raw queue exception secret=hidden' );
	}
}

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Prepared sql.
	 *
	 * @var string
	 */
	public string $prepared_sql = '';
	/**
	 * Prepared values.
	 *
	 * @var array<int,mixed>
	 */
	public array $prepared_values = array();
	/**
	 * Initialize q28 database dependencies and state.
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
	public function prepare( string $sql, ...$values ): string {
		$this->prepared_sql    = $sql;
		$this->prepared_values = $values;
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		return $this->row;
	}
}

/**
 * Q28 zero row.
 *
 * @return array<string,string>
 */
function q28_zero_row(): array {
	return array(
		'pending_count'        => '0',
		'active_count'         => '0',
		'delayed_count'        => '0',
		'stale_lease_count'    => '0',
		'recent_failure_count' => '0',
	);
}

/**
 * Q28 schedule.
 *
 * @param int $missing_index Missing index.
 * @return Closure(string):int|false
 */
function q28_schedule( int $missing_index = -1 ): Closure {
	$index = 0;
	return static function ( string $hook ) use ( &$index, $missing_index ) {
		$current = $index++;
		return $current === $missing_index ? false : 1_777_111_200 + $current;
	};
}

$ready_database = new Q28_Database( q28_zero_row() );
$queue_adapter  = new WordPress_Evaluation_Queue_Diagnostics(
	static fn () => $ready_database,
	q28_schedule(),
	static fn (): string => '2026-08-25 12:00:00'
);
$ready_queue    = $queue_adapter->inspect();
q28_same( Queue_Diagnostics_Result::READY, $ready_queue->status(), 'A fully scheduled empty queue was not ready.' );
q28_same( 4, $ready_queue->metrics()['expected_workers'], 'Q28 did not cover all four AI evaluation workers.' );
q28_same( 4, $ready_queue->metrics()['scheduled_workers'], 'Q28 lost a recurring worker schedule.' );
q28_assert( str_contains( $ready_database->prepared_sql, 'FROM oj_ai_evaluation_runs' ), 'Q28 did not read the literal queue source of truth.' );
q28_assert( ! str_contains( $ready_database->prepared_sql, 'wp_oj_ai_' ), 'Q28 prefixed the literal queue table.' );
q28_assert( ! str_contains( $ready_database->prepared_sql, '$wpdb->prefix' ), 'Q28 builds a queue table from the WordPress prefix.' );
q28_assert( in_array( '2026-08-25 11:45:00', $ready_database->prepared_values, true ), 'Q28 did not apply the frozen 15-minute queue window.' );

$attention_row                         = q28_zero_row();
$attention_row['pending_count']        = '6';
$attention_row['active_count']         = '2';
$attention_row['delayed_count']        = '1';
$attention_row['stale_lease_count']    = '1';
$attention_row['recent_failure_count'] = '3';
$attention_queue                       = ( new WordPress_Evaluation_Queue_Diagnostics(
	static fn () => new Q28_Database( $attention_row ),
	q28_schedule(),
	static fn (): string => '2026-08-25 12:00:00'
) )->inspect();
q28_same( Queue_Diagnostics_Result::ATTENTION, $attention_queue->status(), 'Stale work did not require attention.' );
q28_same( 'queue_stale_lease_detected', $attention_queue->code(), 'Stale lease did not receive first diagnostic priority.' );
q28_same(
	array( 'queue_stale_lease_detected', 'queue_failure_threshold_reached', 'queue_backlog_delayed' ),
	$attention_queue->issue_codes(),
	'Q28 queue issue aggregation drifted.'
);

$inactive_queue = ( new WordPress_Evaluation_Queue_Diagnostics(
	static fn () => new Q28_Database( q28_zero_row() ),
	q28_schedule( 2 ),
	static fn (): string => '2026-08-25 12:00:00'
) )->inspect();
q28_same( Queue_Diagnostics_Result::UNAVAILABLE, $inactive_queue->status(), 'A missing recurring worker schedule was not unavailable.' );
q28_same( 'queue_schedule_inactive', $inactive_queue->code(), 'A missing worker schedule used an unstable code.' );
q28_same( 3, $inactive_queue->metrics()['scheduled_workers'], 'Q28 schedule count drifted.' );

$broken_database             = new Q28_Database( null );
$broken_database->last_error = 'raw database error with secret';
$broken_queue                = ( new WordPress_Evaluation_Queue_Diagnostics(
	static fn () => $broken_database,
	q28_schedule(),
	static fn (): string => '2026-08-25 12:00:00'
) )->inspect();
q28_same( Queue_Diagnostics_Result::UNAVAILABLE, $broken_queue->status(), 'Queue observation failure did not fail closed.' );
q28_same( 'queue_observation_failed', $broken_queue->code(), 'Queue failure leaked a raw storage reason.' );

$cold_bridge = ( new Addon_Runtime_Bridge_Diagnostics( new Addon_Runtime_Bridge() ) )->inspect();
q28_same( Bridge_Diagnostics_Result::UNAVAILABLE, $cold_bridge->status(), 'An unconnected bridge was reported ready.' );
q28_same( 'addon_runtime_not_ready', $cold_bridge->code(), 'The unconnected bridge code drifted.' );
q28_same( array(), $cold_bridge->details(), 'An unavailable bridge exposed Runtime details.' );

$bridge     = new Q28_Bridge( new Bridge_Diagnostics_Result( Bridge_Diagnostics_Result::READY, 'addon_runtime_ready', '2.0.0-dev.74', '1.1.0' ) );
$schema     = new Q28_Schema( new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::READY, 'schema_runtime_ready' ) );
$query      = new Read_System_Diagnostics( $bridge, $schema, new Q28_Queue( $ready_queue ), static fn (): string => '2026-08-25 12:00:00' );
$projection = $query->execute();
q28_same( Read_System_Diagnostics::PROFILE, $projection['profile'], 'Q28 profile drifted.' );
q28_same( 'ready', $projection['status'], 'Ready Q28 components did not produce a ready aggregate.' );
q28_same( '사용 준비됨', $projection['label'], 'Ready Q28 user label drifted.' );
q28_same( array( 'bridge', 'schema', 'queue' ), array_keys( $projection['checks'] ), 'S01 Q28 component order or scope drifted.' );
q28_same( 'none', $projection['checks']['bridge']['next_action'], 'Ready bridge exposed an unnecessary action.' );
q28_same( '2.0.0-dev.74', $projection['checks']['bridge']['details']['platform_version'], 'Safe platform version was lost.' );
q28_same( 4, $projection['checks']['queue']['metrics']['expected_workers'], 'Queue metrics were not projected.' );

$safe_json = json_encode( $projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
foreach ( array( 'secret', 'endpoint', 'provider_key', 'model_key', 'last_error', 'oj_ai_evaluation_runs', 'wp_oj_ai_' ) as $forbidden ) {
	q28_assert( ! str_contains( strtolower( $safe_json ), strtolower( $forbidden ) ), 'Q28 leaked a forbidden diagnostic field: ' . $forbidden );
}

$attention_projection = ( new Read_System_Diagnostics(
	$bridge,
	$schema,
	new Q28_Queue( $attention_queue ),
	static fn (): string => '2026-08-25 12:00:00'
) )->execute();
q28_same( 'attention', $attention_projection['status'], 'Queue attention did not raise the aggregate status.' );
q28_same( '확인 필요', $attention_projection['label'], 'Q28 attention label drifted.' );
q28_same( 'check_jobs', $attention_projection['checks']['queue']['next_action'], 'Queue attention did not project the safe next action.' );

$schema_unavailable     = new Q28_Schema(
	new Schema_Runtime_Readiness_Result(
		Schema_Runtime_Readiness_Result::UNAVAILABLE,
		'schema_catalog_drift',
		array( 'schema_checksum_mismatch' )
	)
);
$unavailable_projection = ( new Read_System_Diagnostics(
	$bridge,
	$schema_unavailable,
	new Q28_Throwing_Queue(),
	static fn (): string => '2026-08-25 12:00:00'
) )->execute();
q28_same( 'unavailable', $unavailable_projection['status'], 'Failed components did not close the Q28 aggregate.' );
q28_same( '사용할 수 없음', $unavailable_projection['label'], 'Unavailable Q28 label drifted.' );
q28_same( 'schema_checksum_mismatch', $unavailable_projection['checks']['schema']['issues'][0], 'Safe schema issue code was lost.' );
q28_same( 'queue_observation_failed', $unavailable_projection['checks']['queue']['code'], 'Thrown queue diagnostics leaked an exception.' );

/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Actor implements Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int {
		return 77;
	}
}
/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Verify q28 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		++$this->calls;
		return true;
	}
}
/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		return $this->allowed
			? Access_Policy_Decision::allowed( $request->correlation_id() )
			: Access_Policy_Decision::denied( 503, 'addon_runtime_not_ready', $request->correlation_id() );
	}
}
/**
 * G5-IMP-05-S01 Q28 Bridge, schema, and queue diagnostics contract.
 */
final class Q28_Request {
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function get_header( string $name ) {
		return '';
	}
}

$policy       = new Q28_Policy();
$nonce        = new Q28_Nonce();
$correlations = new Correlation_Id_Factory( static fn (): string => 'q28-correlation-0001' );
$envelopes    = new Rest_Envelope_Factory();
$handoff      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new Q28_Actor() ),
	new Rest_Request_Authorizer( $policy, $nonce, $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Operations_Diagnostics_Rest_Controller( $handoff, $query, $envelopes, $correlations );
$module       = new Operations_Diagnostics_Rest_Module( $controller );
$module->register();
$module->register();
q28_same( 1, count( $GLOBALS['q28_hooks']['rest_api_init'] ?? array() ), 'Q28 module registered the REST lifecycle more than once.' );
( $GLOBALS['q28_hooks']['rest_api_init'][0] )();
$controller->register_routes();
q28_same( 1, count( $GLOBALS['q28_routes'] ), 'Q28 route registered more than once.' );
q28_same( 'oj-ai/v1', $GLOBALS['q28_routes'][0][0], 'Q28 REST namespace drifted.' );
q28_same( '/diagnostics', $GLOBALS['q28_routes'][0][1], 'Q28 REST route drifted.' );
q28_same( 'GET', $GLOBALS['q28_routes'][0][2]['methods'], 'Q28 route is not read-only.' );

$request = new Q28_Request();
q28_same( true, $controller->permissions_check( $request ), 'Q28 Console common authorization was not handed off.' );
q28_assert( $policy->last_request instanceof Access_Policy_Request, 'Q28 did not call the common access policy.' );
q28_same( Access_Policy_Request::SHELL_CONSOLE, $policy->last_request->shell(), 'Q28 bypassed the Console shell policy.' );
q28_same( 'operations.diagnostics.read', $policy->last_request->action_key(), 'Q28 action key drifted.' );
q28_same( array(), $policy->last_request->required_capabilities(), 'Q28 added an unauthorized add-on capability beyond Console common.' );
q28_same( array(), $policy->last_request->required_core_capabilities(), 'Q28 added an explicit core capability beyond Console common.' );
q28_same( 0, $nonce->calls, 'A GET diagnostic route invoked the mutation nonce verifier.' );
$response = $controller->show( $request );
q28_assert( $response instanceof WP_REST_Response, 'Q28 did not return the common success envelope.' );
q28_same( 200, $response->get_status(), 'Q28 success status drifted.' );
q28_same( Read_System_Diagnostics::PROFILE, $response->get_data()['data']['profile'], 'Q28 REST response lost the diagnostic profile.' );
q28_same( array(), $response->get_data()['actions'], 'Q28 read-only response exposed a command action.' );

$policy->allowed = false;
$denied_request  = new Q28_Request();
$before_denial   = $bridge->calls;
$denied          = $controller->permissions_check( $denied_request );
q28_assert( $denied instanceof WP_Error, 'Q28 Runtime-unavailable authorization did not fail closed.' );
q28_same( 'oj_ai_runtime_unavailable', $denied->get_error_code(), 'Q28 Runtime denial code drifted.' );
q28_same( $before_denial, $bridge->calls, 'Q28 inspected the add-on bridge after common policy denial.' );

$runtime_files  = array(
	'src/Operations/Diagnostics/Application/Read_System_Diagnostics.php',
	'src/Operations/Diagnostics/Infrastructure/Addon_Runtime_Bridge_Diagnostics.php',
	'src/Operations/Diagnostics/Infrastructure/WordPress_Evaluation_Queue_Diagnostics.php',
	'src/Operations/Diagnostics/Presentation/Operations_Diagnostics_Rest_Controller.php',
);
$runtime_source = '';
foreach ( $runtime_files as $file ) {
	$runtime_source .= (string) file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file ) );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'AddonAiTaskGatewayV1', 'ai_tasks()->execute' ) as $forbidden ) {
	q28_assert( ! str_contains( $runtime_source, $forbidden ), 'Q28 runtime contains a forbidden boundary marker: ' . $forbidden );
}

fwrite( STDOUT, "G5-IMP-05-S01 passed: Q28 safely reports Bridge, schema, four-worker scheduling, backlog, stale leases, and recent failure accumulation without secrets or AI execution.\n" );
