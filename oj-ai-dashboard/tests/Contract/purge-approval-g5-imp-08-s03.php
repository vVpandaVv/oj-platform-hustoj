<?php
/**
 * G5-IMP-08-S03 C72 purge approval contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Preview_Builder;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;
use OJAI\Dashboard\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token;
use OJAI\Dashboard\Operations\Purge\Infrastructure\WordPress_Purge_Approval_Repository;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Approval_Rest_Controller;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Approval_Rest_Module;
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-08-S03 C72 purge approval contract.
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
	 * G5-IMP-08-S03 C72 purge approval contract.
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

$GLOBALS['g5_imp08_s03_hooks']  = array();
$GLOBALS['g5_imp08_s03_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_imp08_s03_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_imp08_s03_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 imp08 s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 imp08 s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 imp08 s03 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s03_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Purge_Failure $failure ) {
		g5_imp08_s03_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 imp08 s03 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s03_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': invalid input was accepted.' );
}

/**
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Inventory implements Purge_Inventory_Port_Interface {
	/**
	 * Initialize g5 imp08 s03 inventory dependencies and state.
	 *
	 * @param array<string,int> $owner_rows Owner rows.
	 * @param int               $file_count File count.
	 * @param int               $blob_bytes Blob bytes.
	 * @param int               $hold_count Hold count.
	 * @param array             $jobs Jobs.
	 */
	public function __construct(
		public array $owner_rows,
		public int $file_count = 3,
		public int $blob_bytes = 2048,
		public int $hold_count = 0,
		public array $jobs = array(
			'evaluation_runs' => 1,
			'export_jobs'     => 0,
			'zip_uploads'     => 0,
			'import_runs'     => 0,
			'purge_jobs'      => 0,
		)
	) {
	}

	/**
	 * Inspect.
	 *
	 * @return Purge_Inventory_Snapshot
	 */
	public function inspect(): Purge_Inventory_Snapshot {
		return new Purge_Inventory_Snapshot(
			$this->owner_rows,
			$this->file_count,
			$this->blob_bytes,
			$this->hold_count,
			$this->jobs
		);
	}
}

/**
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Site implements Purge_Site_Port_Interface {
	/**
	 * Initialize g5 imp08 s03 site dependencies and state.
	 *
	 * @param string $site Site.
	 */
	public function __construct( private string $site = '7' ) {
	}
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return $this->site;
	}
}

/**
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_purge_jobs'    => array(),
		'oj_ai_audit_events'  => array(),
		'oj_ai_outbox_events' => array(),
		'oj_ai_worksheets'    => array( array( 'public_id' => 'protected-business-row' ) ),
	);
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		return 'G5S03:' . base64_encode( serialize( array( $sql, $values ) ) );
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
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key = %s' ) ) {
			$key = (string) ( $values[0] ?? '' );
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $key === (string) $row['idempotency_key'] ) {
					return array(
						'aggregate_public_id' => $row['aggregate_public_id'],
						'event_type'          => $row['event_type'],
						'payload_schema'      => $row['payload_schema'],
						'payload_version'     => $row['payload_version'],
						'payload_json'        => $row['payload_json'],
						'payload_hash'        => $row['payload_hash'],
					);
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_purge_jobs WHERE approval_token_hash = %s' ) ) {
			$hash = (string) ( $values[0] ?? '' );
			foreach ( $this->tables['oj_ai_purge_jobs'] as $row ) {
				if ( $hash === (string) $row['approval_token_hash'] ) {
					return array(
						'public_id'       => $row['public_id'],
						'idempotency_key' => $row['idempotency_key'],
					);
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C72 row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected C72 scalar query: ' . $sql );
		}
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? (string) $last['event_hash'] : null;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( ! array_key_exists( $table, $this->tables ) || $this->fail_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		if ( 'oj_ai_purge_jobs' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if (
					(string) $row['idempotency_key'] === (string) $data['idempotency_key']
					|| (string) $row['approval_token_hash'] === (string) $data['approval_token_hash']
				) {
					$this->last_error = 'duplicate purge approval';
					return false;
				}
			}
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
					$this->last_error = 'duplicate receipt';
					return false;
				}
			}
		}
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables = $this->backup;
			}
			$this->backup     = null;
			$this->last_error = '';
			return 1;
		}
		throw new RuntimeException( 'Unexpected C72 transaction query: ' . $sql );
	}

	/**
	 * Decode g5 imp08 s03 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S03:' ) ) {
			throw new RuntimeException( 'C72 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value[0] ) || ! is_array( $value[1] ) ) {
			throw new RuntimeException( 'C72 prepared query is invalid.' );
		}
		return $value;
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}
}

/**
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Initialize g5 imp08 s03 nonce dependencies and state.
	 *
	 * @param bool $valid Valid.
	 */
	public function __construct( private bool $valid = true ) {
	}
	/**
	 * Verify g5 imp08 s03 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return $this->valid && 'nonce-g5-imp08-s03' === $nonce;
	}
}

/**
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Policy implements Access_Policy_Port_Interface {
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
 * G5-IMP-08-S03 C72 purge approval contract.
 */
final class G5_Imp08_S03_Request {
	/**
	 * Initialize g5 imp08 s03 request dependencies and state.
	 *
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct( private array $body, private array $headers = array(), private array $query = array() ) {
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
 * G5 imp08 s03 controller.
 *
 * @param Approve_Purge  $service Service.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param bool           $nonce_valid Nonce valid.
 * @return array{0:Purge_Approval_Rest_Controller,1:G5_Imp08_S03_Policy}
 */
function g5_imp08_s03_controller( Approve_Purge $service, Canonical_Json $canonical, bool $nonce_valid = true ): array {
	$policy       = new G5_Imp08_S03_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_Imp08_S03_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_Imp08_S03_Nonce( $nonce_valid ), $correlations ),
		$envelopes,
		$correlations
	);
	return array( new Purge_Approval_Rest_Controller( $permissions, $service, $envelopes, $correlations, $canonical ), $policy );
}

/**
 * G5 imp08 s03 command.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $token Token.
 * @param string         $key_hash Key hash.
 * @param string         $backup_time Backup time.
 * @param string         $backup_seed Backup seed.
 * @param int            $actor Actor.
 * @param string         $correlation Correlation.
 * @return Approve_Purge_Command
 */
function g5_imp08_s03_command(
	Canonical_Json $canonical,
	string $token,
	string $key_hash,
	string $backup_time,
	string $backup_seed = 'backup-v1',
	int $actor = 77,
	string $correlation = 'corr-g5-imp08-s03-approve'
): Approve_Purge_Command {
	return new Approve_Purge_Command(
		$actor,
		'addon',
		$token,
		hash( 'sha256', $backup_seed ),
		new DateTimeImmutable( $backup_time, new DateTimeZone( 'UTC' ) ),
		Approve_Purge_Command::CONFIRMATION_PHRASE,
		$key_hash,
		$correlation,
		$canonical
	);
}

$canonical                = new Canonical_Json();
$catalog                  = new Schema_Catalog();
$owner_rows               = array_fill_keys( array_keys( $catalog->owner_counts() ), 0 );
$owner_rows['Worksheet']  = 4;
$owner_rows['Submission'] = 7;
$inventory                = new G5_Imp08_S03_Inventory( $owner_rows );
$site                     = new G5_Imp08_S03_Site();
$now                      = '2026-08-26 09:00:00';
$clock                    = static function () use ( &$now ): DateTimeImmutable {
	return new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) );
};
$nonce_counter            = 0;
$nonce                    = static function () use ( &$nonce_counter ): string {
	++$nonce_counter;
	return str_pad( dechex( $nonce_counter ), 32, '0', STR_PAD_LEFT );
};
$tokens                   = new Signed_Purge_Preview_Token( $canonical, str_repeat( 'a', 64 ), $clock, $nonce );
$preview_service          = new Preview_Purge( $inventory, $tokens, $site, $catalog, $canonical );
$database                 = new G5_Imp08_S03_Database();
$repository               = new WordPress_Purge_Approval_Repository( $canonical, $database );
$approval_service         = new Approve_Purge(
	new Purge_Preview_Builder( $inventory, $catalog, $canonical ),
	$tokens,
	$site,
	$repository,
	new Public_Id_Generator(),
	$canonical,
	$clock
);

$key      = 'purge-approve-key-0001';
$key_hash = hash( 'sha256', $key );
$preview  = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $key_hash, 'corr-g5-imp08-s03-preview' ) );
$now      = '2026-08-26 09:05:00';
$command  = g5_imp08_s03_command( $canonical, $preview->preview_token(), $key_hash, '2026-08-26 08:00:00' );
$first    = $approval_service->execute( $command );

g5_imp08_s03_same( 'approved', $first->state(), 'C72 did not create an approved purge job.' );
g5_imp08_s03_same( false, $first->replayed(), 'First C72 request was marked replay.' );
g5_imp08_s03_same( '2026-08-26 09:35:00', $first->approval_expires_at_utc(), 'C72 approval does not expire after 30 minutes.' );
g5_imp08_s03_same( 1, $first->mutation_count(), 'C72 must report one approved ledger mutation.' );
g5_imp08_s03_same( 0, $first->business_mutation_count(), 'C72 changed business data.' );
g5_imp08_s03_same( 0, $first->file_mutation_count(), 'C72 deleted a private file.' );
g5_imp08_s03_same( 0, $first->option_mutation_count(), 'C72 changed an option.' );
g5_imp08_s03_same( 0, $first->role_mutation_count(), 'C72 changed a role.' );
g5_imp08_s03_same( 0, $first->platform_write_count(), 'C72 wrote platform data.' );
g5_imp08_s03_same( 1, count( $database->tables['oj_ai_purge_jobs'] ), 'C72 must create exactly one purge job.' );
g5_imp08_s03_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C72 must append one audit event.' );
g5_imp08_s03_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C72 must append one durable receipt.' );
g5_imp08_s03_same( array( array( 'public_id' => 'protected-business-row' ) ), $database->tables['oj_ai_worksheets'], 'C72 mutated business rows.' );

$job = $database->tables['oj_ai_purge_jobs'][0];
g5_imp08_s03_same( 'oj_ai.purge_preview', $job['preview_schema'], 'C72 preview schema drifted.' );
g5_imp08_s03_same( 1, $job['preview_version'], 'C72 preview database version drifted.' );
g5_imp08_s03_same( null, $job['checkpoint_json'], 'C72 created an execution checkpoint before C73.' );
g5_imp08_s03_same( hash( 'sha256', $job['preview_json'] ), $job['preview_hash'], 'C72 stored preview JSON without its integrity hash.' );
$stored_preview = json_decode( $job['preview_json'], true, 64, JSON_THROW_ON_ERROR );
g5_imp08_s03_same( $preview->preview()['preview_hash'], $stored_preview['preview_hash'], 'C72 did not freeze the exact C71 preview hash.' );
g5_imp08_s03_same( 0, $stored_preview['private_files']['legal_hold_count'], 'C72 stored a legal hold.' );
$audit_json = $database->tables['oj_ai_audit_events'][0]['metadata_json'];
foreach ( array( hash( 'sha256', 'backup-v1' ), hash( 'sha256', Approve_Purge_Command::CONFIRMATION_PHRASE ) ) as $digest ) {
	g5_imp08_s03_assert( str_contains( $audit_json, $digest ), 'C72 audit lost a required evidence digest.' );
}
foreach ( array( '영구 삭제', 'C:\\', '/var/', 's3://', 'password', 'credential' ) as $forbidden ) {
	g5_imp08_s03_assert( ! str_contains( $audit_json, $forbidden ), 'C72 audit leaked forbidden evidence material: ' . $forbidden );
}

$counts_after_first = $database->counts();
$replay             = $approval_service->execute( $command );
g5_imp08_s03_same( $first->job_id(), $replay->job_id(), 'Same C72 request did not replay the same job.' );
g5_imp08_s03_same( true, $replay->replayed(), 'C72 replay flag is false.' );
g5_imp08_s03_same( 0, $replay->mutation_count(), 'C72 replay reported a mutation.' );
g5_imp08_s03_same( $counts_after_first, $database->counts(), 'C72 replay appended duplicate rows.' );

$changed_backup = g5_imp08_s03_command( $canonical, $preview->preview_token(), $key_hash, '2026-08-26 08:00:00', 'backup-v2' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $changed_backup ), Purge_Failure::IDEMPOTENCY_CONFLICT, 'C72 accepted changed evidence under an existing key.' );
$other_actor = g5_imp08_s03_command( $canonical, $preview->preview_token(), $key_hash, '2026-08-26 08:00:00', 'backup-v1', 88, 'corr-g5-imp08-s03-actor2' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $other_actor ), Purge_Failure::TOKEN_ALREADY_USED, 'C72 reused one preview token for another approval ledger.' );

$wrong_key = g5_imp08_s03_command( $canonical, $preview->preview_token(), hash( 'sha256', 'wrong-key' ), '2026-08-26 08:00:00' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $wrong_key ), Purge_Failure::TOKEN_SCOPE_MISMATCH, 'C72 accepted a different Idempotency-Key.' );

$drift_key_hash                     = hash( 'sha256', 'drift-key' );
$now                                = '2026-08-26 10:00:00';
$drift_preview                      = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $drift_key_hash, 'corr-g5-imp08-s03-drift-preview' ) );
$inventory->owner_rows['Worksheet'] = 5;
$now                                = '2026-08-26 10:05:00';
$drift_command                      = g5_imp08_s03_command( $canonical, $drift_preview->preview_token(), $drift_key_hash, '2026-08-26 09:00:00' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $drift_command ), Purge_Failure::PREVIEW_STALE, 'C72 accepted changed inventory.' );
$inventory->owner_rows['Worksheet'] = 4;

$hold_key_hash         = hash( 'sha256', 'hold-key' );
$inventory->hold_count = 1;
$now                   = '2026-08-26 11:00:00';
$hold_preview          = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $hold_key_hash, 'corr-g5-imp08-s03-hold-preview' ) );
$now                   = '2026-08-26 11:05:00';
$hold_command          = g5_imp08_s03_command( $canonical, $hold_preview->preview_token(), $hold_key_hash, '2026-08-26 10:00:00' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $hold_command ), Purge_Failure::LEGAL_HOLD_BLOCKED, 'C72 accepted a nonzero legal hold.' );
$inventory->hold_count = 0;

$backup_key_hash = hash( 'sha256', 'backup-boundary-key' );
$now             = '2026-08-26 12:00:00';
$backup_preview  = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $backup_key_hash, 'corr-g5-imp08-s03-backup-preview' ) );
$now             = '2026-08-26 12:05:00';
$expired_backup  = g5_imp08_s03_command( $canonical, $backup_preview->preview_token(), $backup_key_hash, '2026-08-25 12:04:59' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $expired_backup ), Purge_Failure::BACKUP_INVALID, 'C72 accepted backup evidence older than 24 hours.' );
$future_backup = g5_imp08_s03_command( $canonical, $backup_preview->preview_token(), $backup_key_hash, '2026-08-26 12:05:01' );
g5_imp08_s03_failure( static fn () => $approval_service->execute( $future_backup ), Purge_Failure::BACKUP_INVALID, 'C72 accepted future backup evidence.' );

g5_imp08_s03_invalid(
	static fn () => new Approve_Purge_Command(
		77,
		'addon',
		$backup_preview->preview_token(),
		hash( 'sha256', 'backup' ),
		new DateTimeImmutable( '2026-08-26 12:00:00', new DateTimeZone( 'UTC' ) ),
		'삭제',
		$backup_key_hash,
		'corr-g5-imp08-s03-invalid',
		$canonical
	),
	'C72 accepted the wrong confirmation phrase.'
);

$rollback_key_hash    = hash( 'sha256', 'rollback-key' );
$now                  = '2026-08-26 13:00:00';
$rollback_preview     = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $rollback_key_hash, 'corr-g5-imp08-s03-rollback-preview' ) );
$now                  = '2026-08-26 13:05:00';
$rollback_command     = g5_imp08_s03_command( $canonical, $rollback_preview->preview_token(), $rollback_key_hash, '2026-08-26 12:00:00' );
$database->fail_table = 'oj_ai_audit_events';
g5_imp08_s03_failure( static fn () => $approval_service->execute( $rollback_command ), Purge_Failure::STORAGE_UNAVAILABLE, 'C72 did not surface an atomic storage failure.' );
g5_imp08_s03_same( $counts_after_first, $database->counts(), 'C72 left a partial job after audit failure.' );
$database->fail_table = null;

$rest_key                    = 'purge-rest-key-0001';
$rest_key_hash               = hash( 'sha256', $rest_key );
$now                         = '2026-08-26 14:00:00';
$rest_preview                = $preview_service->execute( new Preview_Purge_Command( 77, 'addon', $rest_key_hash, 'corr-g5-imp08-s03-rest-preview' ) );
$now                         = '2026-08-26 14:05:00';
list( $controller, $policy ) = g5_imp08_s03_controller( $approval_service, $canonical );
$body                        = array(
	'scope'         => array( 'type' => 'addon' ),
	'preview_token' => $rest_preview->preview_token(),
	'backup'        => array(
		'evidence_digest' => hash( 'sha256', 'rest-backup' ),
		'verified_at_utc' => '2026-08-26 13:00:00',
	),
	'confirmation'  => array( 'phrase' => Approve_Purge_Command::CONFIRMATION_PHRASE ),
);
$headers                     = array(
	'X-WP-Nonce'      => 'nonce-g5-imp08-s03',
	'Idempotency-Key' => $rest_key,
);
$request                     = new G5_Imp08_S03_Request( $body, $headers );
g5_imp08_s03_same( true, $controller->permissions_approve( $request ), 'C72 permission handoff failed.' );
g5_imp08_s03_same( Purge_Approval_Rest_Controller::ACTION_APPROVE, $policy->request?->action_key(), 'C72 common policy action drifted.' );
g5_imp08_s03_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C72 manage_migrations gate drifted.' );
g5_imp08_s03_same( array( Access_Policy_Request::CORE_DELETE_PLUGINS ), $policy->request?->required_core_capabilities(), 'C72 delete_plugins AND gate drifted.' );
$response = $controller->approve( $request );
g5_imp08_s03_assert( $response instanceof WP_REST_Response, 'C72 did not return the common REST envelope.' );
g5_imp08_s03_same( 201, $response->get_status(), 'First C72 REST response must be 201.' );
g5_imp08_s03_same( 'approved', $response->get_data()['data']['purge_job']['state'], 'C72 REST state drifted.' );
foreach ( array( 'business_mutation_count', 'file_mutation_count', 'option_mutation_count', 'role_mutation_count', 'platform_write_count' ) as $zero ) {
	g5_imp08_s03_same( 0, $response->get_data()['meta'][ $zero ], 'C72 response lost zero-mutation evidence: ' . $zero );
}
g5_imp08_s03_same( true, $controller->permissions_approve( $request ), 'C72 replay permission handoff failed.' );
$replay_response = $controller->approve( $request );
g5_imp08_s03_same( 200, $replay_response->get_status(), 'C72 replay REST response must be 200.' );
g5_imp08_s03_same( $response->get_data()['data']['purge_job']['id'], $replay_response->get_data()['data']['purge_job']['id'], 'C72 replay changed job ID.' );

$module = new Purge_Approval_Rest_Module( $controller );
$module->register();
$module->register();
g5_imp08_s03_same( 1, count( $GLOBALS['g5_imp08_s03_hooks']['rest_api_init'] ?? array() ), 'C72 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_imp08_s03_same( 1, count( $GLOBALS['g5_imp08_s03_routes'] ), 'C72 REST route registered twice.' );
g5_imp08_s03_same( '/purge-jobs/commands/approve', $GLOBALS['g5_imp08_s03_routes'][0][1], 'C72 REST path drifted.' );

$bad_body          = $body;
$bad_body['extra'] = true;
$bad_body_error    = $controller->permissions_approve( new G5_Imp08_S03_Request( $bad_body, $headers ) );
g5_imp08_s03_assert( $bad_body_error instanceof WP_Error, 'C72 accepted an over-posted body.' );
g5_imp08_s03_same( 422, $bad_body_error->get_error_data()['status'], 'C72 over-posted body status drifted.' );
$bad_phrase                           = $body;
$bad_phrase['confirmation']['phrase'] = '삭제';
$bad_phrase_error                     = $controller->permissions_approve( new G5_Imp08_S03_Request( $bad_phrase, $headers ) );
g5_imp08_s03_assert( $bad_phrase_error instanceof WP_Error, 'C72 accepted the wrong confirmation phrase at REST.' );
g5_imp08_s03_same( 422, $bad_phrase_error->get_error_data()['status'], 'C72 wrong phrase status drifted.' );
list( $missing_key_controller ) = g5_imp08_s03_controller( $approval_service, $canonical );
$missing_key_error              = $missing_key_controller->permissions_approve( new G5_Imp08_S03_Request( $body, array( 'X-WP-Nonce' => 'nonce-g5-imp08-s03' ) ) );
g5_imp08_s03_assert( $missing_key_error instanceof WP_Error, 'C72 accepted a missing Idempotency-Key.' );
g5_imp08_s03_same( 422, $missing_key_error->get_error_data()['status'], 'C72 missing Idempotency-Key status drifted.' );
list( $bad_nonce_controller ) = g5_imp08_s03_controller( $approval_service, $canonical, false );
$bad_nonce_error              = $bad_nonce_controller->permissions_approve( new G5_Imp08_S03_Request( $body, $headers ) );
g5_imp08_s03_assert( $bad_nonce_error instanceof WP_Error, 'C72 accepted an invalid nonce.' );
g5_imp08_s03_same( 403, $bad_nonce_error->get_error_data()['status'], 'C72 invalid nonce status drifted.' );

$runtime_files = array(
	'src/Operations/Purge/Application/Approve_Purge.php',
	'src/Operations/Purge/Infrastructure/WordPress_Purge_Approval_Repository.php',
	'src/Operations/Purge/Presentation/Purge_Approval_Rest_Controller.php',
);
foreach ( $runtime_files as $relative ) {
	$source = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_imp08_s03_assert( is_string( $source ), 'C72 runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'DELETE FROM', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_imp08_s03_assert( ! str_contains( $source, $forbidden ), 'C72 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}
$repository_source = file_get_contents( $root . '/src/Operations/Purge/Infrastructure/WordPress_Purge_Approval_Repository.php' );
foreach ( array( "'oj_ai_purge_jobs'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'" ) as $literal_table ) {
	g5_imp08_s03_assert( str_contains( (string) $repository_source, $literal_table ), 'C72 lost literal ledger table: ' . $literal_table );
}
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
g5_imp08_s03_assert( str_contains( (string) $composition_source, 'Purge_Approval_Rest_Module' ), 'C72 production REST module is not composed.' );

fwrite( STDOUT, "G5-IMP-08-S03 passed: C72 strict backup and phrase validation, token/scope/preview revalidation, legal-hold blocking, atomic approved job, durable replay, and zero deletion are frozen.\n" );
