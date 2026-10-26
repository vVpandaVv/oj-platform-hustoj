<?php
/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token;
use OJAI\Dashboard\Operations\Purge\Infrastructure\WordPress_Purge_Inventory;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Preview_Rest_Controller;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Preview_Rest_Module;
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
	 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
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
	 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
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

$GLOBALS['g5_imp08_s02_hooks']  = array();
$GLOBALS['g5_imp08_s02_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_imp08_s02_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_imp08_s02_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 imp08 s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 imp08 s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 imp08 s02 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s02_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': invalid input was accepted.' );
}

/**
 * G5 imp08 s02 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s02_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Purge_Failure $failure ) {
		g5_imp08_s02_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Db {
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
	 * Initialize g5 imp08 s02 db dependencies and state.
	 *
	 * @param Schema_Catalog    $catalog Catalog.
	 * @param array<string,int> $table_counts Table counts.
	 * @param int               $file_count File count.
	 * @param int               $blob_bytes Blob bytes.
	 * @param int               $hold_count Hold count.
	 * @param array<string,int> $jobs Jobs.
	 */
	public function __construct(
		private Schema_Catalog $catalog,
		private array $table_counts,
		private int $file_count = 3,
		private int $blob_bytes = 2048,
		private int $hold_count = 1,
		private array $jobs = array(
			'evaluation_runs' => 2,
			'export_jobs'     => 1,
			'zip_uploads'     => 0,
			'import_runs'     => 1,
			'purge_jobs'      => 0,
		)
	) {
	}

	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param string $output Output.
	 * @return array<int,array{metric:string,metric_key:string,metric_value:string}>
	 */
	public function get_results( string $query, string $output ): array {
		$this->queries[] = $query;
		$rows            = array();
		foreach ( $this->catalog->tables() as $table ) {
			$rows[] = array(
				'metric'       => 'owner_rows',
				'metric_key'   => $table->owner(),
				'metric_value' => (string) ( $this->table_counts[ $table->name() ] ?? 0 ),
			);
		}
		$rows[] = array(
			'metric'       => 'private_file_count',
			'metric_key'   => 'private_files',
			'metric_value' => (string) $this->file_count,
		);
		$rows[] = array(
			'metric'       => 'private_blob_bytes',
			'metric_key'   => 'private_files',
			'metric_value' => (string) $this->blob_bytes,
		);
		$rows[] = array(
			'metric'       => 'legal_hold_count',
			'metric_key'   => 'private_files',
			'metric_value' => (string) $this->hold_count,
		);
		foreach ( $this->jobs as $key => $count ) {
			$rows[] = array(
				'metric'       => 'incomplete_jobs',
				'metric_key'   => $key,
				'metric_value' => (string) $count,
			);
		}
		return $rows;
	}
}

/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Site implements Purge_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return '7';
	}
}

/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Initialize g5 imp08 s02 nonce dependencies and state.
	 *
	 * @param bool $valid Valid.
	 */
	public function __construct( private bool $valid = true ) {
	}
	/**
	 * Verify g5 imp08 s02 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return $this->valid && 'nonce-g5-imp08-s02' === $nonce;
	}
}

/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Policy implements Access_Policy_Port_Interface {
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
		if ( ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied( 403, 'request_integrity_invalid', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G5-IMP-08-S02 C71 read-only add-on purge preview contract.
 */
final class G5_Imp08_S02_Request {
	/**
	 * Initialize g5 imp08 s02 request dependencies and state.
	 *
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct(
		private array $body,
		private array $headers = array(),
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
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G5 imp08 s02 controller.
 *
 * @param Preview_Purge $service Service.
 * @param bool          $nonce_valid Nonce valid.
 * @return array{0:Purge_Preview_Rest_Controller,1:G5_Imp08_S02_Policy}
 */
function g5_imp08_s02_controller( Preview_Purge $service, bool $nonce_valid = true ): array {
	$policy       = new G5_Imp08_S02_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_Imp08_S02_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_Imp08_S02_Nonce( $nonce_valid ), $correlations ),
		$envelopes,
		$correlations
	);
	return array( new Purge_Preview_Rest_Controller( $permissions, $service, $envelopes, $correlations ), $policy );
}

$canonical = new Canonical_Json();
$catalog   = new Schema_Catalog();
g5_imp08_s02_same( 48, count( $catalog->table_names() ), 'C71 did not use exactly 48 target tables.' );
g5_imp08_s02_same( 10, count( $catalog->owner_counts() ), 'C71 owner catalog drifted from 10 owners.' );
foreach ( $catalog->table_names() as $table ) {
	g5_imp08_s02_assert( str_starts_with( $table, 'oj_ai_' ), 'C71 target table left the literal namespace.' );
	g5_imp08_s02_assert( ! str_starts_with( $table, 'wp_' ), 'C71 target table gained a WordPress prefix.' );
}

$table_counts = array_fill_keys( $catalog->table_names(), 2 );
$database     = new G5_Imp08_S02_Db( $catalog, $table_counts );
$inventory    = new WordPress_Purge_Inventory( $catalog, static fn () => $database );
$snapshot     = $inventory->inspect();
g5_imp08_s02_same( 96, $snapshot->total_row_count(), 'C71 total row count drifted.' );
foreach ( $catalog->owner_counts() as $owner => $table_count ) {
	g5_imp08_s02_same( $table_count * 2, $snapshot->owner_rows()[ $owner ], 'C71 owner row count drifted: ' . $owner );
}
g5_imp08_s02_same( 3, $snapshot->private_file_count(), 'C71 private file count drifted.' );
g5_imp08_s02_same( 2048, $snapshot->private_blob_bytes(), 'C71 private blob byte count drifted.' );
g5_imp08_s02_same( 1, $snapshot->legal_hold_count(), 'C71 legal hold count drifted.' );
g5_imp08_s02_same( 4, $snapshot->incomplete_job_total(), 'C71 incomplete job total drifted.' );
g5_imp08_s02_same( 1, count( $database->queries ), 'C71 did not use one read snapshot statement.' );
$query = $database->queries[0];
g5_imp08_s02_assert( str_starts_with( $query, 'SELECT ' ), 'C71 inventory statement is not read-only.' );
g5_imp08_s02_same( 56, substr_count( $query, 'SELECT ' ), 'C71 inventory statement lost a required aggregate.' );
foreach ( $catalog->table_names() as $table ) {
	g5_imp08_s02_assert( str_contains( $query, '`' . $table . '`' ), 'C71 inventory skipped a literal table: ' . $table );
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'ALTER ', 'DROP ', 'RENAME ', 'TRUNCATE ', 'CREATE ' ) as $forbidden ) {
	g5_imp08_s02_assert( ! str_contains( strtoupper( $query ), strtoupper( $forbidden ) ), 'C71 inventory crossed a read-only boundary: ' . $forbidden );
}

$clock                = static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-26 09:00:00', new DateTimeZone( 'UTC' ) );
$nonce                = static fn (): string => str_repeat( 'b', 32 );
$tokens               = new Signed_Purge_Preview_Token( $canonical, str_repeat( 'p', 64 ), $clock, $nonce );
$service              = new Preview_Purge( $inventory, $tokens, new G5_Imp08_S02_Site(), $catalog, $canonical );
$command              = new Preview_Purge_Command( 77, 'addon', hash( 'sha256', 'purge-preview-key-0001' ), 'corr-g5-imp08-s02-0001' );
$result               = $service->execute( $command );
$preview              = $result->preview();
$preview_without_hash = $preview;
unset( $preview_without_hash['preview_hash'] );
g5_imp08_s02_same( Preview_Purge::PREVIEW_PROFILE, $preview['profile'], 'C71 preview profile drifted.' );
g5_imp08_s02_same(
	array(
		'type'      => 'addon',
		'public_id' => null,
	),
	$preview['scope'],
	'C71 scope drifted.'
);
g5_imp08_s02_same( hash( 'sha256', $canonical->encode( $preview_without_hash ) ), $preview['preview_hash'], 'C71 preview hash is not canonical.' );
g5_imp08_s02_same( 48, $preview['table_count'], 'C71 response table count drifted.' );
g5_imp08_s02_same( 0, $result->mutation_count(), 'C71 reported a business mutation.' );
g5_imp08_s02_same( 0, $result->ledger_mutation_count(), 'C71 reported a purge ledger mutation.' );
g5_imp08_s02_same( 0, $result->platform_write_count(), 'C71 reported a platform write.' );
g5_imp08_s02_same( 0, $result->role_mutation_count(), 'C71 reported a role mutation.' );
g5_imp08_s02_same( 0, $result->file_mutation_count(), 'C71 reported a file mutation.' );
g5_imp08_s02_same( 0, $result->option_mutation_count(), 'C71 reported an option mutation.' );
g5_imp08_s02_assert( strlen( $result->preview_token() ) <= Signed_Purge_Preview_Token::MAXIMUM_TOKEN_BYTES, 'C71 preview token exceeded 4 KiB.' );

$token               = $tokens->decode( $result->preview_token() );
$expected_token_keys = array( 'purpose', 'site', 'scope_fingerprint', 'preview_hash', 'idempotency_key_hash', 'issued_at_utc', 'expires_at_utc', 'nonce' );
sort( $expected_token_keys, SORT_STRING );
g5_imp08_s02_same( $expected_token_keys, array_keys( $token ), 'C71 token gained raw records or PII fields.' );
g5_imp08_s02_same( $preview['scope_hash'], $token['scope_fingerprint'], 'C71 token is not bound to the scope hash.' );
g5_imp08_s02_same( $preview['preview_hash'], $token['preview_hash'], 'C71 token is not bound to the preview hash.' );
g5_imp08_s02_same( hash( 'sha256', 'purge-preview-key-0001' ), $token['idempotency_key_hash'], 'C71 token is not bound to the Idempotency-Key.' );
g5_imp08_s02_same( 1800, strtotime( $token['expires_at_utc'] . ' UTC' ) - strtotime( $token['issued_at_utc'] . ' UTC' ), 'C71 token lifetime is not 30 minutes.' );
$safe_json = json_encode( array( $preview, $token ), JSON_THROW_ON_ERROR );
foreach ( array( 'student@example', 'password', 'credential', 'storage_key', 'original_name', 'C:\\', '/var/', 's3://' ) as $forbidden ) {
	g5_imp08_s02_assert( ! str_contains( $safe_json, $forbidden ), 'C71 safe response leaked forbidden material: ' . $forbidden );
}
$tampered = substr( $result->preview_token(), 0, -1 ) . ( str_ends_with( $result->preview_token(), '0' ) ? '1' : '0' );
g5_imp08_s02_invalid( static fn () => $tokens->decode( $tampered ), 'C71 accepted a tampered token.' );
$expired_tokens = new Signed_Purge_Preview_Token(
	$canonical,
	str_repeat( 'p', 64 ),
	static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-26 09:30:00', new DateTimeZone( 'UTC' ) ),
	$nonce
);
g5_imp08_s02_failure( static fn () => $expired_tokens->verify( $result->preview_token() ), Purge_Failure::TOKEN_EXPIRED, 'C71 token did not expire at exactly 30 minutes.' );

$drift_counts                     = $table_counts;
$drift_counts['oj_ai_worksheets'] = 3;
$drift_database                   = new G5_Imp08_S02_Db( $catalog, $drift_counts );
$drift_result                     = ( new Preview_Purge(
	new WordPress_Purge_Inventory( $catalog, static fn () => $drift_database ),
	$tokens,
	new G5_Imp08_S02_Site(),
	$catalog,
	$canonical
) )->execute( $command );
g5_imp08_s02_assert( $preview['preview_hash'] !== $drift_result->preview()['preview_hash'], 'One target row change did not invalidate the C71 preview hash.' );

list( $controller, $policy ) = g5_imp08_s02_controller( $service );
$body                        = array( 'scope' => array( 'type' => 'addon' ) );
$headers                     = array(
	'X-WP-Nonce'      => 'nonce-g5-imp08-s02',
	'Idempotency-Key' => 'purge-preview-key-0001',
);
$request                     = new G5_Imp08_S02_Request( $body, $headers );
g5_imp08_s02_same( true, $controller->permissions_preview( $request ), 'C71 permission handoff failed.' );
g5_imp08_s02_same( Purge_Preview_Rest_Controller::ACTION_PREVIEW, $policy->request?->action_key(), 'C71 common policy action drifted.' );
g5_imp08_s02_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C71 manage_migrations gate drifted.' );
g5_imp08_s02_same( array(), $policy->request?->required_core_capabilities(), 'C71 preview incorrectly requires delete_plugins.' );
$response = $controller->preview( $request );
g5_imp08_s02_assert( $response instanceof WP_REST_Response, 'C71 did not return the common success envelope.' );
g5_imp08_s02_same( 200, $response->get_status(), 'C71 response status drifted.' );
$response_data = $response->get_data();
g5_imp08_s02_same( 'previewed', $response_data['data']['purge_preview']['state'], 'C71 response state drifted.' );
foreach ( array( 'mutation_count', 'ledger_mutation_count', 'platform_write_count', 'role_mutation_count', 'file_mutation_count', 'option_mutation_count' ) as $zero ) {
	g5_imp08_s02_same( 0, $response_data['meta'][ $zero ], 'C71 response lost zero-mutation evidence: ' . $zero );
}

$module = new Purge_Preview_Rest_Module( $controller );
$module->register();
$module->register();
g5_imp08_s02_same( 1, count( $GLOBALS['g5_imp08_s02_hooks']['rest_api_init'] ?? array() ), 'C71 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_imp08_s02_same( 1, count( $GLOBALS['g5_imp08_s02_routes'] ), 'C71 route registered twice.' );
g5_imp08_s02_same( 'oj-ai/v1', $GLOBALS['g5_imp08_s02_routes'][0][0], 'C71 namespace drifted.' );
g5_imp08_s02_same( '/purge-jobs/commands/preview', $GLOBALS['g5_imp08_s02_routes'][0][1], 'C71 path drifted.' );

$bad_body       = new G5_Imp08_S02_Request(
	array(
		'scope' => array( 'type' => 'addon' ),
		'extra' => true,
	),
	$headers
);
$bad_body_error = $controller->permissions_preview( $bad_body );
g5_imp08_s02_assert( $bad_body_error instanceof WP_Error, 'C71 accepted an over-posted body.' );
g5_imp08_s02_same( 422, $bad_body_error->get_error_data()['status'], 'C71 over-posted body status drifted.' );
$bad_scope_error = $controller->permissions_preview( new G5_Imp08_S02_Request( array( 'scope' => array( 'type' => 'worksheet' ) ), $headers ) );
g5_imp08_s02_assert( $bad_scope_error instanceof WP_Error, 'C71 accepted a partial purge scope.' );
g5_imp08_s02_same( 422, $bad_scope_error->get_error_data()['status'], 'C71 partial scope status drifted.' );

list( $missing_key_controller ) = g5_imp08_s02_controller( $service );
$missing_key_error              = $missing_key_controller->permissions_preview( new G5_Imp08_S02_Request( $body, array( 'X-WP-Nonce' => 'nonce-g5-imp08-s02' ) ) );
g5_imp08_s02_assert( $missing_key_error instanceof WP_Error, 'C71 accepted a missing Idempotency-Key.' );
g5_imp08_s02_same( 422, $missing_key_error->get_error_data()['status'], 'C71 missing Idempotency-Key status drifted.' );
list( $bad_nonce_controller ) = g5_imp08_s02_controller( $service, false );
$bad_nonce_error              = $bad_nonce_controller->permissions_preview( new G5_Imp08_S02_Request( $body, $headers ) );
g5_imp08_s02_assert( $bad_nonce_error instanceof WP_Error, 'C71 accepted an invalid standard nonce.' );
g5_imp08_s02_same( 403, $bad_nonce_error->get_error_data()['status'], 'C71 invalid nonce status drifted.' );

$runtime_files = array(
	'src/Operations/Purge/Application/Preview_Purge.php',
	'src/Operations/Purge/Infrastructure/WordPress_Purge_Inventory.php',
	'src/Operations/Purge/Infrastructure/Signed_Purge_Preview_Token.php',
	'src/Operations/Purge/Presentation/Purge_Preview_Rest_Controller.php',
);
foreach ( $runtime_files as $relative ) {
	$source = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_imp08_s02_assert( is_string( $source ), 'C71 runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'INSERT INTO', 'DELETE FROM', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_imp08_s02_assert( ! str_contains( $source, $forbidden ), 'C71 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
g5_imp08_s02_assert( str_contains( (string) $composition_source, 'Purge_Preview_Rest_Module' ), 'C71 production REST module is not composed.' );

fwrite( STDOUT, "G5-IMP-08-S02 passed: C71 exact 48-table owner inventory, private bytes, legal holds, incomplete jobs, 30-minute signed token, and zero mutations are frozen.\n" );
