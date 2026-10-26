<?php
/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Batch_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Running_Legacy_Migration_Run;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Worker_Module;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Queue_Wakeup;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Batch_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Item_Reader_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Execution_Rest_Controller;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Execution_Rest_Module;
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
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
	 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
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

$GLOBALS['g5_s03e_hooks']   = array();
$GLOBALS['g5_s03e_routes']  = array();
$GLOBALS['g5_s03e_cleared'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_s03e_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_s03e_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Wp clear scheduled hook.
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	function wp_clear_scheduled_hook( string $hook ): int {
		$GLOBALS['g5_s03e_cleared'][] = $hook;
		return 1;
	}
}

require $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Contract'
	. DIRECTORY_SEPARATOR . 'legacy-migration-execution-start-g5-imp-07-s03.php';

/**
 * G5 s03e assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03e_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03e same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03e_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify g5 s03 e nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'nonce-g5-imp07-s03-e' === $nonce;
	}
}

/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Policy implements Access_Policy_Port_Interface {
	/**
	 * Request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $request = null;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->request = $request;
		return $request->request_integrity_verified()
			? Access_Policy_Decision::allowed( $request->correlation_id() )
			: Access_Policy_Decision::denied( 403, 'request_integrity_invalid', $request->correlation_id() );
	}
}

/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Request {
	/**
	 * Initialize g5 s03 e request dependencies and state.
	 *
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $url Url.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct(
		private array $body,
		private array $headers,
		private array $url,
		private array $query = array()
	) {
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'POST';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		return $this->headers[ $name ] ?? '';
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_params(): array {
		return $this->url;
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G5 s03e controller.
 *
 * @param object         $service Service.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:Legacy_Migration_Execution_Rest_Controller,1:G5_S03E_Policy}
 */
function g5_s03e_controller( object $service, Canonical_Json $canonical ): array {
	$policy       = new G5_S03E_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_S03E_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_S03E_Nonce(), $correlations ),
		$envelopes,
		$correlations
	);
	return array(
		new Legacy_Migration_Execution_Rest_Controller( $permissions, $service, $envelopes, $correlations, $canonical ),
		$policy,
	);
}

/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Batch_Repository implements Legacy_Migration_Batch_Repository_Port_Interface {
	/**
	 * Seen run id.
	 *
	 * @var string|null
	 */
	public ?string $seen_run_id = null;
	/**
	 * Running run.
	 *
	 * @param string $run_id Run id.
	 * @return Running_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function running_run( string $run_id ): Running_Legacy_Migration_Run {
		$this->seen_run_id = $run_id;
		throw new RuntimeException( 'stop after worker handoff' );
	}
	/**
	 * Commit batch.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param Legacy_Source_Item_Batch     $batch Batch.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return Legacy_Migration_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function commit_batch( Running_Legacy_Migration_Run $run, Legacy_Source_Item_Batch $batch, string $processed_at_utc ): Legacy_Migration_Batch_Result {
		throw new RuntimeException( 'unexpected batch commit' );
	}
}

/**
 * G5-IMP-07-S03-E C62 REST, composition, and async worker contract.
 */
final class G5_S03E_Source_Reader implements Legacy_Source_Item_Reader_Port_Interface {
	/**
	 * Read g5 s03 e source reader.
	 *
	 * @param string $source_profile Source profile.
	 * @param int    $table_index Table index.
	 * @param int    $after_source_id After source id.
	 * @param int    $next_item_no Next item no.
	 * @param int    $limit Maximum number of entries requested.
	 * @return Legacy_Source_Item_Batch
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( string $source_profile, int $table_index, int $after_source_id, int $next_item_no, int $limit ): Legacy_Source_Item_Batch {
		throw new RuntimeException( 'unexpected source read' );
	}
}

$canonical                                    = new Canonical_Json();
$rest_database                                = new G5_S03_Database();
$rest_snapshot                                = g5_s03_snapshot( 'rest-s03-e' );
$rest_database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $rest_snapshot, $canonical, 101 );
$rest_run                                     = $rest_database->tables['oj_ai_import_runs'][0];
list( $rest_service, , $rest_queue )          = g5_s03_service( $rest_database, $rest_snapshot, $canonical );
list( $controller, $policy )                  = g5_s03e_controller( $rest_service, $canonical );
$preview                                      = json_decode( (string) $rest_run['preview_json'], true, 64, JSON_THROW_ON_ERROR );
$body    = array(
	'command_id' => g5_s03_id(),
	'expected'   => array(
		'state'              => 'approved',
		'source_fingerprint' => (string) $rest_run['source_fingerprint'],
		'plan_hash'          => (string) $preview['plan']['plan_hash'],
	),
	'payload'    => array(),
);
$headers = array(
	'X-WP-Nonce'      => 'nonce-g5-imp07-s03-e',
	'Idempotency-Key' => 'legacy-execute-rest-key-0001',
);
$url     = array( 'run_id' => (string) $rest_run['public_id'] );
$request = new G5_S03E_Request( $body, $headers, $url );

g5_s03e_same( true, $controller->permissions_execute( $request ), 'C62 permission handoff failed.' );
g5_s03e_same( Legacy_Migration_Execution_Rest_Controller::ACTION_EXECUTE, $policy->request?->action_key(), 'C62 policy action drifted.' );
g5_s03e_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C62 capability gate drifted.' );
$first_response = $controller->execute( $request );
g5_s03e_assert( $first_response instanceof WP_REST_Response, 'C62 did not return the common success envelope.' );
g5_s03e_same( 202, $first_response->get_status(), 'First C62 REST response must be 202.' );
$first_data = $first_response->get_data();
g5_s03e_same( 'running', $first_data['data']['migration_run']['state'], 'C62 REST state drifted.' );
g5_s03e_same( 250, $first_data['data']['migration_run']['checkpoint']['batch_size'], 'C62 REST checkpoint batch size drifted.' );
g5_s03e_same( false, $first_data['meta']['replayed'], 'First C62 REST response was marked replay.' );
g5_s03e_same( 0, $first_data['meta']['business_mutation_count'], 'C62 REST reported a business mutation before worker execution.' );
g5_s03e_same( 0, $first_data['meta']['platform_write_count'], 'C62 REST reported a platform write.' );
g5_s03e_same( array( (string) $rest_run['public_id'] ), $rest_queue->runs, 'C62 REST did not enqueue the exact run.' );
$counts_after_first = $rest_database->counts();

g5_s03e_same( true, $controller->permissions_execute( $request ), 'C62 replay permission handoff failed.' );
$replay_response = $controller->execute( $request );
g5_s03e_same( 202, $replay_response->get_status(), 'C62 replay REST response must remain 202.' );
$replay_data = $replay_response->get_data();
g5_s03e_same( true, $replay_data['meta']['replayed'], 'C62 replay flag is false.' );
g5_s03e_same( $first_data['data'], $replay_data['data'], 'C62 replay changed the safe run projection.' );
g5_s03e_same( $counts_after_first, $rest_database->counts(), 'C62 replay created duplicate target, item, or ledger rows.' );
g5_s03e_same( array( (string) $rest_run['public_id'] ), $rest_queue->runs, 'C62 replay scheduled a duplicate wakeup.' );

$safe_json = json_encode( $first_data, JSON_THROW_ON_ERROR );
foreach ( array( 'wp_oj_ai_', 'source_table', 'private_path', 'secret' ) as $forbidden ) {
	g5_s03e_assert( ! str_contains( $safe_json, $forbidden ), 'C62 response exposed a forbidden source detail: ' . $forbidden );
}

$overposted          = $body;
$overposted['extra'] = true;
$overpost_error      = $controller->permissions_execute( new G5_S03E_Request( $overposted, $headers, $url ) );
g5_s03e_assert( $overpost_error instanceof WP_Error, 'C62 accepted an over-posted body.' );
g5_s03e_same( 422, $overpost_error->get_error_data()['status'], 'C62 over-post status drifted.' );
$path_error = $controller->permissions_execute( new G5_S03E_Request( $body, $headers, array( 'run_id' => 'bad-run' ) ) );
g5_s03e_assert( $path_error instanceof WP_Error, 'C62 accepted an invalid path ID.' );
g5_s03e_same( 422, $path_error->get_error_data()['status'], 'C62 invalid path status drifted.' );
$query_error = $controller->permissions_execute( new G5_S03E_Request( $body, $headers, $url, array( 'debug' => '1' ) ) );
g5_s03e_assert( $query_error instanceof WP_Error, 'C62 accepted query over-posting.' );
g5_s03e_same( 422, $query_error->get_error_data()['status'], 'C62 query over-post status drifted.' );

$missing_key_request = new G5_S03E_Request( $body, array( 'X-WP-Nonce' => 'nonce-g5-imp07-s03-e' ), $url );
$missing_key_error   = $controller->permissions_execute( $missing_key_request );
g5_s03e_assert( $missing_key_error instanceof WP_Error, 'C62 accepted a missing idempotency key.' );
g5_s03e_same( 422, $missing_key_error->get_error_data()['status'], 'C62 missing idempotency status drifted.' );

$module = new Legacy_Migration_Execution_Rest_Module( $controller );
$module->register();
$module->register();
g5_s03e_same( 1, count( $GLOBALS['g5_s03e_hooks']['rest_api_init'] ?? array() ), 'C62 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_s03e_same( 1, count( $GLOBALS['g5_s03e_routes'] ), 'C62 REST route registered twice.' );
g5_s03e_same( 'oj-ai/v1', $GLOBALS['g5_s03e_routes'][0][0], 'C62 REST namespace drifted.' );
g5_s03e_same( Legacy_Migration_Execution_Rest_Controller::ROUTE_EXECUTE, $GLOBALS['g5_s03e_routes'][0][1], 'C62 REST path drifted.' );

$batch_repository = new G5_S03E_Batch_Repository();
$batch            = new Process_Legacy_Migration_Batch(
	new G5_S03_Source( $rest_snapshot ),
	new G5_S03_Site(),
	new G5_S03E_Source_Reader(),
	$batch_repository,
	new G5_S03_Queue(),
	$canonical
);
$worker           = new Legacy_Migration_Worker_Module( $batch );
$worker->register();
$worker->register();
g5_s03e_same( 1, count( $GLOBALS['g5_s03e_hooks'][ WordPress_Legacy_Migration_Queue_Wakeup::HOOK ] ?? array() ), 'C62 worker hook registered twice.' );
$worker->run( (string) $rest_run['public_id'] );
g5_s03e_same( (string) $rest_run['public_id'], $batch_repository->seen_run_id, 'C62 worker did not pass the exact scheduled run ID.' );
$worker->run( 'bad-run' );
g5_s03e_same( (string) $rest_run['public_id'], $batch_repository->seen_run_id, 'C62 worker accepted an invalid run ID.' );
Legacy_Migration_Worker_Module::clear();
g5_s03e_same( array( WordPress_Legacy_Migration_Queue_Wakeup::HOOK ), $GLOBALS['g5_s03e_cleared'], 'C62 worker hook was not cleared on deactivation.' );

$composition  = (string) file_get_contents( $root . '/src/Core/Composition_Root.php' );
$deactivation = (string) file_get_contents( $root . '/src/Core/Deactivation.php' );
foreach ( array(
	'Legacy_Migration_Execution_Rest_Controller',
	'Legacy_Migration_Execution_Rest_Module',
	'WordPress_Legacy_Migration_Execution_Repository',
	'WordPress_Legacy_Migration_Batch_Factory::create',
	'Legacy_Migration_Worker_Module',
) as $marker ) {
	g5_s03e_assert( str_contains( $composition, $marker ), 'C62 composition marker is missing: ' . $marker );
}
g5_s03e_assert( str_contains( $deactivation, 'Legacy_Migration_Worker_Module::clear();' ), 'C62 deactivation cleanup is not composed.' );

$runtime_files = array(
	'src/Operations/LegacyMigration/Presentation/Legacy_Migration_Execution_Rest_Controller.php',
	'src/Operations/LegacyMigration/Presentation/Legacy_Migration_Execution_Rest_Module.php',
	'src/Operations/LegacyMigration/Infrastructure/Legacy_Migration_Worker_Module.php',
);
foreach ( $runtime_files as $relative ) {
	$source_code = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
		g5_s03e_assert( ! str_contains( $source_code, $forbidden ), 'C62 entrypoint crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}

fwrite( STDOUT, "G5-IMP-07-S03-E passed: strict C62 REST, first and replay 202 projections, exact async run handoff, composition, cleanup, and zero duplicate or platform writes are frozen.\n" );
