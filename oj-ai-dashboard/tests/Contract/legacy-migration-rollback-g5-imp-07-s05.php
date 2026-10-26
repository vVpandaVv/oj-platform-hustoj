<?php
/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Rollback_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Rollback_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Controller;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Rollback_Rest_Module;
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
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
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
	 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
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

$GLOBALS['g5_s05_hooks']  = array();
$GLOBALS['g5_s05_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_s05_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_s05_routes'][] = array( $namespace, $route, $arguments );
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
function g5_s05_same( $expected, $actual, string $message ): void {
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
		$callback(); } catch ( Legacy_Migration_Failure $failure ) {
		g5_s05_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s05 id.
 *
 * @return string
 */
function g5_s05_id(): string {
	static $ids = null;
	$ids      ??= new Public_Id_Generator();
	return $ids->create()->value();
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Site implements Legacy_Migration_Site_Port_Interface {
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
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Memory_Repository implements Legacy_Migration_Rollback_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Stored.
	 *
	 * @var Rollback_Legacy_Migration_Result|null
	 */
	public ?Rollback_Legacy_Migration_Result $stored = null;
	/**
	 * Digest.
	 *
	 * @var string|null
	 */
	public ?string $digest = null;
	/**
	 * Failure.
	 *
	 * @var string|null
	 */
	public ?string $failure = null;

	/**
	 * Compensated receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Rollback_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function compensated_receipt( string $storage_key, string $request_digest ): ?Rollback_Legacy_Migration_Result {
		unset( $storage_key );
		if ( null !== $this->failure ) {
			throw Legacy_Migration_Failure::because( $this->failure );
		}
		if ( null === $this->stored ) {
			return null;
		}
		if ( ! hash_equals( (string) $this->digest, $request_digest ) ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT );
		}
		return new Rollback_Legacy_Migration_Result(
			$this->stored->run_id(),
			'compensated',
			$this->stored->checkpoint_hash(),
			$this->stored->item_total(),
			$this->stored->success_total(),
			$this->stored->failure_total(),
			$this->stored->skipped_total(),
			$this->stored->compensated_target_count(),
			$this->stored->compensated_at_utc(),
			true
		);
	}

	/**
	 * Compensate.
	 *
	 * @param array $record Record.
	 * @return Rollback_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function compensate( array $record ): Rollback_Legacy_Migration_Result {
		if ( null !== $this->failure ) {
			throw Legacy_Migration_Failure::because( $this->failure );
		}
		$this->record = $record;
		$this->digest = (string) $record['request_digest'];
		$this->stored = new Rollback_Legacy_Migration_Result(
			(string) $record['run_public_id'],
			'compensated',
			hash( 'sha256', 'compensated-checkpoint' ),
			4,
			3,
			1,
			0,
			3,
			(string) $record['compensated_at_utc'],
			false
		);
		return $this->stored;
	}
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify g5 s05 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'nonce-g5-imp07-s05' === $nonce;
	}
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Policy implements Access_Policy_Port_Interface {
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
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Request {
	/**
	 * Initialize g5 s05 request dependencies and state.
	 *
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $url Url.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct( private array $body, private array $headers, private array $url, private array $query = array() ) {
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
 * G5 s05 controller.
 *
 * @param Rollback_Legacy_Migration $service Service.
 * @param Canonical_Json            $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:Legacy_Migration_Rollback_Rest_Controller,1:G5_S05_Policy}
 */
function g5_s05_controller( Rollback_Legacy_Migration $service, Canonical_Json $canonical ): array {
	$policy       = new G5_S05_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_S05_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_S05_Nonce(), $correlations ),
		$envelopes,
		$correlations
	);
	return array( new Legacy_Migration_Rollback_Rest_Controller( $permissions, $service, $envelopes, $correlations, $canonical ), $policy );
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Deleted.
	 *
	 * @var array<int,string>
	 */
	public array $deleted = array();
	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		unset( $source_path, $storage_key, $sha256 );
		return false;
	}
	/**
	 * Delete g5 s05 private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		$this->deleted[] = $storage_key;
	}
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Probe_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Affected.
	 *
	 * @var int
	 */
	public int $affected = 1;
	/**
	 * Queries.
	 *
	 * @var array<int,array{sql:string,values:array<int,mixed>}>
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
		return 'G5S05:' . base64_encode( serialize( array( $sql, $values ) ) );
	}
	/**
	 * Query.
	 *
	 * @param string $prepared Prepared.
	 * @return int
	 */
	public function query( string $prepared ): int {
		list( $sql, $values ) = $this->decode( $prepared );
		$this->queries[]      = array(
			'sql'    => $sql,
			'values' => $values,
		);
		return $this->affected;
	}
	/**
	 * Decode g5 s05 probe database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S05:' ) ) {
			return array( $prepared, array() );
		}
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		return is_array( $value ) ? $value : array( '', array() );
	}
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Lock implements Schema_Lock_Port_Interface {
	/**
	 * Events.
	 *
	 * @var array<int,string>
	 */
	public array $events = array();
	/**
	 * Acquire.
	 *
	 * @param string $name Name.
	 * @param int    $timeout_seconds Timeout seconds.
	 * @return bool
	 */
	public function acquire( string $name, int $timeout_seconds ): bool {
		$this->events[] = 'acquire:' . $name . ':' . $timeout_seconds;
		return true;
	}
	/**
	 * Release.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function release( string $name ): void {
		$this->events[] = 'release:' . $name;
	}
}

/**
 * G5-IMP-07-S05 C63 unchanged-target compensation contract.
 */
final class G5_S05_Empty_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Write sql.
	 *
	 * @var array<int,string>
	 */
	public array $write_sql = array();
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Initialize g5 s05 empty database dependencies and state.
	 *
	 * @param array<string,mixed> $run Run.
	 * @param array<string,mixed> $item Item.
	 */
	public function __construct( array $run, array $item ) {
		$this->tables = array(
			'oj_ai_import_runs'   => array( $run ),
			'oj_ai_import_items'  => array( $item ),
			'oj_ai_audit_events'  => array(),
			'oj_ai_outbox_events' => array(),
		);
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		return 'G5S05DB:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		unset( $output );
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key = %s' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( ( $row['idempotency_key'] ?? null ) === ( $values[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_import_runs'] as $row ) {
				if ( ( $row['public_id'] ?? null ) === ( $values[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C63 get_row SQL: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, string $output ): array {
		unset( $output );
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			return array_values( array_filter( $this->tables['oj_ai_import_items'], static fn ( array $row ): bool => $row['import_run_id'] === ( $values[0] ?? null ) ) );
		}
		throw new RuntimeException( 'Unexpected C63 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$last = end( $this->tables['oj_ai_audit_events'] );
			return false === $last ? null : ( $last['event_hash'] ?? null );
		}
		throw new RuntimeException( 'Unexpected C63 get_var SQL: ' . $sql );
	}

	/**
	 * Query.
	 *
	 * @param string $prepared Prepared.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $prepared ): int {
		list( $sql, $values ) = $this->decode( $prepared );
		$this->write_sql[]    = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = $this->tables;
			return 0;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup = null;
			return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->backup ) {
				$this->tables = $this->backup;
			} $this->backup = null;
			return 0;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_import_runs SET state = %s' ) ) {
			foreach ( $this->tables['oj_ai_import_runs'] as &$row ) {
				if (
					$row['id'] === $values[5] && $row['public_id'] === $values[6] && $row['lock_version'] === $values[7]
					&& $row['state'] === $values[8] && $row['checkpoint_hash'] === $values[9] && $row['updated_at_utc'] === $values[10]
				) {
					$row['state'] = $values[0];
					++$row['lock_version'];
					$row['updated_by_user_id'] = $values[1];
					$row['updated_at_utc']     = $values[2];
					$row['checkpoint_json']    = $values[3];
					$row['checkpoint_hash']    = $values[4];
					$row['error_code']         = null;
					unset( $row );
					return 1;
				}
			}
			unset( $row );
			return 0;
		}
		throw new RuntimeException( 'Unexpected C63 query SQL: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ): int {
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C63 insert table: ' . $table );
		}
		$data['id']               = count( $this->tables[ $table ] ) + 1;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/**
	 * Decode g5 s05 empty database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S05DB:' ) ) {
			return array( $prepared, array() );
		}
		$value = unserialize( base64_decode( substr( $prepared, 8 ), true ), array( 'allowed_classes' => false ) );
		return is_array( $value ) ? $value : array( '', array() );
	}
}

$canonical       = new Canonical_Json();
$run_id          = g5_s05_id();
$command_id      = g5_s05_id();
$checkpoint_hash = hash( 'sha256', 'terminal-checkpoint-input' );
$command         = new Rollback_Legacy_Migration_Command(
	77,
	$run_id,
	$command_id,
	'completed',
	$checkpoint_hash,
	'2026-08-26 01:00:00',
	hash( 'sha256', 'idempotency-key' ),
	'g5-s05-correlation-0001',
	$canonical
);
g5_s05_assert( str_starts_with( $command->idempotency_storage_key(), 'command:' ), 'C63 command storage key profile drifted.' );
g5_s05_same( 64, strlen( $command->request_digest() ), 'C63 request digest is not SHA-256.' );
try {
	new Rollback_Legacy_Migration_Command( 77, $run_id, $command_id, 'running', $checkpoint_hash, '2026-08-26 01:00:00', hash( 'sha256', 'key' ), 'g5-s05-invalid-0001', $canonical );
	throw new RuntimeException( 'C63 accepted a non-terminal expected state.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$memory                      = new G5_S05_Memory_Repository();
$service                     = new Rollback_Legacy_Migration( $memory, new G5_S05_Site(), new Public_Id_Generator(), static fn (): string => '2026-08-26 02:00:00' );
list( $controller, $policy ) = g5_s05_controller( $service, $canonical );
$body                        = array(
	'command_id' => $command_id,
	'expected'   => array(
		'state'           => 'completed',
		'checkpoint_hash' => $checkpoint_hash,
		'updated_at_utc'  => '2026-08-26 01:00:00',
	),
	'payload'    => array(),
);
$headers                     = array(
	'X-WP-Nonce'      => 'nonce-g5-imp07-s05',
	'Idempotency-Key' => 'legacy-rollback-rest-key-0001',
);
$request                     = new G5_S05_Request( $body, $headers, array( 'run_id' => $run_id ) );
g5_s05_same( true, $controller->permissions_rollback( $request ), 'C63 permission handoff failed.' );
g5_s05_same( Legacy_Migration_Rollback_Rest_Controller::ACTION_ROLLBACK, $policy->request?->action_key(), 'C63 policy action drifted.' );
g5_s05_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C63 capability gate drifted.' );
$first = $controller->rollback( $request );
g5_s05_assert( $first instanceof WP_REST_Response, 'C63 did not return the common success envelope.' );
g5_s05_same( 200, $first->get_status(), 'C63 first response must be 200.' );
$first_data = $first->get_data();
g5_s05_same( 'compensated', $first_data['data']['migration_run']['state'], 'C63 state drifted.' );
g5_s05_same( 'compensated', $first_data['data']['migration_run']['checkpoint']['phase'], 'C63 checkpoint phase drifted.' );
g5_s05_same(
	array(
		'total'   => 4,
		'success' => 3,
		'failure' => 1,
		'skipped' => 0,
	),
	$first_data['data']['migration_run']['items'],
	'C63 item totals drifted.'
);
g5_s05_same( 3, $first_data['data']['migration_run']['compensated_target_count'], 'C63 compensated target count drifted.' );
g5_s05_same( false, $first_data['meta']['replayed'], 'C63 first result was marked replay.' );
g5_s05_same( 0, $first_data['meta']['platform_write_count'], 'C63 reported a platform write.' );
g5_s05_same( '7', $memory->record['site_id'] ?? null, 'C63 did not bind the current site ID.' );

g5_s05_same( true, $controller->permissions_rollback( $request ), 'C63 replay permission handoff failed.' );
$replay = $controller->rollback( $request );
g5_s05_same( 200, $replay->get_status(), 'C63 replay response must remain 200.' );
g5_s05_same( true, $replay->get_data()['meta']['replayed'], 'C63 replay flag is false.' );
g5_s05_same( $first_data['data'], $replay->get_data()['data'], 'C63 replay changed the safe projection.' );

$changed_body                               = $body;
$changed_body['expected']['updated_at_utc'] = '2026-08-26 01:00:01';
$changed                                    = new G5_S05_Request( $changed_body, $headers, array( 'run_id' => $run_id ) );
g5_s05_same( true, $controller->permissions_rollback( $changed ), 'C63 changed-body permission handoff failed.' );
$conflict = $controller->rollback( $changed );
g5_s05_assert( $conflict instanceof WP_Error, 'C63 changed-body replay did not conflict.' );
g5_s05_same( 409, $conflict->get_error_data()['status'], 'C63 idempotency conflict status drifted.' );

$overposted          = $body;
$overposted['extra'] = true;
$invalid             = $controller->permissions_rollback( new G5_S05_Request( $overposted, $headers, array( 'run_id' => $run_id ) ) );
g5_s05_assert( $invalid instanceof WP_Error, 'C63 accepted an over-posted body.' );
g5_s05_same( 422, $invalid->get_error_data()['status'], 'C63 over-post status drifted.' );
$bad_date                               = $body;
$bad_date['expected']['updated_at_utc'] = '2026-02-30 01:00:00';
g5_s05_assert( $controller->permissions_rollback( new G5_S05_Request( $bad_date, $headers, array( 'run_id' => $run_id ) ) ) instanceof WP_Error, 'C63 accepted an impossible UTC date.' );
g5_s05_assert( $controller->permissions_rollback( new G5_S05_Request( $body, $headers, array( 'run_id' => $run_id ), array( 'debug' => '1' ) ) ) instanceof WP_Error, 'C63 accepted query over-posting.' );

$module = new Legacy_Migration_Rollback_Rest_Module( $controller );
$module->register();
$module->register();
g5_s05_same( 1, count( $GLOBALS['g5_s05_hooks']['rest_api_init'] ?? array() ), 'C63 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_s05_same( 1, count( $GLOBALS['g5_s05_routes'] ), 'C63 REST route registered twice.' );
g5_s05_same( Legacy_Migration_Rollback_Rest_Controller::ROUTE_ROLLBACK, $GLOBALS['g5_s05_routes'][0][1], 'C63 REST path drifted.' );

foreach ( array(
	Legacy_Migration_Failure::RUN_NOT_FOUND    => 404,
	Legacy_Migration_Failure::TARGET_CHANGED   => 409,
	Legacy_Migration_Failure::ROLLBACK_INVALID => 422,
) as $reason => $status ) {
	$failing                    = new G5_S05_Memory_Repository();
	$failing->failure           = $reason;
	list( $failing_controller ) = g5_s05_controller( new Rollback_Legacy_Migration( $failing, new G5_S05_Site(), new Public_Id_Generator() ), $canonical );
	$failing_request            = new G5_S05_Request( $body, $headers, array( 'run_id' => $run_id ) );
	g5_s05_same( true, $failing_controller->permissions_rollback( $failing_request ), 'C63 failure-map permission failed.' );
	$error = $failing_controller->rollback( $failing_request );
	g5_s05_same( $status, $error->get_error_data()['status'], 'C63 failure status map drifted for ' . $reason );
}

$probe_db         = new G5_S05_Probe_Database();
$probe_files      = new G5_S05_Private_Files();
$probe_repository = new WordPress_Legacy_Migration_Rollback_Repository( $canonical, $probe_files, $probe_db );
$reflection       = new ReflectionClass( $probe_repository );
$purge            = $reflection->getMethod( 'purge_private_files' );
$private_row      = array(
	'id'            => 5,
	'public_id'     => g5_s05_id(),
	'lock_version'  => 0,
	'storage_key'   => 'legacy-migration/private/file.bin',
	'rollback_spec' => array(
		'state'           => 'ready',
		'owner_type'      => 'submission',
		'owner_public_id' => g5_s05_id(),
		'purpose'         => 'submission_attachment',
		'retention_class' => 'submission_staging',
	),
);
$purge_record     = array(
	'actor_user_id'      => 77,
	'compensated_at_utc' => '2026-08-26 02:00:00',
);
g5_s05_same( 1, $purge->invoke( $probe_repository, array( $private_row ), $purge_record ), 'C63 private purge mutation count drifted.' );
g5_s05_same( array( 'legacy-migration/private/file.bin' ), $probe_files->deleted, 'C63 did not delete the exact private storage key.' );
g5_s05_assert( str_contains( $probe_db->queries[0]['sql'], 'UPDATE oj_ai_private_files SET state = %s' ), 'C63 private metadata tombstone SQL is missing.' );
g5_s05_same( 'purged', $probe_db->queries[0]['values'][0], 'C63 private metadata did not enter purged state.' );
g5_s05_assert( str_contains( $probe_db->queries[0]['sql'], 'legal_hold_until_utc IS NULL' ), 'C63 private purge omitted the legal-hold CAS.' );

$probe_db->queries = array();
$delete            = $reflection->getMethod( 'delete_targets' );
g5_s05_same(
	2,
	$delete->invoke(
		$probe_repository,
		array(
			'oj_ai_curriculums' => array( array( 'id' => 10 ) ),
			'oj_ai_assignments' => array( array( 'id' => 20 ) ),
		)
	),
	'C63 target delete count drifted.'
);
g5_s05_assert( str_contains( $probe_db->queries[0]['sql'], 'DELETE FROM oj_ai_assignments' ), 'C63 did not delete dependent assignments first.' );
g5_s05_assert( str_contains( $probe_db->queries[1]['sql'], 'DELETE FROM oj_ai_curriculums' ), 'C63 did not delete curriculum roots last.' );

$probe_db->affected = 0;
g5_s05_failure(
	static function () use ( $purge, $probe_repository, $private_row, $purge_record ): void {
		$purge->invoke( $probe_repository, array( $private_row ), $purge_record );
	},
	Legacy_Migration_Failure::TARGET_CHANGED,
	'C63 private tombstone CAS did not fail closed.'
);

$terminal_checkpoint = array(
	'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
	'phase'      => 'completed_with_errors',
	'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
	'source'     => array(
		'profile'     => 'oj_ai_dashboard_wpdb_prefixed@0.1.2',
		'fingerprint' => hash( 'sha256', 'source' ),
	),
	'plan_hash'  => hash( 'sha256', 'plan' ),
	'cursor'     => array(
		'table_index'     => 35,
		'after_source_id' => 0,
		'next_item_no'    => 2,
	),
	'totals'     => array(
		'success' => 0,
		'failure' => 1,
		'skipped' => 0,
	),
);
$terminal_json       = $canonical->encode( $terminal_checkpoint );
$terminal_hash       = hash( 'sha256', $terminal_json );
$terminal_run_id     = g5_s05_id();
$terminal_run        = array(
	'id'                  => 1,
	'public_id'           => $terminal_run_id,
	'lock_version'        => 4,
	'approved_by_user_id' => 11,
	'updated_by_user_id'  => 22,
	'updated_at_utc'      => '2026-08-26 03:00:00',
	'state'               => 'completed_with_errors',
	'checkpoint_schema'   => Execute_Legacy_Migration::CHECKPOINT_SCHEMA,
	'checkpoint_version'  => Execute_Legacy_Migration::CHECKPOINT_VERSION,
	'checkpoint_json'     => $terminal_json,
	'checkpoint_hash'     => $terminal_hash,
	'item_total'          => 1,
	'success_total'       => 0,
	'failure_total'       => 1,
	'skipped_total'       => 0,
	'error_code'          => 'legacy_migration_item_failures',
);
$failure_payload     = array(
	'profile'    => 'oj_ai.legacy_item_payload@1.0.0',
	'source'     => array(
		'type'     => 'worksheet_templates',
		'key'      => '1',
		'checksum' => hash( 'sha256', 'source-row' ),
	),
	'state'      => 'failure',
	'target'     => null,
	'error_code' => 'legacy_source_invalid',
);
$failure_json        = $canonical->encode( $failure_payload );
$failure_item        = array(
	'id'                 => 1,
	'lock_version'       => 0,
	'created_by_user_id' => 22,
	'created_at_utc'     => '2026-08-26 02:30:00',
	'updated_by_user_id' => 22,
	'updated_at_utc'     => '2026-08-26 02:30:00',
	'import_run_id'      => 1,
	'item_no'            => 1,
	'source_type'        => 'worksheet_templates',
	'source_key'         => '1',
	'source_checksum'    => hash( 'sha256', 'source-row' ),
	'state'              => 'failure',
	'payload_schema'     => 'oj_ai.legacy_item_payload',
	'payload_version'    => 1,
	'payload_json'       => $failure_json,
	'payload_hash'       => hash( 'sha256', $failure_json ),
	'target_type'        => null,
	'target_public_id'   => null,
	'error_code'         => 'legacy_source_invalid',
);
$empty_db            = new G5_S05_Empty_Database( $terminal_run, $failure_item );
$empty_files         = new G5_S05_Private_Files();
$lock                = new G5_S05_Lock();
$real_service        = new Rollback_Legacy_Migration(
	new WordPress_Legacy_Migration_Rollback_Repository( $canonical, $empty_files, $empty_db, $lock ),
	new G5_S05_Site(),
	new Public_Id_Generator(),
	static fn (): string => '2026-08-26 04:00:00'
);
$real_command        = new Rollback_Legacy_Migration_Command(
	77,
	$terminal_run_id,
	g5_s05_id(),
	'completed_with_errors',
	$terminal_hash,
	'2026-08-26 03:00:00',
	hash( 'sha256', 'real-idempotency' ),
	'g5-s05-real-repository-0001',
	$canonical
);
$real_result         = $real_service->rollback( $real_command );
g5_s05_same( 'compensated', $real_result->state(), 'C63 real repository did not compensate the terminal run.' );
g5_s05_same( 0, $real_result->compensated_target_count(), 'C63 failure-only run reported a target deletion.' );
g5_s05_same( 1, count( $empty_db->tables['oj_ai_import_items'] ), 'C63 removed the import item ledger.' );
g5_s05_same( 1, count( $empty_db->tables['oj_ai_audit_events'] ), 'C63 did not append one audit event.' );
g5_s05_same( 1, count( $empty_db->tables['oj_ai_outbox_events'] ), 'C63 did not append one outbox receipt.' );
g5_s05_same( array(), $empty_files->deleted, 'C63 failure-only run touched private bytes.' );
g5_s05_assert( ! array_filter( $empty_db->write_sql, static fn ( string $sql ): bool => str_starts_with( $sql, 'DELETE FROM' ) ), 'C63 failure-only run issued a business DELETE.' );
g5_s05_assert( str_contains( $lock->events[0] ?? '', 'oj_ai_dashboard_legacy_migration_7' ), 'C63 did not acquire the site migration lock.' );
$real_replay = $real_service->rollback( $real_command );
g5_s05_same( true, $real_replay->replayed(), 'C63 real repository replay flag is false.' );
g5_s05_same( 1, count( $empty_db->tables['oj_ai_audit_events'] ), 'C63 real replay duplicated audit events.' );

$repository_source  = (string) file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Rollback_Repository.php' );
$controller_source  = (string) file_get_contents( $root . '/src/Operations/LegacyMigration/Presentation/Legacy_Migration_Rollback_Rest_Controller.php' );
$composition_source = (string) file_get_contents( $root . '/src/Core/Composition_Root.php' );
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'TRUNCATE TABLE', 'RENAME TABLE' ) as $forbidden ) {
	g5_s05_assert( ! str_contains( $repository_source . $controller_source, $forbidden ), 'C63 crossed a frozen boundary: ' . $forbidden );
}
foreach ( array(
	"private const TABLE_RUNS = 'oj_ai_import_runs';",
	"private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';",
	"'DELETE FROM ' . \$table",
	"'purged'",
	'legal_hold_until_utc IS NULL',
	"'phase'] = 'compensated'",
	'execution_actor_user_id',
	'assert_exact_children( self::TABLE_PUBLICATIONS',
	'TARGET_CHANGED',
	"'platform_write_count'",
) as $marker ) {
	g5_s05_assert( Ojai_Php_Source_Contract::marker( $repository_source . $controller_source, $marker ), 'C63 implementation marker is missing: ' . $marker );
}
foreach ( array( 'DELETE FROM oj_ai_import_runs', 'DELETE FROM oj_ai_import_items', 'DELETE FROM oj_ai_audit_events', 'DELETE FROM oj_ai_outbox_events', 'DELETE FROM oj_ai_private_files' ) as $forbidden_delete ) {
	g5_s05_assert( ! str_contains( $repository_source, $forbidden_delete ), 'C63 deletes a preserved ledger or private tombstone: ' . $forbidden_delete );
}
foreach ( array( 'Legacy_Migration_Rollback_Rest_Controller', 'Legacy_Migration_Rollback_Rest_Module', 'WordPress_Legacy_Migration_Rollback_Repository' ) as $marker ) {
	g5_s05_assert( str_contains( $composition_source, $marker ), 'C63 Composition Root marker is missing: ' . $marker );
}

fwrite( STDOUT, "G5-IMP-07-S05 passed: strict C63 REST, execution-actor ownership, unchanged-target reverse deletion, private byte purge plus tombstone, durable replay, preserved migration ledgers, and zero platform writes are frozen.\n" );
