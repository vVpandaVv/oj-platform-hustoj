<?php
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Contracts\Platform_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Repairer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Repair_Authorization;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Schema_Repair_Failure;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_Receipt;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_Receipt_Port_Interface;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use OJAI\Dashboard\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Controller;
use OJAI\Dashboard\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Module;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\External_Ai_Consent_Settings_Page_Handler;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Actor_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Schema_Recovery_Access_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild' . DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) || ! is_file( $platform_root . '/src/Kernel/Autoloader.php' ) ) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}
require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platform_root );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-06-S04 C75 forward-only schema repair contract.
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
	 * G5-IMP-06-S04 C75 forward-only schema repair contract.
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
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['g5_s04_options'] = array();
$GLOBALS['g5_s04_hooks']   = array();
$GLOBALS['g5_s04_routes']  = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param string $name Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['g5_s04_options'] ) ? $GLOBALS['g5_s04_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Add option.
	 *
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param string $deprecated Deprecated.
	 * @param bool   $autoload Autoload.
	 * @return bool
	 */
	function add_option( string $name, $value, string $deprecated = '', bool $autoload = false ): bool {
		if ( array_key_exists( $name, $GLOBALS['g5_s04_options'] ) ) {
			return false;
		}
		$GLOBALS['g5_s04_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Update option.
	 *
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param bool   $autoload Autoload.
	 * @return bool
	 */
	function update_option( string $name, $value, bool $autoload = false ): bool {
		$changed                            = ! array_key_exists( $name, $GLOBALS['g5_s04_options'] ) || $GLOBALS['g5_s04_options'][ $name ] !== $value;
		$GLOBALS['g5_s04_options'][ $name ] = $value;
		return $changed;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete option.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_option( string $name ): bool {
		$exists = array_key_exists( $name, $GLOBALS['g5_s04_options'] );
		unset( $GLOBALS['g5_s04_options'][ $name ] );
		return $exists;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_s04_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_s04_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

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
function g5_s04_same( $expected, $actual, string $message ): void {
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
	} catch ( Schema_Repair_Failure $failure ) {
		g5_s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ' No failure was thrown.' );
}

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_State implements Schema_Repair_State_Port_Interface {
	/**
	 * Initialize g5 s04 state dependencies and state.
	 *
	 * @param Schema_Repair_State $state State.
	 */
	public function __construct( public Schema_Repair_State $state ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Schema_Repair_State
	 */
	public function inspect(): Schema_Repair_State {
		return $this->state;
	}
}

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Receipt implements Schema_Repair_Receipt_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Results.
	 *
	 * @var array<string,Repair_Addon_Schema_Result>
	 */
	public array $results = array();
	/**
	 * Fail complete once.
	 *
	 * @var bool
	 */
	public bool $fail_complete_once = false;

	/**
	 * Claim.
	 *
	 * @param array $record Record.
	 * @return Repair_Addon_Schema_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim( array $record ): ?Repair_Addon_Schema_Result {
		$key = (string) $record['storage_key'];
		if ( isset( $this->records[ $key ] ) && ! hash_equals( (string) $this->records[ $key ]['request_digest'], (string) $record['request_digest'] ) ) {
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::IDEMPOTENCY_CONFLICT );
		}
		$this->records[ $key ] ??= $record;
		if ( isset( $this->results[ $key ] ) ) {
			return $this->results[ $key ]->as_replay();
		}
		$blocked = $this->records[ $key ]['failure_reason'] ?? null;
		if ( is_string( $blocked ) ) {
			throw Schema_Repair_Failure::because( $blocked );
		}
		return null;
	}

	/**
	 * Complete.
	 *
	 * @param array                      $record Record.
	 * @param Schema_Installation_Result $repair_result Repair result.
	 * @param string                     $completed_at_utc Completed at utc.
	 * @return Repair_Addon_Schema_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete( array $record, Schema_Installation_Result $repair_result, string $completed_at_utc ): Repair_Addon_Schema_Result {
		if ( $this->fail_complete_once ) {
			$this->fail_complete_once = false;
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		$key                   = (string) $record['storage_key'];
		$result                = new Repair_Addon_Schema_Result(
			'schema_repair_converged' === $repair_result->code() ? 'schema_repair_converged' : 'schema_repaired',
			(string) $record['expected_catalog_fingerprint'],
			$completed_at_utc,
			false,
			count( $repair_result->completed_tables() )
		);
		$this->results[ $key ] = $result;
		return $result;
	}

	/**
	 * Block.
	 *
	 * @param array  $record Record.
	 * @param string $reason Reason.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	public function block( array $record, string $reason, string $occurred_at_utc ): void {
		$key                   = (string) $record['storage_key'];
		$this->records[ $key ] = array_merge(
			$record,
			array(
				'failure_reason'   => $reason,
				'completed_at_utc' => $occurred_at_utc,
			)
		);
	}
}

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Repairer implements Schema_Repairer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * After.
	 *
	 * @var Closure|null
	 */
	public ?Closure $after = null;
	/**
	 * Initialize g5 s04 repairer dependencies and state.
	 *
	 * @param Schema_Installation_Result $result Result.
	 */
	public function __construct( public Schema_Installation_Result $result ) {
	}
	/**
	 * Repair.
	 *
	 * @param Schema_Repair_Authorization $authorization Authorization.
	 * @return Schema_Installation_Result
	 */
	public function repair( Schema_Repair_Authorization $authorization ): Schema_Installation_Result {
		++$this->calls;
		if ( $this->after instanceof Closure ) {
			( $this->after )();
		}
		return $this->result;
	}
}

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Readiness implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Results.
	 *
	 * @var array<int,Schema_Runtime_Readiness_Result|Throwable>
	 */
	public array $results;
	/**
	 * Initialize g5 s04 readiness dependencies and state.
	 *
	 * @param array<int,Schema_Runtime_Readiness_Result|Throwable> $results Results.
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}
	/**
	 * Verify g5 s04 readiness.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		$value = count( $this->results ) > 1 ? array_shift( $this->results ) : $this->results[0];
		if ( $value instanceof Throwable ) {
			throw $value;
		}
		return $value;
	}
}

/**
 * G5 s04 ready.
 *
 * @return Schema_Runtime_Readiness_Result
 */
function g5_s04_ready(): Schema_Runtime_Readiness_Result {
	return new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::READY, 'schema_runtime_ready' );
}

/**
 * G5 s04 unavailable.
 *
 * @return Schema_Runtime_Readiness_Result
 */
function g5_s04_unavailable(): Schema_Runtime_Readiness_Result {
	return new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::UNAVAILABLE, 'schema_runtime_unavailable' );
}

/**
 * G5 s04 command.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param string         $state_hash State hash.
 * @param string         $idempotency_hash Idempotency hash.
 * @param string         $backup_time Backup time.
 * @param string         $backup_hash Backup hash.
 * @return Repair_Addon_Schema_Command
 */
function g5_s04_command(
	Schema_Catalog $catalog,
	string $state_hash,
	string $idempotency_hash,
	string $backup_time = '2026-08-25 11:00:00',
	string $backup_hash = ''
): Repair_Addon_Schema_Command {
	return new Repair_Addon_Schema_Command(
		77,
		'schema-repair-command-0001',
		Installation_State::MAINTENANCE_REQUIRED,
		$catalog->fingerprint(),
		$state_hash,
		'' === $backup_hash ? str_repeat( 'c', 64 ) : $backup_hash,
		new DateTimeImmutable( $backup_time, new DateTimeZone( 'UTC' ) ),
		true,
		$idempotency_hash,
		'g5-s04-correlation-0001',
		new Canonical_Json()
	);
}

/**
 * G5 s04 service.
 *
 * @param G5_S04_State     $state State.
 * @param G5_S04_Receipt   $receipt Receipt.
 * @param G5_S04_Repairer  $repairer Repairer.
 * @param G5_S04_Readiness $readiness Readiness.
 * @param Schema_Catalog   $catalog Catalog.
 * @return Repair_Addon_Schema
 */
function g5_s04_service(
	G5_S04_State $state,
	G5_S04_Receipt $receipt,
	G5_S04_Repairer $repairer,
	G5_S04_Readiness $readiness,
	Schema_Catalog $catalog
): Repair_Addon_Schema {
	return new Repair_Addon_Schema(
		$state,
		$receipt,
		$repairer,
		$readiness,
		$catalog,
		static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) )
	);
}

$catalog    = new Schema_Catalog();
$state_hash = str_repeat( 'a', 64 );
$state      = new G5_S04_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $state_hash ) );
$receipt    = new G5_S04_Receipt();
$repairer   = new G5_S04_Repairer(
	new Schema_Installation_Result(
		Schema_Installation_Result::REPAIRED,
		'schema_repaired',
		'g5-s04-correlation-0001',
		array( 'oj_ai_audit_events', 'oj_ai_outbox_events' )
	)
);
$service    = g5_s04_service( $state, $receipt, $repairer, new G5_S04_Readiness( array( g5_s04_ready() ) ), $catalog );
$command    = g5_s04_command( $catalog, $state_hash, str_repeat( 'b', 64 ) );
$result     = $service->execute( $command );
g5_s04_same( 'schema_repaired', $result->code(), 'C75 success code drifted.' );
g5_s04_same( 2, $result->mutation_count(), 'C75 mutation count drifted.' );
g5_s04_same( 1, $repairer->calls, 'C75 did not invoke the existing forward repair runner exactly once.' );
g5_s04_assert( ! $result->replayed(), 'First C75 result was marked as a replay.' );
$stored = reset( $receipt->records );
g5_s04_assert( is_array( $stored ), 'C75 did not claim a bootstrap receipt.' );
g5_s04_same( Repair_Addon_Schema_Command::PROFILE, $stored['profile'], 'C75 receipt profile drifted.' );
g5_s04_assert( ! array_key_exists( 'path', $stored ) && ! array_key_exists( 'credential', $stored ), 'C75 stored a backup path or credential.' );

$replay = $service->execute( $command );
g5_s04_assert( $replay->replayed(), 'C75 identical retry did not replay the receipt.' );
g5_s04_same( 0, $replay->mutation_count(), 'C75 replay reported a mutation.' );
g5_s04_same( 1, $repairer->calls, 'C75 replay invoked the repair runner again.' );

$conflict = g5_s04_command( $catalog, $state_hash, str_repeat( 'b', 64 ), '2026-08-25 11:00:00', str_repeat( 'd', 64 ) );
g5_s04_failure(
	static fn () => $service->execute( $conflict ),
	Schema_Repair_Failure::IDEMPOTENCY_CONFLICT,
	'C75 reused an idempotency key for a different request.'
);

$stale_receipt  = new G5_S04_Receipt();
$stale_repairer = new G5_S04_Repairer( $repairer->result );
$stale_service  = g5_s04_service( $state, $stale_receipt, $stale_repairer, new G5_S04_Readiness( array( g5_s04_ready() ) ), $catalog );
g5_s04_failure(
	static fn () => $stale_service->execute( g5_s04_command( $catalog, str_repeat( '9', 64 ), str_repeat( 'e', 64 ) ) ),
	Schema_Repair_Failure::STATE_STALE,
	'C75 accepted a stale expected repair hash.'
);
g5_s04_same( 0, $stale_repairer->calls, 'C75 ran DDL after a stale-state conflict.' );

$backup_receipt  = new G5_S04_Receipt();
$backup_repairer = new G5_S04_Repairer( $repairer->result );
$backup_service  = g5_s04_service( $state, $backup_receipt, $backup_repairer, new G5_S04_Readiness( array( g5_s04_ready() ) ), $catalog );
g5_s04_failure(
	static fn () => $backup_service->execute( g5_s04_command( $catalog, $state_hash, str_repeat( 'f', 64 ), '2026-08-24 11:59:59' ) ),
	Schema_Repair_Failure::BACKUP_INVALID,
	'C75 accepted backup evidence older than 24 hours.'
);
g5_s04_same( 0, $backup_repairer->calls, 'Expired C75 backup evidence reached the runner.' );

$lock_receipt  = new G5_S04_Receipt();
$lock_repairer = new G5_S04_Repairer(
	new Schema_Installation_Result(
		Schema_Installation_Result::LOCK_UNAVAILABLE,
		'schema_lock_unavailable',
		'g5-s04-correlation-0001'
	)
);
$lock_service  = g5_s04_service( $state, $lock_receipt, $lock_repairer, new G5_S04_Readiness( array( g5_s04_unavailable() ) ), $catalog );
g5_s04_failure(
	static fn () => $lock_service->execute( g5_s04_command( $catalog, $state_hash, str_repeat( '1', 64 ) ) ),
	Schema_Repair_Failure::LOCK_UNAVAILABLE,
	'C75 lock contention did not remain retryable.'
);
g5_s04_assert( ! isset( reset( $lock_receipt->records )['failure_reason'] ), 'C75 lock contention permanently blocked the receipt.' );

$crash_state                       = new G5_S04_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $state_hash ) );
$crash_receipt                     = new G5_S04_Receipt();
$crash_receipt->fail_complete_once = true;
$crash_repairer                    = new G5_S04_Repairer( $repairer->result );
$crash_repairer->after             = static function () use ( $crash_state ): void {
	$crash_state->state = Schema_Repair_State::not_required();
};
$crash_service                     = g5_s04_service( $crash_state, $crash_receipt, $crash_repairer, new G5_S04_Readiness( array( g5_s04_ready() ) ), $catalog );
$crash_command                     = g5_s04_command( $catalog, $state_hash, str_repeat( '2', 64 ) );
g5_s04_failure(
	static fn () => $crash_service->execute( $crash_command ),
	Schema_Repair_Failure::STORAGE_UNAVAILABLE,
	'C75 did not surface a receipt-finalization interruption.'
);
$converged = $crash_service->execute( $crash_command );
g5_s04_same( 'schema_repair_converged', $converged->code(), 'C75 restart did not converge after repair completed before receipt finalization.' );
g5_s04_same( 1, $crash_repairer->calls, 'C75 convergence retry ran DDL twice.' );

$transient_state           = new G5_S04_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $state_hash ) );
$transient_receipt         = new G5_S04_Receipt();
$transient_repairer        = new G5_S04_Repairer( $repairer->result );
$transient_repairer->after = static function () use ( $transient_state ): void {
	$transient_state->state = Schema_Repair_State::not_required();
};
$transient_service         = g5_s04_service(
	$transient_state,
	$transient_receipt,
	$transient_repairer,
	new G5_S04_Readiness( array( g5_s04_unavailable(), g5_s04_ready() ) ),
	$catalog
);
$transient_command         = g5_s04_command( $catalog, $state_hash, str_repeat( '3', 64 ) );
g5_s04_failure(
	static fn () => $transient_service->execute( $transient_command ),
	Schema_Repair_Failure::POSTCONDITION_FAILED,
	'C75 transient postcondition failure did not remain retryable.'
);
g5_s04_assert( ! isset( reset( $transient_receipt->records )['failure_reason'] ), 'C75 transient postcondition failure blocked the receipt.' );
g5_s04_same( 'schema_repair_converged', $transient_service->execute( $transient_command )->code(), 'C75 did not converge after a transient postcondition failure.' );
g5_s04_same( 1, $transient_repairer->calls, 'C75 transient postcondition retry ran DDL twice.' );

try {
	new Repair_Addon_Schema_Command(
		77,
		'schema-repair-command-0002',
		Installation_State::MAINTENANCE_REQUIRED,
		$catalog->fingerprint(),
		$state_hash,
		str_repeat( '4', 64 ),
		new DateTimeImmutable( '2026-08-25 11:00:00', new DateTimeZone( 'UTC' ) ),
		false,
		str_repeat( '5', 64 ),
		'g5-s04-correlation-0002',
		new Canonical_Json()
	);
	throw new RuntimeException( 'C75 accepted a non-forward repair command.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Receipt_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,bool>
	 */
	public array $tables = array(
		'oj_ai_audit_events'  => false,
		'oj_ai_outbox_events' => false,
	);
	/**
	 * Audit.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $audit = array();
	/**
	 * Outbox.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $outbox = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			$sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $sql, 1 ) ?? '';
		}
		return $sql;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ) {
		if ( preg_match( "/TABLE_NAME = '([^']+)'/", $sql, $match ) ) {
			return true === ( $this->tables[ $match[1] ] ?? false ) ? $match[1] : null;
		}
		if ( str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$row = end( $this->audit );
			return is_array( $row ) ? $row['event_hash'] : null;
		}
		return null;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		if ( preg_match( "/idempotency_key = '([^']+)'/", $sql, $match ) ) {
			foreach ( $this->outbox as $row ) {
				if ( $row['idempotency_key'] === $match[1] ) {
					return $row;
				}
			}
		}
		return null;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		if ( 'oj_ai_audit_events' === $table ) {
			$this->audit[] = $data;
			return 1;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			$this->outbox[] = $data;
			return 1;
		}
		return false;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		return 1;
	}
}

$GLOBALS['g5_s04_options'] = array();
$receipt_db                = new G5_S04_Receipt_Database();
$durable                   = new WordPress_Schema_Repair_Receipt( new Canonical_Json(), new Public_Id_Generator(), $receipt_db );
$durable_record            = g5_s04_command( $catalog, $state_hash, str_repeat( '6', 64 ) )->receipt( '2026-08-25 12:00:00' );
g5_s04_same( null, $durable->claim( $durable_record ), 'C75 bootstrap receipt claim did not begin as pending.' );
g5_s04_same( 1, count( $GLOBALS['g5_s04_options'] ), 'C75 bootstrap receipt was not stored before repair tables became available.' );
$receipt_db->tables['oj_ai_audit_events']  = true;
$receipt_db->tables['oj_ai_outbox_events'] = true;
$durable_result                            = $durable->complete( $durable_record, $repairer->result, '2026-08-25 12:00:01' );
g5_s04_same( 1, count( $receipt_db->audit ), 'C75 did not append exactly one audit event.' );
g5_s04_same( 1, count( $receipt_db->outbox ), 'C75 did not append exactly one durable receipt event.' );
g5_s04_same( array(), $GLOBALS['g5_s04_options'], 'C75 did not remove the bootstrap receipt after commit.' );
g5_s04_same( 'operations.schema.repaired', $receipt_db->audit[0]['action_key'], 'C75 audit action drifted.' );
g5_s04_same( 'schema-repair:' . str_repeat( '6', 64 ), $receipt_db->outbox[0]['idempotency_key'], 'C75 durable receipt key drifted.' );
$durable_json = json_encode( array( $receipt_db->audit[0], $receipt_db->outbox[0] ), JSON_THROW_ON_ERROR );
foreach ( array( 'password', 'credential', 's3://', 'wp_oj_ai_' ) as $forbidden ) {
	g5_s04_assert( ! str_contains( strtolower( $durable_json ), $forbidden ), 'C75 durable evidence leaked forbidden material: ' . $forbidden );
}
$durable_replay = $durable->claim( $durable_record );
g5_s04_assert( $durable_replay instanceof Repair_Addon_Schema_Result && $durable_replay->replayed(), 'C75 durable receipt did not replay after bootstrap cleanup.' );
g5_s04_same( 0, $durable_replay->mutation_count(), 'C75 durable replay reported a mutation.' );

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Runtime implements Platform_Runtime_Readiness_Port_Interface {
	/**
	 * Initialize g5 s04 runtime dependencies and state.
	 *
	 * @param bool $ready Ready.
	 */
	public function __construct( private bool $ready ) {
	}
	/**
	 * Is ready.
	 *
	 * @return mixed
	 */
	public function is_ready() {
		return $this->ready;
	}
}
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Actors implements Actor_Authorization_Port_Interface {
	/**
	 * Grant.
	 *
	 * @var bool
	 */
	public bool $grant = true;
	/**
	 * Is active.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return bool
	 */
	public function is_active( int $actor_user_id ): bool {
		return true;
	}
	/**
	 * Has capability.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $wordpress_capability WordPress capability.
	 * @return bool
	 */
	public function has_capability( int $actor_user_id, string $wordpress_capability ): bool {
		return $this->grant;
	}
}
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Contexts implements Context_Authorization_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result {
		throw new RuntimeException( 'Unexpected Context authorization.' );
	}
}
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Objects implements Object_Authorization_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( int $actor_user_id, string $action_key, ?Context_Authorization_Result $context, Object_Scope $object ): Object_Authorization_Result {
		throw new RuntimeException( 'Unexpected object authorization.' );
	}
}

$actors           = new G5_S04_Actors();
$common_policy    = new Access_Policy( new G5_S04_Runtime( false ), $actors, new G5_S04_Contexts(), new G5_S04_Objects() );
$recovery_policy  = new Schema_Recovery_Access_Policy( $common_policy, new G5_S04_Runtime( true ) );
$recovery_request = new Access_Policy_Request(
	77,
	Access_Policy_Request::SHELL_CONSOLE,
	Access_Policy_Request::SURFACE_REST,
	'operations.schema.repair',
	array( Addon_Capabilities::MANAGE_MIGRATIONS ),
	null,
	null,
	true,
	'g5-s04-policy-0001'
);
g5_s04_same( 503, $common_policy->authorize( $recovery_request )->status(), 'Normal policy stopped failing closed while Runtime is unavailable.' );
$recovery_decision = $recovery_policy->authorize( $recovery_request );
g5_s04_assert( $recovery_decision->is_allowed(), 'Narrow C75 policy did not allow an authorized recovery action: ' . $recovery_decision->status() . ' ' . $recovery_decision->code() );
$other_request = new Access_Policy_Request(
	77,
	Access_Policy_Request::SHELL_CONSOLE,
	Access_Policy_Request::SURFACE_REST,
	'operations.diagnostics.read',
	array(),
	null,
	null,
	true,
	'g5-s04-policy-0002'
);
g5_s04_same( 403, $recovery_policy->authorize( $other_request )->status(), 'C75 recovery policy accepted an action outside its allowlist.' );

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Page_Policy implements Access_Policy_Port_Interface {
	/**
	 * Request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $request = null;
	/**
	 * Initialize g5 s04 page policy dependencies and state.
	 *
	 * @param bool $allowed Allowed.
	 */
	public function __construct( private bool $allowed ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->request = $request;
		return $this->allowed
			? Access_Policy_Decision::allowed( $request->correlation_id() )
			: Access_Policy_Decision::denied( 503, 'addon_runtime_not_ready', $request->correlation_id() );
	}
}

$page_policy          = new G5_S04_Page_Policy( false );
$page_recovery_policy = new G5_S04_Page_Policy( true );
$page_handler         = new External_Ai_Consent_Settings_Page_Handler(
	$page_policy,
	Addon_Manifest::CONSOLE_SETTINGS,
	$page_recovery_policy,
	new G5_S04_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $state_hash ) )
);
$page_response        = $page_handler->handle(
	new AddonPageRequestV1(
		Addon_Manifest::CONSOLE_SETTINGS,
		77,
		array(),
		array(),
		'ko_KR',
		'g5-s04-page-correlation-0001'
	)
);
g5_s04_same( 200, $page_response->status(), 'C75 recovery Page did not open after the normal Runtime 503.' );
$page_body = (string) $page_response->body();
foreach ( array( 'data-recovery-mode="true"', 'data-repair-available="true"', 'data-diagnostics-role="repair"', '백업 확인 메모', '앞으로만 복구' ) as $marker ) {
	g5_s04_assert( str_contains( $page_body, $marker ), 'C75 recovery Page lost marker: ' . $marker );
}
foreach ( array( '평가 정책', '기본 루브릭', '학생 제출 평가', 'AI 처리 승인' ) as $forbidden ) {
	g5_s04_assert( ! str_contains( $page_body, $forbidden ), 'C75 recovery Page exposed an unrelated settings workflow: ' . $forbidden );
}
g5_s04_same( 'operations.schema.repair.view', $page_recovery_policy->request?->action_key(), 'C75 recovery Page action drifted.' );
g5_s04_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $page_recovery_policy->request?->required_capabilities(), 'C75 recovery Page capability drifted.' );

/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Current_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify g5 s04 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'nonce-g5-s04' === $nonce;
	}
}
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Rest_Policy implements Access_Policy_Port_Interface {
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
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}
/**
 * G5-IMP-06-S04 C75 forward-only schema repair contract.
 */
final class G5_S04_Request {
	/**
	 * Initialize g5 s04 request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param string              $idempotency Idempotency.
	 */
	public function __construct( private array $body, private string $idempotency = 'schema-repair-request-0001' ) {
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
		return match ( strtolower( $name ) ) {
			'x-wp-nonce' => 'nonce-g5-s04',
			'idempotency-key' => $this->idempotency,
			default => '',
		};
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
		return $this->body;
	}
}

$rest_policy     = new G5_S04_Rest_Policy();
$correlations    = new Correlation_Id_Factory( static fn (): string => 'g5-s04-rest-correlation-0001' );
$envelopes       = new Rest_Envelope_Factory();
$handoff         = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new G5_S04_Current_Actor() ),
	new Rest_Request_Authorizer( $rest_policy, new G5_S04_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$rest_state_hash = str_repeat( '7', 64 );
$rest_state      = new G5_S04_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $rest_state_hash ) );
$rest_receipt    = new G5_S04_Receipt();
$rest_repairer   = new G5_S04_Repairer( $repairer->result );
$rest_service    = new Repair_Addon_Schema(
	$rest_state,
	$rest_receipt,
	$rest_repairer,
	new G5_S04_Readiness( array( g5_s04_ready() ) ),
	$catalog,
	static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) )
);
$controller      = new Schema_Repair_Rest_Controller( $handoff, $rest_service, new Canonical_Json(), $envelopes, $correlations );
$module          = new Schema_Repair_Rest_Module( $controller );
$module->register();
$module->register();
g5_s04_same( 1, count( $GLOBALS['g5_s04_hooks']['rest_api_init'] ?? array() ), 'C75 module registered twice.' );
( $GLOBALS['g5_s04_hooks']['rest_api_init'][0] )();
$controller->register_routes();
g5_s04_same( 1, count( $GLOBALS['g5_s04_routes'] ), 'C75 route registered twice.' );
g5_s04_same( '/operations/schema/commands/repair', $GLOBALS['g5_s04_routes'][0][1], 'C75 route drifted.' );
g5_s04_same( 'POST', $GLOBALS['g5_s04_routes'][0][2]['methods'], 'C75 route is not POST-only.' );

$body    = array(
	'command_id' => 'schema-repair-command-rest-0001',
	'expected'   => array(
		'installation_state'  => Installation_State::MAINTENANCE_REQUIRED,
		'catalog_fingerprint' => $catalog->fingerprint(),
		'repair_state_hash'   => $rest_state_hash,
	),
	'backup'     => array(
		'evidence_digest' => str_repeat( '8', 64 ),
		'verified_at_utc' => '2026-08-25 11:30:00',
	),
	'payload'    => array( 'forward_only' => true ),
);
$request = new G5_S04_Request( $body );
g5_s04_same( true, $controller->permissions_repair( $request ), 'C75 permission handoff failed.' );
g5_s04_same( 'operations.schema.repair', $rest_policy->request?->action_key(), 'C75 policy action drifted.' );
g5_s04_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $rest_policy->request?->required_capabilities(), 'C75 manage_migrations gate drifted.' );
$response = $controller->repair( $request );
g5_s04_assert( $response instanceof WP_REST_Response, 'C75 did not return the common success envelope.' );
g5_s04_same( 202, $response->get_status(), 'C75 success status drifted.' );
g5_s04_same( true, $response->get_data()['meta']['forward_only'], 'C75 response lost the forward-only marker.' );

$invalid_body          = $body;
$invalid_body['extra'] = true;
$invalid               = $controller->permissions_repair( new G5_S04_Request( $invalid_body, 'schema-repair-request-0002' ) );
g5_s04_assert( $invalid instanceof WP_Error, 'C75 accepted an unknown body field.' );
g5_s04_same( 422, $invalid->get_error_data()['status'], 'C75 malformed-body status drifted.' );

$runtime_files  = array(
	'src/Operations/SchemaRepair/Application/Repair_Addon_Schema.php',
	'src/Operations/SchemaRepair/Infrastructure/WordPress_Schema_Repair_Receipt.php',
	'src/Operations/SchemaRepair/Infrastructure/WordPress_Schema_Repair_State.php',
	'src/Operations/SchemaRepair/Presentation/Schema_Repair_Rest_Controller.php',
	'src/Core/Composition_Root.php',
);
$runtime_source = '';
foreach ( $runtime_files as $file ) {
	$runtime_source .= (string) file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file ) );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'DROP TABLE', 'function down(', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5_s04_assert( ! str_contains( $runtime_source, $forbidden ), 'C75 runtime contains a forbidden boundary: ' . $forbidden );
}
g5_s04_same( 1, substr_count( (string) file_get_contents( $root . '/src/Core/Composition_Root.php' ), 'new Access_Policy(' ), 'C75 created a second common Access_Policy.' );

fwrite( STDOUT, "G5-IMP-06-S04 passed: C75 strict forward repair, 24-hour backup evidence, single-policy recovery authorization, idempotent restart, durable audit/outbox receipt, and 202 REST contract.\n" );
