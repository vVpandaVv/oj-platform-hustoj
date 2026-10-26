<?php
/**
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Prefixed_Legacy_Source_Inspector;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Controller;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Preview_Rest_Module;
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
	 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
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
	 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
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

$GLOBALS['g5_s01_hooks']  = array();
$GLOBALS['g5_s01_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_s01_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_s01_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s01 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s01_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s01 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': invalid input was accepted.' );
}

/**
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Legacy_Db {
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
	 * Logical by table.
	 *
	 * @var array<string,string>
	 */
	private array $logical_by_table = array();

	/**
	 * Initialize g5 s01 legacy db dependencies and state.
	 *
	 * @param string                                       $prefix Prefix.
	 * @param Legacy_Source_Profile_0_1_2                  $profile Profile.
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows.
	 * @param bool                                         $column_drift Column drift.
	 * @param bool                                         $missing_table Missing table.
	 */
	public function __construct(
		public string $prefix,
		private Legacy_Source_Profile_0_1_2 $profile,
		private array $rows,
		private bool $column_drift = false,
		private bool $missing_table = false
	) {
		foreach ( $profile->tables() as $logical => $table ) {
			$this->logical_by_table[ $prefix . $table['suffix'] ] = $logical;
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		$this->queries[] = $query;
		return 'EXISTS:' . (string) ( $args[0] ?? '' );
	}

	/**
	 * Get var.
	 *
	 * @param string $query Query.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		$table           = str_starts_with( $query, 'EXISTS:' ) ? substr( $query, 7 ) : '';
		if ( $this->missing_table && str_ends_with( $table, 'oj_ai_import_items' ) ) {
			return null;
		}
		return isset( $this->logical_by_table[ $table ] ) ? $table : null;
	}

	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param string $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $query, string $output ): array {
		$this->queries[] = $query;
		if ( 1 === preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/D', $query, $matches ) ) {
			$logical = $this->logical_by_table[ $matches[1] ] ?? '';
			$columns = '' === $logical ? array() : $this->profile->columns( $logical );
			if ( $this->column_drift && 'worksheet_templates' === $logical ) {
				array_pop( $columns );
			}
			return array_map( static fn ( string $column ): array => array( 'Field' => $column ), $columns );
		}
		if ( 1 === preg_match( '/^SELECT \* FROM `([^`]+)` ORDER BY `id` ASC LIMIT 250 OFFSET ([0-9]+)$/D', $query, $matches ) ) {
			$logical = $this->logical_by_table[ $matches[1] ] ?? '';
			return array_slice( $this->rows[ $logical ] ?? array(), (int) $matches[2], 250 );
		}
		throw new RuntimeException( 'Unexpected legacy read query: ' . $query );
	}
}

/**
 * G5 s01 row.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param string                      $logical Logical.
 * @param array<string,mixed>         $overrides Overrides.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_row( Legacy_Source_Profile_0_1_2 $profile, string $logical, array $overrides ): array {
	$row = array_fill_keys( $profile->columns( $logical ), null );
	foreach ( $overrides as $key => $value ) {
		if ( ! array_key_exists( $key, $row ) ) {
			throw new RuntimeException( 'Unknown fixture column: ' . $logical . '.' . $key );
		}
		$row[ $key ] = $value;
	}
	return $row;
}

/**
 * G5 s01 rows.
 *
 * @param Legacy_Source_Profile_0_1_2 $profile Profile.
 * @param string                      $worksheet_title Worksheet title.
 * @return array<string,array<int,array<string,mixed>>>
 */
function g5_s01_rows( Legacy_Source_Profile_0_1_2 $profile, string $worksheet_title = 'PRIVATE SOURCE ROW' ): array {
	return array(
		'academic_years'         => array(
			g5_s01_row(
				$profile,
				'academic_years',
				array(
					'id'    => '1',
					'label' => '2026',
				)
			),
		),
		'student_mappings'       => array(
			g5_s01_row(
				$profile,
				'student_mappings',
				array(
					'id'              => '2',
					'student_user_id' => '77',
				)
			),
		),
		'worksheet_templates'    => array(
			g5_s01_row(
				$profile,
				'worksheet_templates',
				array(
					'id'    => '3',
					'title' => $worksheet_title,
				)
			),
		),
		'submission_attachments' => array(
			g5_s01_row(
				$profile,
				'submission_attachments',
				array(
					'id'      => '4',
					'file_id' => '501',
				)
			),
		),
		'reports'                => array(
			g5_s01_row(
				$profile,
				'reports',
				array(
					'id'              => '5',
					'student_user_id' => '77',
				)
			),
		),
	);
}

/**
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Site implements Legacy_Migration_Site_Port_Interface {
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
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Initialize g5 s01 nonce dependencies and state.
	 *
	 * @param bool $valid Valid.
	 */
	public function __construct( private bool $valid = true ) {
	}
	/**
	 * Verify g5 s01 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return $this->valid && 'nonce-g5-imp07-s01' === $nonce;
	}
}

/**
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Policy implements Access_Policy_Port_Interface {
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
 * G5-IMP-07-S01 C61 mutation-free legacy migration preview contract.
 */
final class G5_S01_Request {
	/**
	 * Initialize g5 s01 request dependencies and state.
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
 * G5 s01 controller.
 *
 * @param Preview_Legacy_Migration $service Service.
 * @param bool                     $nonce_valid Nonce valid.
 * @return array{0:Legacy_Migration_Preview_Rest_Controller,1:G5_S01_Policy}
 */
function g5_s01_controller( Preview_Legacy_Migration $service, bool $nonce_valid = true ): array {
	$policy       = new G5_S01_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_S01_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_S01_Nonce( $nonce_valid ), $correlations ),
		$envelopes,
		$correlations
	);
	return array( new Legacy_Migration_Preview_Rest_Controller( $permissions, $service, $envelopes, $correlations ), $policy );
}

$canonical = new Canonical_Json();
$profile   = new Legacy_Source_Profile_0_1_2();
g5_s01_same( 35, count( $profile->tables() ), 'The 0.1.2 source profile did not freeze exactly 35 tables.' );
g5_s01_same( 'oj_ai_academic_years', $profile->tables()['academic_years']['suffix'], 'The first legacy table suffix drifted.' );
g5_s01_same( 'oj_ai_import_items', $profile->tables()['import_items']['suffix'], 'The last legacy table suffix drifted.' );
foreach ( $profile->tables() as $table ) {
	g5_s01_assert( str_starts_with( $table['suffix'], 'oj_ai_' ), 'A source profile table left the exact legacy namespace.' );
	g5_s01_assert( ! str_starts_with( $table['suffix'], 'wp_' ), 'The source profile hard-coded a WordPress prefix.' );
}

$blob_calls  = array();
$blob_reader = static function ( int $file_id ) use ( &$blob_calls ): array {
	$blob_calls[] = $file_id;
	return array(
		'state'      => 'ready',
		'size_bytes' => 7,
		'sha256'     => hash( 'sha256', 'bytes-501' ),
	);
};
$database    = new G5_S01_Legacy_Db( 'wp_', $profile, g5_s01_rows( $profile ) );
$inspector   = new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, static fn () => $database, $blob_reader );
$snapshot    = $inspector->inspect( Legacy_Source_Profile_0_1_2::PROFILE );
g5_s01_same( 35, $snapshot->table_count(), 'C61 source inspection lost an exact table.' );
g5_s01_same( 5, $snapshot->row_count(), 'C61 source row inventory drifted.' );
g5_s01_same( 1, $snapshot->blob_count(), 'C61 did not deduplicate legacy file IDs.' );
g5_s01_same( 7, $snapshot->blob_bytes(), 'C61 did not inspect actual legacy blob bytes.' );
g5_s01_same( array( 501 ), $blob_calls, 'C61 inspected an unexpected legacy blob.' );
g5_s01_same(
	array(
		'source_rows'          => 5,
		'migration_candidates' => 2,
		'excluded'             => 3,
		'conflicts'            => 0,
		'mapping_needed'       => 0,
	),
	$snapshot->counts(),
	'C61 safe count projection drifted.'
);
g5_s01_same( array(), $snapshot->mapping_manifest(), 'Unreferenced local mapping rows became executable mapping requirements.' );
foreach ( $snapshot->tables() as $table ) {
	g5_s01_assert( str_starts_with( $table['source_table'], 'wp_oj_ai_' ), 'C61 read a target or guessed source table.' );
}
foreach ( $database->queries as $query ) {
	foreach ( array( 'INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'ALTER ', 'DROP ', 'RENAME ', 'TRUNCATE ', 'CREATE ' ) as $forbidden ) {
		g5_s01_assert( ! str_contains( strtoupper( $query ), $forbidden ), 'C61 source inspection attempted a mutation: ' . $query );
	}
}

$drift_database = new G5_S01_Legacy_Db( 'wp_', $profile, g5_s01_rows( $profile, 'PRIVATE SOURCE ROW CHANGED' ) );
$drift_snapshot = ( new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, static fn () => $drift_database, $blob_reader ) )
	->inspect( Legacy_Source_Profile_0_1_2::PROFILE );
g5_s01_assert( $snapshot->source_fingerprint() !== $drift_snapshot->source_fingerprint(), 'One source row change did not change the source fingerprint.' );

g5_s01_failure(
	static fn () => ( new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, static fn () => new G5_S01_Legacy_Db( '', $profile, array() ), $blob_reader ) )->inspect( Legacy_Source_Profile_0_1_2::PROFILE ),
	Legacy_Migration_Failure::SOURCE_COLLISION,
	'C61 did not block an unprefixed source/target collision.'
);
g5_s01_failure(
	static fn () => ( new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, static fn () => new G5_S01_Legacy_Db( 'wp_', $profile, array(), true ), $blob_reader ) )->inspect( Legacy_Source_Profile_0_1_2::PROFILE ),
	Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH,
	'C61 accepted a drifted source column profile.'
);
g5_s01_failure(
	static fn () => $inspector->inspect( 'unknown_legacy_profile@9.9.9' ),
	Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED,
	'C61 accepted an unknown source profile.'
);

$clock             = static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) );
$nonce             = static fn (): string => str_repeat( 'a', 32 );
$tokens            = new Signed_Legacy_Preview_Token( $canonical, str_repeat( 's', 64 ), $clock, $nonce );
$service           = new Preview_Legacy_Migration( $inspector, $tokens, new G5_S01_Site(), $canonical );
$command           = new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, hash( 'sha256', 'preview-key-0001' ), 'corr-g5-imp07-s01-0001' );
$result            = $service->execute( $command );
$plan              = $result->plan();
$plan_without_hash = $plan;
unset( $plan_without_hash['plan_hash'] );
g5_s01_same( hash( 'sha256', $canonical->encode( $plan_without_hash ) ), $plan['plan_hash'], 'C61 plan hash is not canonical and deterministic.' );
g5_s01_same( Preview_Legacy_Migration::PLAN_PROFILE, $plan['profile'], 'C61 plan profile drifted.' );
g5_s01_same( array(), $plan['mapping_decisions'], 'C61 invented unapproved mapping decisions.' );
g5_s01_same( 0, $result->mutation_count(), 'C61 reported a business mutation.' );
g5_s01_same( 0, $result->ledger_mutation_count(), 'C61 reported an import ledger mutation.' );
g5_s01_same( 0, $result->platform_write_count(), 'C61 reported a platform write.' );
g5_s01_assert( strlen( $result->preview_token() ) <= Signed_Legacy_Preview_Token::MAXIMUM_TOKEN_BYTES, 'C61 preview token exceeded 4 KiB.' );

$envelope            = $tokens->decode( $result->preview_token() );
$expected_token_keys = array( 'purpose', 'site', 'source_fingerprint', 'plan_hash', 'idempotency_key_hash', 'issued_at_utc', 'expires_at_utc', 'nonce' );
sort( $expected_token_keys, SORT_STRING );
g5_s01_same( $expected_token_keys, array_keys( $envelope ), 'C61 preview token envelope gained source data.' );
g5_s01_same( $plan['plan_hash'], $envelope['plan_hash'], 'C61 preview token is not bound to the plan hash.' );
g5_s01_same( hash( 'sha256', 'preview-key-0001' ), $envelope['idempotency_key_hash'], 'C61 preview token is not bound to the Idempotency-Key.' );
g5_s01_same( 1800, strtotime( $envelope['expires_at_utc'] . ' UTC' ) - strtotime( $envelope['issued_at_utc'] . ' UTC' ), 'C61 preview token lifetime is not 30 minutes.' );
$safe_json = json_encode( array( $plan, $envelope ), JSON_THROW_ON_ERROR );
foreach ( array( 'PRIVATE SOURCE ROW', 'password', 'credential', 'C:\\', '/var/', 's3://' ) as $forbidden ) {
	g5_s01_assert( ! str_contains( $safe_json, $forbidden ), 'C61 safe projection leaked forbidden source material: ' . $forbidden );
}
$tampered = substr( $result->preview_token(), 0, -1 ) . ( str_ends_with( $result->preview_token(), '0' ) ? '1' : '0' );
g5_s01_invalid( static fn () => $tokens->decode( $tampered ), 'C61 accepted a tampered preview token.' );

$drift_service = new Preview_Legacy_Migration(
	new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, static fn () => $drift_database, $blob_reader ),
	$tokens,
	new G5_S01_Site(),
	$canonical
);
g5_s01_assert( $plan['plan_hash'] !== $drift_service->execute( $command )->plan()['plan_hash'], 'A source byte change did not invalidate the preview plan.' );

list( $controller, $policy ) = g5_s01_controller( $service );
$body                        = array( 'source' => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ) );
$headers                     = array(
	'X-WP-Nonce'      => 'nonce-g5-imp07-s01',
	'Idempotency-Key' => 'preview-key-0001',
);
$request                     = new G5_S01_Request( $body, $headers );
g5_s01_same( true, $controller->permissions_preview( $request ), 'C61 permission handoff failed.' );
g5_s01_same( Legacy_Migration_Preview_Rest_Controller::ACTION_PREVIEW, $policy->request?->action_key(), 'C61 common policy action drifted.' );
g5_s01_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C61 manage_migrations gate drifted.' );
$response = $controller->preview( $request );
g5_s01_assert( $response instanceof WP_REST_Response, 'C61 did not return the common REST success envelope.' );
g5_s01_same( 200, $response->get_status(), 'C61 preview status drifted.' );
g5_s01_same( 'previewed', $response->get_data()['data']['migration_preview']['state'], 'C61 response state drifted.' );
g5_s01_same( 0, $response->get_data()['meta']['mutation_count'], 'C61 response lost mutation 0.' );
g5_s01_same( 0, $response->get_data()['meta']['ledger_mutation_count'], 'C61 response lost ledger mutation 0.' );
g5_s01_same( 0, $response->get_data()['meta']['platform_write_count'], 'C61 response lost platform write 0.' );

$module = new Legacy_Migration_Preview_Rest_Module( $controller );
$module->register();
$module->register();
g5_s01_same( 1, count( $GLOBALS['g5_s01_hooks']['rest_api_init'] ?? array() ), 'C61 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_s01_same( 1, count( $GLOBALS['g5_s01_routes'] ), 'C61 REST route registered twice.' );
g5_s01_same( 'oj-ai/v1', $GLOBALS['g5_s01_routes'][0][0], 'C61 REST namespace drifted.' );
g5_s01_same( '/migration-runs/commands/preview', $GLOBALS['g5_s01_routes'][0][1], 'C61 REST path drifted.' );

$bad_body       = new G5_S01_Request(
	array(
		'source' => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
		'extra'  => true,
	),
	$headers
);
$bad_body_error = $controller->permissions_preview( $bad_body );
g5_s01_assert( $bad_body_error instanceof WP_Error, 'C61 accepted an over-posted body.' );
g5_s01_same( 422, $bad_body_error->get_error_data()['status'], 'C61 over-posted body status drifted.' );

list( $missing_key_controller ) = g5_s01_controller( $service );
$missing_key                    = new G5_S01_Request( $body, array( 'X-WP-Nonce' => 'nonce-g5-imp07-s01' ) );
$missing_key_error              = $missing_key_controller->permissions_preview( $missing_key );
g5_s01_assert( $missing_key_error instanceof WP_Error, 'C61 accepted a missing Idempotency-Key.' );
g5_s01_same( 422, $missing_key_error->get_error_data()['status'], 'C61 missing Idempotency-Key status drifted.' );

list( $bad_nonce_controller ) = g5_s01_controller( $service, false );
$bad_nonce_error              = $bad_nonce_controller->permissions_preview( new G5_S01_Request( $body, $headers ) );
g5_s01_assert( $bad_nonce_error instanceof WP_Error, 'C61 accepted an invalid standard nonce.' );
g5_s01_same( 403, $bad_nonce_error->get_error_data()['status'], 'C61 invalid nonce status drifted.' );

$runtime_files = array(
	'src/Operations/LegacyMigration/Application/Preview_Legacy_Migration.php',
	'src/Operations/LegacyMigration/Infrastructure/WordPress_Prefixed_Legacy_Source_Inspector.php',
	'src/Operations/LegacyMigration/Infrastructure/Signed_Legacy_Preview_Token.php',
	'src/Operations/LegacyMigration/Presentation/Legacy_Migration_Preview_Rest_Controller.php',
);
foreach ( $runtime_files as $relative ) {
	$source = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_s01_assert( is_string( $source ), 'C61 runtime source could not be read: ' . $relative );
	foreach ( array( 'wp_oj_ai_', '$wpdb->prefix', '$wpdb->base_prefix', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s01_assert( ! str_contains( $source, $forbidden ), 'C61 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}
$controller_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Presentation/Legacy_Migration_Preview_Rest_Controller.php' );
g5_s01_assert( ! str_contains( (string) $controller_source, 'oj_ai_import_runs' ), 'C61 preview writes or couples to the import run ledger.' );
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
g5_s01_assert( str_contains( (string) $composition_source, 'Legacy_Migration_Preview_Rest_Module' ), 'C61 production REST module is not composed.' );
g5_s01_assert( ! str_contains( (string) $composition_source, 'new Import_Module' ), 'The superseded import module became active.' );

fwrite( STDOUT, "G5-IMP-07-S01 passed: C61 exact legacy inventory, deterministic plan, 30-minute signed token, common policy, and zero mutations are frozen.\n" );
