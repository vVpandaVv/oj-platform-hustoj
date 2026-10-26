<?php
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 * honest cron-heartbeat freshness read (running/stale/never, never a
 * fabricated "in progress" state), the WordPress_Storage_Worker_Heartbeat
 * autoload-disabled option store (never throws, ignores malformed input),
 * the Storage_Operations_Detail_Rest_Controller permission gate and safe
 * envelope, and every storage *_Worker_Module recording its heartbeat on
 * every tick -- including a tick whose real work throws.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Contracts\Storage_Worker_Heartbeat_Port_Interface;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_Storage_Operations_Detail;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Storage_Worker_Heartbeat;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Storage_Operations_Detail_Rest_Controller;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Storage_Operations_Detail_Rest_Module;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Cleanup;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Scan;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Cleanup_Item;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Inspector;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Cleanup;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Scan;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Scan_Job;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Suggestion_Matcher;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
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
	 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
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

$GLOBALS['osd_hooks']    = array();
$GLOBALS['osd_routes']   = array();
$GLOBALS['osd_options']  = array();
$GLOBALS['osd_autoload'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['osd_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['osd_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Update option.
	 *
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	function update_option( string $name, $value, $autoload = true ): bool {
		if ( isset( $GLOBALS['osd_option_write_throws'] ) && $GLOBALS['osd_option_write_throws'] ) {
			throw new RuntimeException( 'Injected option write outage.' );
		}
		$GLOBALS['osd_options'][ $name ]  = $value;
		$GLOBALS['osd_autoload'][ $name ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param string $name Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	function get_option( string $name, $default = false ) {
		if ( isset( $GLOBALS['osd_option_read_throws'] ) && $GLOBALS['osd_option_read_throws'] ) {
			throw new RuntimeException( 'Injected option read outage.' );
		}
		return $GLOBALS['osd_options'][ $name ] ?? $default;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Osd assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function osd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Osd same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function osd_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

// --- Read_Storage_Operations_Detail: metrics passthrough and honest worker health. ---
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Storage_Port implements Storage_Diagnostics_Port_Interface {
	/**
	 * Initialize osd storage port dependencies and state.
	 *
	 * @param Storage_Diagnostics_Result $result Result.
	 */
	public function __construct( private Storage_Diagnostics_Result $result ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 */
	public function inspect(): Storage_Diagnostics_Result {
		return $this->result;
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Throwing_Storage_Port implements Storage_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Storage_Diagnostics_Result {
		throw new RuntimeException( 'Injected storage diagnostics outage.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Heartbeat_Fixture implements Storage_Worker_Heartbeat_Port_Interface {
	/**
	 * Values.
	 *
	 * @var array<string,?string>
	 */
	public array $values = array();
	/**
	 * Record.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 */
	public function record( string $worker_key, string $now_utc ): void {
		$this->values[ $worker_key ] = $now_utc;
	}
	/**
	 * Last heartbeats.
	 *
	 * @param array<int,string> $worker_keys Worker keys.
	 * @return array<string,?string>
	 */
	public function last_heartbeats( array $worker_keys ): array {
		$result = array();
		foreach ( $worker_keys as $key ) {
			$result[ $key ] = $this->values[ $key ] ?? null;
		}
		return $result;
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Throwing_Heartbeat implements Storage_Worker_Heartbeat_Port_Interface {
	/**
	 * Record.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 */
	public function record( string $worker_key, string $now_utc ): void {
	}
	/**
	 * Last heartbeats.
	 *
	 * @param array $worker_keys Worker keys.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function last_heartbeats( array $worker_keys ): array {
		throw new RuntimeException( 'Injected heartbeat read outage.' );
	}
}

/**
 * Osd ready path.
 *
 * @return Storage_Path_Diagnostics
 */
function osd_ready_path(): Storage_Path_Diagnostics {
	return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true );
}
/**
 * Matches Storage_Diagnostics_Result's internal ksort() of the metrics array.
 *
 * @return array
 */
function osd_metrics(): array {
	return array(
		'cleanup_due'       => 3,
		'delayed_cleanup'   => 0,
		'delayed_scans'     => 1,
		'expected_workers'  => 4,
		'metadata_issues'   => 0,
		'pending_scans'     => 2,
		'scheduled_workers' => 4,
	);
}

$ready_result       = new Storage_Diagnostics_Result( Storage_Diagnostics_Result::READY, 'storage_runtime_ready', osd_ready_path(), osd_metrics() );
$heartbeats         = new OSD_Heartbeat_Fixture();
$heartbeats->values = array(
	'attachment_scan'    => '2026-08-25 11:59:30',    // 30s before "now" -> running.
	'attachment_cleanup' => '2026-08-25 11:50:00',  // 10m before "now" -> stale.
	'zip_scan'           => null,                             // never recorded.
	// zip_cleanup intentionally omitted from the fixture -> also never recorded.
);
$detail     = new Read_Storage_Operations_Detail(
	new OSD_Storage_Port( $ready_result ),
	$heartbeats,
	static fn (): string => '2026-08-25 12:00:00'
);
$projection = $detail->execute();
osd_same( 'oj_ai.storage_operations_detail@1.0.0', $projection['profile'], 'Storage detail profile drifted.' );
osd_same( '2026-08-25 12:00:00', $projection['checked_at_utc'], 'Storage detail clock drifted.' );
osd_same( osd_metrics(), $projection['metrics'], 'Storage detail metrics were not a plain passthrough of Q28.' );
osd_same( 'unknown', $projection['scanner_status'], 'No scanner observation must not imply a responding daemon.' );
foreach ( array( 'responding', 'unavailable' ) as $scanner_status ) {
	$scanner_result     = new Storage_Diagnostics_Result(
		Storage_Diagnostics_Result::ATTENTION,
		'storage_scan_delayed',
		osd_ready_path(),
		osd_metrics(),
		array( 'storage_scan_delayed' ),
		$scanner_status
	);
	$scanner_projection = ( new Read_Storage_Operations_Detail(
		new OSD_Storage_Port( $scanner_result ),
		$heartbeats,
		static fn (): string => '2026-08-25 12:00:00'
	) )->execute();
	osd_same( $scanner_status, $scanner_projection['scanner_status'], 'Scanner observation was not preserved.' );
	osd_same( osd_metrics(), $scanner_projection['metrics'], 'Scanner status must not hide backlog metrics.' );
}
osd_same(
	array( 'attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup' ),
	array_keys( $projection['workers'] ),
	'Storage detail worker key order drifted.'
);
osd_same(
	array(
		'status'             => 'running',
		'last_heartbeat_utc' => '2026-08-25 11:59:30',
		'minutes_since'      => 0,
	),
	$projection['workers']['attachment_scan'],
	'A heartbeat within the fresh window was not reported as running.'
);
osd_same(
	array(
		'status'             => 'stale',
		'last_heartbeat_utc' => '2026-08-25 11:50:00',
		'minutes_since'      => 10,
	),
	$projection['workers']['attachment_cleanup'],
	'A heartbeat past the stale threshold was not reported as stale with its elapsed minutes.'
);
osd_same(
	array(
		'status'             => 'never',
		'last_heartbeat_utc' => null,
		'minutes_since'      => null,
	),
	$projection['workers']['zip_scan'],
	'A null heartbeat was not reported as never-recorded.'
);
osd_same(
	array(
		'status'             => 'never',
		'last_heartbeat_utc' => null,
		'minutes_since'      => null,
	),
	$projection['workers']['zip_cleanup'],
	'A missing heartbeat entry was not reported as never-recorded.'
);

// Exactly at the 2x-interval boundary (120s) must still read as running; just past it must read as stale.
$boundary_heartbeats         = new OSD_Heartbeat_Fixture();
$boundary_heartbeats->values = array(
	'attachment_scan'    => '2026-08-25 11:58:00',
	'attachment_cleanup' => '2026-08-25 11:57:59',
	'zip_scan'           => null,
	'zip_cleanup'        => null,
);
$boundary_projection         = ( new Read_Storage_Operations_Detail(
	new OSD_Storage_Port( $ready_result ),
	$boundary_heartbeats,
	static fn (): string => '2026-08-25 12:00:00'
) )->execute();
osd_same( 'running', $boundary_projection['workers']['attachment_scan']['status'], 'Exactly 2x the worker interval must still read as running.' );
osd_same( 'stale', $boundary_projection['workers']['attachment_cleanup']['status'], 'One second past 2x the worker interval must read as stale.' );

// A thrown storage diagnostic must fail closed to the safe unavailable projection, never raise.
$throwing_projection = ( new Read_Storage_Operations_Detail(
	new OSD_Throwing_Storage_Port(),
	$heartbeats,
	static fn (): string => '2026-08-25 12:00:00'
) )->execute();
osd_same( null, $throwing_projection['metrics'], 'A failed observation must not fabricate zero metrics.' );
osd_same( 'unavailable', $throwing_projection['status'], 'An observation outage lost its state.' );
osd_same( 'unknown', $throwing_projection['scanner_status'], 'An observation outage must not imply scanner availability.' );

// A thrown heartbeat read must fail closed to "never recorded" for every worker, never raise or leak the exception.
$throwing_heartbeat_projection = ( new Read_Storage_Operations_Detail(
	new OSD_Storage_Port( $ready_result ),
	new OSD_Throwing_Heartbeat(),
	static fn (): string => '2026-08-25 12:00:00'
) )->execute();
foreach ( array( 'attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup' ) as $key ) {
	osd_same( 'never', $throwing_heartbeat_projection['workers'][ $key ]['status'], 'A thrown heartbeat read did not fail closed for: ' . $key );
}

// --- WordPress_Storage_Worker_Heartbeat: autoload-disabled option store, never throws. ---
$GLOBALS['osd_options']  = array();
$GLOBALS['osd_autoload'] = array();
$store                   = new WordPress_Storage_Worker_Heartbeat();
$store->record( 'attachment_scan', '2026-08-25 12:00:00' );
osd_same( '2026-08-25 12:00:00', $GLOBALS['osd_options']['oj_ai_dashboard_storage_heartbeat_attachment_scan'], 'Heartbeat option value drifted.' );
osd_same( false, $GLOBALS['osd_autoload']['oj_ai_dashboard_storage_heartbeat_attachment_scan'], 'Heartbeat option must disable autoload.' );
osd_same(
	array(
		'attachment_scan'    => '2026-08-25 12:00:00',
		'attachment_cleanup' => null,
	),
	$store->last_heartbeats( array( 'attachment_scan', 'attachment_cleanup' ) ),
	'Heartbeat read-back drifted.'
);

// Malformed worker keys and timestamps must be silently ignored, never stored, never thrown.
$store->record( 'Invalid-Key', '2026-08-25 12:00:00' );
$store->record( 'attachment_scan', 'not-a-timestamp' );
osd_assert( ! array_key_exists( 'oj_ai_dashboard_storage_heartbeat_Invalid-Key', $GLOBALS['osd_options'] ), 'An invalid worker key was stored.' );
osd_same( '2026-08-25 12:00:00', $GLOBALS['osd_options']['oj_ai_dashboard_storage_heartbeat_attachment_scan'], 'A malformed timestamp overwrote a valid heartbeat.' );

// A write outage must never throw out of record() -- it is incidental instrumentation, never the worker's real job.
$GLOBALS['osd_option_write_throws'] = true;
$store->record( 'zip_scan', '2026-08-25 12:00:00' );
$GLOBALS['osd_option_write_throws'] = false;
osd_assert( true, 'record() must never throw on a write outage (unreachable if it did).' );

// A read outage must resolve to "never recorded" rather than raise.
$GLOBALS['osd_option_read_throws'] = true;
osd_same( array( 'attachment_scan' => null ), $store->last_heartbeats( array( 'attachment_scan' ) ), 'A read outage did not fail closed to never-recorded.' );
$GLOBALS['osd_option_read_throws'] = false;

// A malformed stored value (wrong shape) must read back as never-recorded, not raise or leak the raw value.
$GLOBALS['osd_options']['oj_ai_dashboard_storage_heartbeat_zip_cleanup'] = 12345;
osd_same( array( 'zip_cleanup' => null ), $store->last_heartbeats( array( 'zip_cleanup' ) ), 'A malformed stored heartbeat value was not treated as never-recorded.' );

// --- REST boundary: manage_policies gate, GET-only route, safe success envelope. ---
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Current_Actor implements Current_Actor_Port_Interface {
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
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify osd nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'osd-nonce' === $nonce;
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Policy implements Access_Policy_Port_Interface {
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
			: Access_Policy_Decision::denied( 403, 'addon_capability_denied', $request->correlation_id() );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Request {
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
	public function get_header( string $name ): string {
		return 'x-wp-nonce' === strtolower( $name ) ? 'osd-nonce' : '';
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return array();
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return array();
	}
}

$policy       = new OSD_Policy();
$correlations = new Correlation_Id_Factory( static fn (): string => 'osd-correlation-0001' );
$envelopes    = new Rest_Envelope_Factory();
$handoff      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new OSD_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new OSD_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Storage_Operations_Detail_Rest_Controller( $handoff, $detail, $envelopes, $correlations );
$module       = new Storage_Operations_Detail_Rest_Module( $controller );
$module->register();
$module->register();
osd_same( 1, count( $GLOBALS['osd_hooks']['rest_api_init'] ?? array() ), 'Storage detail module registered its REST lifecycle more than once.' );
( $GLOBALS['osd_hooks']['rest_api_init'][0] )();
$controller->register_routes();
osd_same( 1, count( $GLOBALS['osd_routes'] ), 'Storage detail route registered more than once.' );
osd_same( 'oj-ai/v1', $GLOBALS['osd_routes'][0][0], 'Storage detail REST namespace drifted.' );
osd_same( '/operations/storage/detail', $GLOBALS['osd_routes'][0][1], 'Storage detail REST route drifted.' );
osd_same( 'GET', $GLOBALS['osd_routes'][0][2]['methods'], 'Storage detail route is not GET-only.' );

$request = new OSD_Request();
osd_same( true, $controller->permissions_check( $request ), 'Storage detail permission handoff failed for an allowed actor.' );
osd_assert( $policy->last_request instanceof Access_Policy_Request, 'Storage detail controller did not call the common access policy.' );
osd_same( Access_Policy_Request::SHELL_CONSOLE, $policy->last_request->shell(), 'Storage detail bypassed the Console shell policy.' );
osd_same( 'operations.storage.detail.read', $policy->last_request->action_key(), 'Storage detail action key drifted.' );
osd_same( array( Addon_Capabilities::MANAGE_POLICIES ), $policy->last_request->required_capabilities(), 'Storage detail capability gate drifted from the settings Page and resume command gate.' );

$response = $controller->show( $request );
osd_assert( $response instanceof WP_REST_Response, 'Storage detail did not return the common success envelope.' );
osd_same( 200, $response->get_status(), 'Storage detail success status drifted.' );
$payload = $response->get_data();
osd_same( 'osd-correlation-0001', $payload['meta']['correlation_id'], 'Storage detail response lost its correlation ID.' );
osd_same(
	array( 'attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup' ),
	array_keys( $payload['data']['workers'] ),
	'Storage detail REST response worker keys drifted.'
);
osd_same( osd_metrics(), $payload['data']['metrics'], 'Storage detail REST response metrics drifted.' );

// A detail read failure must degrade to a safe 503 problem, never leak the raw exception.
$throwing_detail     = new Read_Storage_Operations_Detail( new OSD_Throwing_Storage_Port(), new OSD_Throwing_Heartbeat(), static fn (): string => 'not-a-timestamp' );
$throwing_controller = new Storage_Operations_Detail_Rest_Controller( $handoff, $throwing_detail, $envelopes, $correlations );
osd_same( true, $throwing_controller->permissions_check( $request ), 'Storage detail permission handoff failed unexpectedly.' );
$throwing_response = $throwing_controller->show( $request );
osd_assert( $throwing_response instanceof WP_Error, 'A failed detail read was not reported as a REST problem.' );
osd_same( 'storage_operations_detail_unavailable', $throwing_response->get_error_code(), 'Storage detail failure error code drifted.' );

// A denied capability must fail closed before the detail read ever runs.
$denied_policy          = new OSD_Policy();
$denied_policy->allowed = false;
$denied_handoff         = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new OSD_Current_Actor() ),
	new Rest_Request_Authorizer( $denied_policy, new OSD_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$denied_controller      = new Storage_Operations_Detail_Rest_Controller( $denied_handoff, $detail, $envelopes, $correlations );
$denied                 = $denied_controller->permissions_check( $request );
osd_assert( $denied instanceof WP_Error, 'A denied capability was not rejected.' );
osd_same( 'oj_ai_access_denied', $denied->get_error_code(), 'Denied storage detail error code drifted.' );

// --- Every storage *_Worker_Module records its heartbeat on every tick, including one whose real work throws. ---
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Heartbeat_Spy implements Storage_Worker_Heartbeat_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public array $calls = array();
	/**
	 * Record.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 */
	public function record( string $worker_key, string $now_utc ): void {
		$this->calls[] = array( $worker_key, $now_utc );
	}
	/**
	 * Last heartbeats.
	 *
	 * @param array $worker_keys Worker keys.
	 * @return array
	 */
	public function last_heartbeats( array $worker_keys ): array {
		return array();
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Idle_Scan_Repository implements Student_Attachment_Scan_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Scan_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Scan_Item {
		return null;
	}
	/**
	 * Complete.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $outcome Outcome.
	 * @param string|null                     $reason_code Reason code.
	 * @param string                          $audit_public_id Audit public id.
	 * @param string                          $outbox_public_id Outbox public id.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete( Submission_Attachment_Scan_Item $item, string $outcome, ?string $reason_code, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		throw new RuntimeException( 'OSD idle scan repository must never complete an item.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Throwing_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Claim calls.
	 *
	 * @var int
	 */
	public int $claim_calls = 0;
	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
		++$this->claim_calls;
		throw new RuntimeException( 'Injected OSD attachment cleanup outage.' );
	}
	/**
	 * Expire due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $audit_public_id Audit public id.
	 * @param string $outbox_public_id Outbox public id.
	 * @return Submission_Attachment_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function expire_due( string $now_utc, string $audit_public_id, string $outbox_public_id ): ?Submission_Attachment_Cleanup_Item {
		throw new RuntimeException( 'OSD throwing cleanup repository must never expire an item.' );
	}
	/**
	 * Purge claimed.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param callable                           $delete Delete.
	 * @param string                             $audit_public_id Audit public id.
	 * @param string                             $outbox_public_id Outbox public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function purge_claimed( Submission_Attachment_Cleanup_Item $item, callable $delete, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		throw new RuntimeException( 'OSD throwing cleanup repository must never purge an item.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Idle_Zip_Scan_Repository implements Submission_Zip_Scan_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Scan_Job|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Scan_Job {
		return null;
	}
	/**
	 * Complete.
	 *
	 * @param Submission_Zip_Scan_Job        $job Job.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param string                         $audit_public_id Audit public id.
	 * @param string                         $outbox_public_id Outbox public id.
	 * @param string                         $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete( Submission_Zip_Scan_Job $job, array $items, array $mapping, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		throw new RuntimeException( 'OSD idle ZIP scan repository must never complete a job.' );
	}
	/**
	 * Reject.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $reason_code Reason code.
	 * @param string                  $audit_public_id Audit public id.
	 * @param string                  $outbox_public_id Outbox public id.
	 * @param string                  $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function reject( Submission_Zip_Scan_Job $job, string $reason_code, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		throw new RuntimeException( 'OSD idle ZIP scan repository must never reject a job.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Idle_Zip_Cleanup_Repository implements Submission_Zip_Cleanup_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Cleanup_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Cleanup_Item {
		return null;
	}
	/**
	 * Purge claimed.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param callable                    $delete Delete.
	 * @param string                      $audit_public_id Audit public id.
	 * @param string                      $outbox_public_id Outbox public id.
	 * @param string                      $occurred_at_utc Occurred at utc.
	 * @return bool|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function purge_claimed( Submission_Zip_Cleanup_Item $item, callable $delete, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): ?bool {
		throw new RuntimeException( 'OSD idle ZIP cleanup repository must never purge an item.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Noop_File_Store implements Private_File_Store_Port_Interface {
	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		throw new RuntimeException( 'OSD noop file store must never quarantine a file.' );
	}
	/**
	 * Delete osd noop file store.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Unreachable_File_Read implements Private_File_Read_Port_Interface {
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		throw new RuntimeException( 'OSD unreachable file read must never be called.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Unreachable_File_Staging implements Private_File_Staging_Port_Interface {
	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		throw new RuntimeException( 'OSD unreachable file staging must never be called.' );
	}
	/**
	 * Insert quarantined.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_quarantined( array $record ): void {
		throw new RuntimeException( 'OSD unreachable file staging must never be called.' );
	}
	/**
	 * Mark purged.
	 *
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $purged_at_utc Purged at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function mark_purged( string $file_id, int $actor_user_id, string $purged_at_utc ): void {
		throw new RuntimeException( 'OSD unreachable file staging must never be called.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Unreachable_Malware implements Submission_Attachment_Malware_Scanner_Port_Interface {
	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		throw new RuntimeException( 'OSD unreachable malware scanner must never be called.' );
	}
}

$scan_spy    = new OSD_Heartbeat_Spy();
$scan_module = new Submission_Attachment_Scan_Worker_Module(
	new Run_Next_Submission_Attachment_Scan(
		new OSD_Idle_Scan_Repository(),
		new OSD_Unreachable_File_Read(),
		new OSD_Unreachable_Malware(),
		new Submission_Attachment_Content_Inspector(),
		new Public_Id_Generator()
	),
	$scan_spy
);
$scan_module->run();
osd_same( 1, count( $scan_spy->calls ), 'The attachment scan module did not record exactly one heartbeat per tick.' );
osd_same( 'attachment_scan', $scan_spy->calls[0][0], 'The attachment scan module heartbeat key drifted.' );
osd_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $scan_spy->calls[0][1] ), 'The attachment scan module heartbeat timestamp is not a plain UTC datetime.' );

$zip_scan_spy     = new OSD_Heartbeat_Spy();
$zip_scan_profile = new Submission_Zip_Upload_Profile();
$zip_scan_reader  = new Submission_Zip_Entry_Reader( $zip_scan_profile );
$zip_scan_module  = new Submission_Zip_Scan_Worker_Module(
	new Run_Next_Submission_Zip_Scan(
		new OSD_Idle_Zip_Scan_Repository(),
		new OSD_Unreachable_File_Read(),
		new OSD_Noop_File_Store(),
		new OSD_Unreachable_File_Staging(),
		new OSD_Unreachable_Malware(),
		$zip_scan_reader,
		new Submission_Zip_Item_Content_Inspector( $zip_scan_reader ),
		new Submission_Zip_Suggestion_Matcher(),
		new Public_Id_Generator()
	),
	$zip_scan_spy
);
$zip_scan_module->run();
osd_same( 1, count( $zip_scan_spy->calls ), 'The ZIP scan module did not record exactly one heartbeat per tick.' );
osd_same( 'zip_scan', $zip_scan_spy->calls[0][0], 'The ZIP scan module heartbeat key drifted.' );

$zip_cleanup_spy    = new OSD_Heartbeat_Spy();
$zip_cleanup_module = new Submission_Zip_Cleanup_Worker_Module(
	new Run_Next_Submission_Zip_Cleanup( new OSD_Idle_Zip_Cleanup_Repository(), new OSD_Noop_File_Store(), new Public_Id_Generator() ),
	$zip_cleanup_spy
);
$zip_cleanup_module->run();
osd_same( 1, count( $zip_cleanup_spy->calls ), 'The ZIP cleanup module did not record exactly one heartbeat per tick.' );
osd_same( 'zip_cleanup', $zip_cleanup_spy->calls[0][0], 'The ZIP cleanup module heartbeat key drifted.' );

// The decisive case: a worker whose real tick throws on its very first claim must still have recorded its heartbeat.
$throwing_cleanup_repository = new OSD_Throwing_Cleanup_Repository();
$cleanup_spy                 = new OSD_Heartbeat_Spy();
$cleanup_module              = new Submission_Attachment_Cleanup_Worker_Module(
	new Run_Next_Submission_Attachment_Cleanup( $throwing_cleanup_repository, new OSD_Noop_File_Store(), new Public_Id_Generator() ),
	$cleanup_spy
);
$cleanup_module->run();
osd_assert( $throwing_cleanup_repository->claim_calls >= 1, 'The throwing cleanup repository was never actually attempted.' );
osd_same( 1, count( $cleanup_spy->calls ), 'A tick whose real work threw did not still record its heartbeat.' );
osd_same( 'attachment_cleanup', $cleanup_spy->calls[0][0], 'The attachment cleanup module heartbeat key drifted.' );

// A heartbeat write failure must never block or fail the real tick underneath it.
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Recording_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Claimed.
	 *
	 * @var bool
	 */
	public bool $claimed = false;
	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
		$this->claimed = true;
		return null;
	}
	/**
	 * Expire due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $audit_public_id Audit public id.
	 * @param string $outbox_public_id Outbox public id.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function expire_due( string $now_utc, string $audit_public_id, string $outbox_public_id ): ?Submission_Attachment_Cleanup_Item {
		return null;
	}
	/**
	 * Purge claimed.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param callable                           $delete Delete.
	 * @param string                             $audit_public_id Audit public id.
	 * @param string                             $outbox_public_id Outbox public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function purge_claimed( Submission_Attachment_Cleanup_Item $item, callable $delete, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		throw new RuntimeException( 'OSD recording cleanup repository must never purge an item.' );
	}
}
/**
 * Storage operations detail Page contract: Read_Storage_Operations_Detail's
 */
final class OSD_Throwing_Heartbeat_Write implements Storage_Worker_Heartbeat_Port_Interface {
	/**
	 * Record.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function record( string $worker_key, string $now_utc ): void {
		throw new RuntimeException( 'Injected heartbeat write outage.' );
	}
	/**
	 * Last heartbeats.
	 *
	 * @param array $worker_keys Worker keys.
	 * @return array
	 */
	public function last_heartbeats( array $worker_keys ): array {
		return array();
	}
}
$resilient_repository = new OSD_Recording_Cleanup_Repository();
$resilient_module     = new Submission_Attachment_Cleanup_Worker_Module(
	new Run_Next_Submission_Attachment_Cleanup( $resilient_repository, new OSD_Noop_File_Store(), new Public_Id_Generator() ),
	new OSD_Throwing_Heartbeat_Write()
);
$resilient_module->run();
osd_same( true, $resilient_repository->claimed, 'A heartbeat write failure blocked the real worker tick underneath it.' );

$export_repository = new class() implements \OJAI\Dashboard\Reporting\Export\Port\Export_Cleanup_Repository_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Purge next.
	 *
	 * @param string   $now_utc Now utc.
	 * @param string   $retry_before_utc Retry before utc.
	 * @param callable $delete Delete.
	 * @param string   $audit_id Audit id.
	 * @param string   $outbox_id Outbox id.
	 * @return string
	 */
	public function purge_next( string $now_utc, string $retry_before_utc, callable $delete, string $audit_id, string $outbox_id ): string {
		++$this->calls;
		return 'idle';
	}
};
$export_run        = new \OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup( $export_repository, new OSD_Noop_File_Store(), new Public_Id_Generator() );
$export_spy        = new OSD_Heartbeat_Spy();
$export_module     = new \OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module( $export_run, $export_spy );
$export_module->run();
osd_same( 1, $export_repository->calls, 'Export cron did not execute its cleanup worker.' );
osd_same( 'export_cleanup', $export_spy->calls[0][0], 'Export cleanup heartbeat key drifted.' );
( new \OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module( $export_run, new OSD_Throwing_Heartbeat_Write() ) )->run();
osd_same( 2, $export_repository->calls, 'Heartbeat failure blocked export cleanup.' );
osd_same( 60, $export_module->cron_schedules( array() )[ $export_module::SCHEDULE ]['interval'], 'Export cleanup cron interval drifted.' );
fwrite( STDOUT, "Operations storage detail passed: five worker heartbeats, failure isolation, metrics, capability gates, no private file identity.\n" );
