<?php
/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Process_Purge_Batch;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Preview_Builder;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Batch_Result;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Job_Snapshot;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Control_Rest_Controller;
use OJAI\Dashboard\Operations\Purge\Presentation\Purge_Control_Rest_Module;
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
	 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
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
	 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
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

$GLOBALS['g5_imp08_s04_hooks']  = array();
$GLOBALS['g5_imp08_s04_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_imp08_s04_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_imp08_s04_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 imp08 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 imp08 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 imp08 s04 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s04_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Purge_Failure $failure ) {
		g5_imp08_s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 imp08 s04 id.
 *
 * @param int $seed Seed.
 * @return string
 */
function g5_imp08_s04_id( int $seed ): string {
	$ids = new Public_Id_Generator( static fn ( int $length ): string => str_repeat( chr( $seed ), $length ) );
	return $ids->create()->value();
}

/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Inventory implements Purge_Inventory_Port_Interface {
	/**
	 * Initialize g5 imp08 s04 inventory dependencies and state.
	 *
	 * @param array<string,int> $owner_rows Owner rows.
	 * @param int               $file_count File count.
	 * @param int               $blob_bytes Blob bytes.
	 * @param int               $hold_count Hold count.
	 * @param array<string,int> $jobs Jobs.
	 */
	public function __construct(
		public array $owner_rows,
		public int $file_count,
		public int $blob_bytes,
		public int $hold_count,
		public array $jobs
	) {
	}
	/**
	 * Inspect.
	 *
	 * @return Purge_Inventory_Snapshot
	 */
	public function inspect(): Purge_Inventory_Snapshot {
		return new Purge_Inventory_Snapshot( $this->owner_rows, $this->file_count, $this->blob_bytes, $this->hold_count, $this->jobs );
	}
}

/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Site implements Purge_Site_Port_Interface {
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
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Queue implements Purge_Queue_Wakeup_Port_Interface {
	/**
	 * Jobs.
	 *
	 * @var array<int,string>
	 */
	public array $jobs = array();
	/**
	 * Wake.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function wake( string $job_id ): void {
		$this->jobs[] = $job_id;
	}
}

/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Repository implements Purge_Operation_Repository_Port_Interface {
	/**
	 * Start record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $start_record = null;
	/**
	 * Cancel record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $cancel_record = null;
	/**
	 * Start calls.
	 *
	 * @var int
	 */
	public int $start_calls = 0;
	/**
	 * Cancel calls.
	 *
	 * @var int
	 */
	public int $cancel_calls = 0;
	/**
	 * Batch failure.
	 *
	 * @var Purge_Failure|null
	 */
	public ?Purge_Failure $batch_failure = null;
	/**
	 * Batch result.
	 *
	 * @var Purge_Batch_Result|null
	 */
	public ?Purge_Batch_Result $batch_result = null;
	/**
	 * Started.
	 *
	 * @var Execute_Purge_Result|null
	 */
	private ?Execute_Purge_Result $started = null;
	/**
	 * Cancelled.
	 *
	 * @var Cancel_Purge_Result|null
	 */
	private ?Cancel_Purge_Result $cancelled = null;
	/**
	 * Start key.
	 *
	 * @var string|null
	 */
	private ?string $start_key = null;
	/**
	 * Start digest.
	 *
	 * @var string|null
	 */
	private ?string $start_digest = null;
	/**
	 * Cancel key.
	 *
	 * @var string|null
	 */
	private ?string $cancel_key = null;
	/**
	 * Cancel digest.
	 *
	 * @var string|null
	 */
	private ?string $cancel_digest = null;

	/**
	 * Initialize g5 imp08 s04 repository dependencies and state.
	 *
	 * @param Purge_Job_Snapshot $snapshot Snapshot.
	 * @param Canonical_Json     $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( public Purge_Job_Snapshot $snapshot, private Canonical_Json $canonical ) {
	}

	/**
	 * Execution receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Purge_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execution_receipt( string $storage_key, string $request_digest ): ?Execute_Purge_Result {
		if ( null === $this->started || $storage_key !== $this->start_key ) {
			return null;
		}
		if ( ! hash_equals( (string) $this->start_digest, $request_digest ) ) {
			throw Purge_Failure::because( Purge_Failure::IDEMPOTENCY_CONFLICT );
		}
		return new Execute_Purge_Result(
			$this->started->job_id(),
			$this->started->state(),
			$this->started->preview_hash(),
			$this->started->checkpoint(),
			$this->started->checkpoint_hash(),
			$this->started->started_at_utc(),
			true
		);
	}

	/**
	 * Cancellation receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Purge_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cancellation_receipt( string $storage_key, string $request_digest ): ?Cancel_Purge_Result {
		if ( null === $this->cancelled || $storage_key !== $this->cancel_key ) {
			return null;
		}
		if ( ! hash_equals( (string) $this->cancel_digest, $request_digest ) ) {
			throw Purge_Failure::because( Purge_Failure::IDEMPOTENCY_CONFLICT );
		}
		return new Cancel_Purge_Result(
			$this->cancelled->job_id(),
			$this->cancelled->state(),
			$this->cancelled->preview_hash(),
			$this->cancelled->checkpoint(),
			$this->cancelled->checkpoint_hash(),
			$this->cancelled->cancelled_at_utc(),
			$this->cancelled->maintenance_retained(),
			3,
			true
		);
	}

	/**
	 * Job.
	 *
	 * @param string $job_id Job id.
	 * @return Purge_Job_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function job( string $job_id ): Purge_Job_Snapshot {
		if ( $job_id !== $this->snapshot->public_id() ) {
			throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
		}
		return $this->snapshot;
	}

	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Purge_Result
	 */
	public function start( array $record ): Execute_Purge_Result {
		++$this->start_calls;
		$this->start_record = $record;
		$this->start_key    = (string) $record['command_storage_key'];
		$this->start_digest = (string) $record['request_digest'];
		$this->started      = new Execute_Purge_Result(
			(string) $record['job_public_id'],
			'running',
			(string) $record['preview_hash'],
			$record['checkpoint'],
			(string) $record['checkpoint_hash'],
			(string) $record['started_at_utc'],
			false
		);
		return $this->started;
	}

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Cancel_Purge_Result
	 */
	public function cancel( array $record ): Cancel_Purge_Result {
		++$this->cancel_calls;
		$this->cancel_record = $record;
		$this->cancel_key    = (string) $record['command_storage_key'];
		$this->cancel_digest = (string) $record['request_digest'];
		$checkpoint          = $this->snapshot->checkpoint();
		$hash                = null;
		$retained            = false;
		$ledger              = 3;
		if ( is_array( $checkpoint ) ) {
			$deleted             = 0 !== $checkpoint['totals']['deleted_rows']
				|| 0 !== $checkpoint['totals']['deleted_files']
				|| 0 !== $checkpoint['totals']['deleted_bytes'];
			$retained            = $deleted || 'maintenance_required' === $checkpoint['maintenance']['previous_installation_state'];
			$ledger              = $deleted ? 3 : 4;
			$checkpoint['phase'] = 'cancelled';
			$hash                = hash( 'sha256', $this->canonical->encode( $checkpoint ) );
		}
		$this->cancelled = new Cancel_Purge_Result(
			(string) $record['job_public_id'],
			'cancelled',
			(string) $record['preview_hash'],
			$checkpoint,
			$hash,
			(string) $record['cancelled_at_utc'],
			$retained,
			$ledger,
			false
		);
		return $this->cancelled;
	}

	/**
	 * Process batch.
	 *
	 * @param string $job_id Job id.
	 * @param string $site_id Site id.
	 * @param string $processed_at_utc Processed at utc.
	 * @return Purge_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function process_batch( string $job_id, string $site_id, string $processed_at_utc ): Purge_Batch_Result {
		if ( $this->batch_failure instanceof Purge_Failure ) {
			throw $this->batch_failure;
		}
		if ( ! $this->batch_result instanceof Purge_Batch_Result ) {
			throw new LogicException( 'Batch result was not configured.' );
		}
		return $this->batch_result;
	}
}
/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Actor implements Current_Actor_Port_Interface {
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
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify g5 imp08 s04 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'nonce-g5-imp08-s04' === $nonce;
	}
}

/**
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Policy implements Access_Policy_Port_Interface {
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
 * G5-IMP-08-S04 C73 checkpoint purge and C74 safe cancellation contract.
 */
final class G5_Imp08_S04_Request {
	/**
	 * Initialize g5 imp08 s04 request dependencies and state.
	 *
	 * @param string               $job_id Job id.
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct(
		private string $job_id,
		private array $body,
		private array $headers,
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
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'job_id' => $this->job_id );
	}
}

/**
 * G5 imp08 s04 job.
 *
 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed>      $preview Preview.
 * @param string                   $state State.
 * @param array<string,mixed>|null $checkpoint Checkpoint.
 * @param string                   $approved_at Approved at.
 * @param string                   $expires_at Expires at.
 * @param string                   $backup_at Backup at.
 * @param string                   $installation_state Installation state.
 * @return Purge_Job_Snapshot
 */
function g5_imp08_s04_job(
	Canonical_Json $canonical,
	array $preview,
	string $state = 'approved',
	?array $checkpoint = null,
	string $approved_at = '2026-08-26 11:50:00',
	string $expires_at = '2026-08-26 12:20:00',
	string $backup_at = '2026-08-26 11:00:00',
	string $installation_state = 'runtime_ready'
): Purge_Job_Snapshot {
	return new Purge_Job_Snapshot(
		41,
		g5_imp08_s04_id( 1 ),
		3,
		$state,
		$preview,
		hash( 'sha256', $canonical->encode( $preview ) ),
		$approved_at,
		$expires_at,
		hash( 'sha256', 'backup-evidence' ),
		$backup_at,
		$checkpoint,
		is_array( $checkpoint ) ? hash( 'sha256', $canonical->encode( $checkpoint ) ) : null,
		7,
		5,
		$installation_state
	);
}

/**
 * G5 imp08 s04 execute command.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $preview Preview.
 * @param int                 $seed Seed.
 * @return Execute_Purge_Command
 */
function g5_imp08_s04_execute_command( Canonical_Json $canonical, array $preview, int $seed = 2 ): Execute_Purge_Command {
	return new Execute_Purge_Command(
		77,
		g5_imp08_s04_id( 1 ),
		g5_imp08_s04_id( $seed ),
		'approved',
		(string) $preview['preview_hash'],
		hash( 'sha256', 'idempotency-' . $seed ),
		'corr-g5-s04-' . str_pad( (string) $seed, 4, '0', STR_PAD_LEFT ),
		$canonical
	);
}

/**
 * G5 imp08 s04 service.
 *
 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
 * @param Schema_Catalog          $catalog Catalog.
 * @param G5_Imp08_S04_Inventory  $inventory Inventory.
 * @param G5_Imp08_S04_Repository $repository Persistence boundary used by this operation.
 * @param G5_Imp08_S04_Queue      $queue Queue.
 * @return Execute_Purge
 */
function g5_imp08_s04_service(
	Canonical_Json $canonical,
	Schema_Catalog $catalog,
	G5_Imp08_S04_Inventory $inventory,
	G5_Imp08_S04_Repository $repository,
	G5_Imp08_S04_Queue $queue
): Execute_Purge {
	return new Execute_Purge(
		new Purge_Preview_Builder( $inventory, $catalog, $canonical ),
		new G5_Imp08_S04_Site(),
		$repository,
		$queue,
		new Purge_Target_Catalog( $catalog ),
		new Public_Id_Generator( static fn ( int $length ): string => str_repeat( chr( 30 ), $length ) ),
		$canonical,
		static fn (): string => '2026-08-26 12:00:00'
	);
}

$canonical                                  = new Canonical_Json();
$catalog                                    = new Schema_Catalog();
$owners                                     = array_fill_keys( array_keys( $catalog->owner_counts() ), 0 );
$owners[ Schema_Catalog::OWNER_DATABASE ]   = 2;
$owners[ Schema_Catalog::OWNER_OPERATIONS ] = 4;
$owners[ Schema_Catalog::OWNER_WORKSHEET ]  = 5;
$owners[ Schema_Catalog::OWNER_SUBMISSION ] = 3;
$owners[ Schema_Catalog::OWNER_STORAGE ]    = 2;
$jobs                                       = array(
	'evaluation_runs' => 0,
	'export_jobs'     => 0,
	'zip_uploads'     => 0,
	'import_runs'     => 0,
	'purge_jobs'      => 0,
);
$baseline                                   = new G5_Imp08_S04_Inventory( $owners, 2, 500, 0, $jobs );
$stored_preview                             = ( new Purge_Preview_Builder( $baseline, $catalog, $canonical ) )
	->build( Preview_Purge_Command::SCOPE_ADDON );

$current_owners                                      = $owners;
$current_owners[ Schema_Catalog::OWNER_OPERATIONS ] += 3;
$current_jobs                                        = $jobs;
$current_jobs['purge_jobs']                          = 1;
$current    = new G5_Imp08_S04_Inventory( $current_owners, 2, 500, 0, $current_jobs );
$snapshot   = g5_imp08_s04_job( $canonical, $stored_preview );
$repository = new G5_Imp08_S04_Repository( $snapshot, $canonical );
$queue      = new G5_Imp08_S04_Queue();
$service    = g5_imp08_s04_service( $canonical, $catalog, $current, $repository, $queue );
$command    = g5_imp08_s04_execute_command( $canonical, $stored_preview );
$result     = $service->execute( $command );

g5_imp08_s04_same( 'running', $result->state(), 'C73 did not start the approved purge.' );
g5_imp08_s04_same( 1, $repository->start_calls, 'C73 start was not durable exactly once.' );
g5_imp08_s04_same( array( $snapshot->public_id() ), $queue->jobs, 'C73 did not wake the exact purge job.' );
g5_imp08_s04_same( 43, $repository->start_record['checkpoint']['expected']['target_table_count'] ?? null, 'C73 target table count drifted.' );
g5_imp08_s04_same( 10, $repository->start_record['checkpoint']['expected']['target_row_count'] ?? null, 'C73 target row count included protected ledgers.' );
g5_imp08_s04_same( 'private_files', $repository->start_record['checkpoint']['cursor']['stage'] ?? null, 'C73 did not start with private bytes.' );
g5_imp08_s04_same( 250, $repository->start_record['checkpoint']['batch_size'] ?? null, 'C73 batch size drifted.' );
g5_imp08_s04_same( 20, $repository->start_record['checkpoint']['time_limit_seconds'] ?? null, 'C73 time boundary drifted.' );

$replayed = $service->execute( $command );
g5_imp08_s04_same( true, $replayed->replayed(), 'C73 durable replay was not returned.' );
g5_imp08_s04_same( 1, $repository->start_calls, 'C73 replay started a second purge.' );
g5_imp08_s04_same( 2, count( $queue->jobs ), 'C73 replay did not recover the worker wakeup.' );
$extra_owners = $current_owners;
++$extra_owners[ Schema_Catalog::OWNER_WORKSHEET ];
$extra_inventory  = new G5_Imp08_S04_Inventory( $extra_owners, 2, 500, 0, $current_jobs );
$extra_repository = new G5_Imp08_S04_Repository( $snapshot, $canonical );
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service( $canonical, $catalog, $extra_inventory, $extra_repository, new G5_Imp08_S04_Queue() )
		->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 3 ) ),
	Purge_Failure::PREVIEW_STALE,
	'C73 accepted data beyond the exact C72 +3/+1 control delta.'
);

$incomplete_jobs                    = $current_jobs;
$incomplete_jobs['evaluation_runs'] = 1;
$incomplete_inventory               = new G5_Imp08_S04_Inventory( $current_owners, 2, 500, 0, $incomplete_jobs );
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service(
		$canonical,
		$catalog,
		$incomplete_inventory,
		new G5_Imp08_S04_Repository( $snapshot, $canonical ),
		new G5_Imp08_S04_Queue()
	)->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 4 ) ),
	Purge_Failure::INCOMPLETE_JOBS_BLOCKED,
	'C73 accepted another incomplete job.'
);

$held_inventory = new G5_Imp08_S04_Inventory( $current_owners, 2, 500, 1, $current_jobs );
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service(
		$canonical,
		$catalog,
		$held_inventory,
		new G5_Imp08_S04_Repository( $snapshot, $canonical ),
		new G5_Imp08_S04_Queue()
	)->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 5 ) ),
	Purge_Failure::LEGAL_HOLD_BLOCKED,
	'C73 accepted a legal hold.'
);

$old_backup = g5_imp08_s04_job(
	$canonical,
	$stored_preview,
	'approved',
	null,
	'2026-08-26 11:50:00',
	'2026-08-26 12:20:00',
	'2026-08-25 11:59:00'
);
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service(
		$canonical,
		$catalog,
		$current,
		new G5_Imp08_S04_Repository( $old_backup, $canonical ),
		new G5_Imp08_S04_Queue()
	)->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 6 ) ),
	Purge_Failure::BACKUP_INVALID,
	'C73 accepted backup evidence older than 24 hours.'
);

$expired = g5_imp08_s04_job(
	$canonical,
	$stored_preview,
	'approved',
	null,
	'2026-08-26 10:00:00',
	'2026-08-26 10:30:00',
	'2026-08-26 09:30:00'
);
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service(
		$canonical,
		$catalog,
		$current,
		new G5_Imp08_S04_Repository( $expired, $canonical ),
		new G5_Imp08_S04_Queue()
	)->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 7 ) ),
	Purge_Failure::APPROVAL_EXPIRED,
	'C73 accepted an expired approval.'
);

$exactly_expired = g5_imp08_s04_job(
	$canonical,
	$stored_preview,
	'approved',
	null,
	'2026-08-26 11:30:00',
	'2026-08-26 12:00:00',
	'2026-08-26 11:00:00'
);
g5_imp08_s04_failure(
	static fn () => g5_imp08_s04_service(
		$canonical,
		$catalog,
		$current,
		new G5_Imp08_S04_Repository( $exactly_expired, $canonical ),
		new G5_Imp08_S04_Queue()
	)->execute( g5_imp08_s04_execute_command( $canonical, $stored_preview, 8 ) ),
	Purge_Failure::APPROVAL_EXPIRED,
	'C73 accepted approval at the exact expiry boundary.'
);

$running_checkpoint                           = $repository->start_record['checkpoint'];
$running_checkpoint['totals']['deleted_rows'] = 1;
$running_snapshot                             = g5_imp08_s04_job(
	$canonical,
	$stored_preview,
	'running',
	$running_checkpoint,
	'2026-08-26 11:50:00',
	'2026-08-26 12:20:00',
	'2026-08-26 11:00:00',
	'maintenance_required'
);
$cancel_repository                            = new G5_Imp08_S04_Repository( $running_snapshot, $canonical );
$cancel                                       = new Cancel_Purge(
	$cancel_repository,
	new G5_Imp08_S04_Site(),
	new Public_Id_Generator( static fn ( int $length ): string => str_repeat( chr( 31 ), $length ) ),
	static fn (): string => '2026-08-26 12:01:00'
);
$cancel_result                                = $cancel->execute(
	new Cancel_Purge_Command(
		77,
		$running_snapshot->public_id(),
		g5_imp08_s04_id( 8 ),
		'running',
		$running_snapshot->preview_hash(),
		hash( 'sha256', 'cancel-key' ),
		'corr-g5-s04-cancel',
		$canonical
	)
);
g5_imp08_s04_same( 'cancelled', $cancel_result->state(), 'C74 did not cancel at the batch boundary.' );
g5_imp08_s04_same( true, $cancel_result->maintenance_retained(), 'C74 released maintenance after partial deletion.' );
g5_imp08_s04_same( 'cancelled', $cancel_result->checkpoint()['phase'] ?? null, 'C74 checkpoint phase drifted.' );
g5_imp08_s04_same( 1, $cancel_repository->cancel_calls, 'C74 was not recorded exactly once.' );

$worker_repository                = new G5_Imp08_S04_Repository( $running_snapshot, $canonical );
$worker_queue                     = new G5_Imp08_S04_Queue();
$processor                        = new Process_Purge_Batch(
	$worker_repository,
	new G5_Imp08_S04_Site(),
	$worker_queue,
	static fn (): string => '2026-08-26 12:02:00'
);
$worker_repository->batch_failure = Purge_Failure::because( Purge_Failure::DELETE_FAILED );
g5_imp08_s04_failure(
	static fn () => $processor->execute( $running_snapshot->public_id() ),
	Purge_Failure::DELETE_FAILED,
	'C73 worker hid a retryable delete failure.'
);
g5_imp08_s04_same( 1, count( $worker_queue->jobs ), 'Retryable C73 failure did not wake recovery.' );
$worker_repository->batch_failure = null;
$worker_repository->batch_result  = new Purge_Batch_Result(
	$running_snapshot->public_id(),
	'running',
	$running_checkpoint,
	hash( 'sha256', $canonical->encode( $running_checkpoint ) )
);
$processor->execute( $running_snapshot->public_id() );
g5_imp08_s04_same( 2, count( $worker_queue->jobs ), 'Nonterminal C73 checkpoint did not wake the next batch.' );
$completed_checkpoint            = $running_checkpoint;
$completed_checkpoint['phase']   = 'completed';
$worker_repository->batch_result = new Purge_Batch_Result(
	$running_snapshot->public_id(),
	'completed',
	$completed_checkpoint,
	hash( 'sha256', $canonical->encode( $completed_checkpoint ) )
);
$processor->execute( $running_snapshot->public_id() );
g5_imp08_s04_same( 2, count( $worker_queue->jobs ), 'Terminal C73 checkpoint scheduled another batch.' );
$rest_repository = new G5_Imp08_S04_Repository( $snapshot, $canonical );
$rest_queue      = new G5_Imp08_S04_Queue();
$rest_execute    = g5_imp08_s04_service( $canonical, $catalog, $current, $rest_repository, $rest_queue );
$rest_cancel     = new Cancel_Purge(
	$rest_repository,
	new G5_Imp08_S04_Site(),
	new Public_Id_Generator( static fn ( int $length ): string => str_repeat( chr( 29 ), $length ) ),
	static fn (): string => '2026-08-26 12:03:00'
);
$policy          = new G5_Imp08_S04_Policy();
$correlations    = new Correlation_Id_Factory();
$envelopes       = new Rest_Envelope_Factory();
$permissions     = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new G5_Imp08_S04_Actor() ),
	new Rest_Request_Authorizer( $policy, new G5_Imp08_S04_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$controller      = new Purge_Control_Rest_Controller(
	$permissions,
	$rest_execute,
	$rest_cancel,
	$canonical,
	$envelopes,
	$correlations
);
$headers         = array(
	'X-WP-Nonce'      => 'nonce-g5-imp08-s04',
	'Idempotency-Key' => 'purge-s04-execute-key',
);
$execute_body    = array(
	'command_id' => g5_imp08_s04_id( 9 ),
	'expected'   => array(
		'state'        => 'approved',
		'preview_hash' => $snapshot->preview_hash(),
	),
	'payload'    => array(),
);
$execute_request = new G5_Imp08_S04_Request( $snapshot->public_id(), $execute_body, $headers );
g5_imp08_s04_same( true, $controller->permissions_execute( $execute_request ), 'C73 REST permission handoff failed.' );
g5_imp08_s04_same( Purge_Control_Rest_Controller::ACTION_EXECUTE, $policy->request?->action_key(), 'C73 policy action drifted.' );
g5_imp08_s04_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C73 capability drifted.' );
g5_imp08_s04_same( array( Access_Policy_Request::CORE_DELETE_PLUGINS ), $policy->request?->required_core_capabilities(), 'C73 delete_plugins AND gate drifted.' );
$execute_response = $controller->execute( $execute_request );
g5_imp08_s04_assert( $execute_response instanceof WP_REST_Response, 'C73 did not return the common REST envelope.' );
g5_imp08_s04_same( 202, $execute_response->get_status(), 'C73 REST status must be 202.' );
$safe_checkpoint = $execute_response->get_data()['data']['purge_job']['checkpoint'];
g5_imp08_s04_same(
	array( 'profile', 'phase', 'batch_size', 'time_limit_seconds', 'cursor', 'totals', 'postcondition', 'hash' ),
	array_keys( $safe_checkpoint ),
	'C73 safe checkpoint projection drifted.'
);
$safe_json = json_encode( $safe_checkpoint );
foreach ( array( 'scope_hash', 'preview_hash', 'maintenance', 'expected', 'storage_key', 'table_name' ) as $private_key ) {
	g5_imp08_s04_assert( ! str_contains( (string) $safe_json, $private_key ), 'C73 exposed private checkpoint detail: ' . $private_key );
}

$cancel_headers = array(
	'X-WP-Nonce'      => 'nonce-g5-imp08-s04',
	'Idempotency-Key' => 'purge-s04-cancel-key',
);
$cancel_body    = array(
	'command_id' => g5_imp08_s04_id( 10 ),
	'expected'   => array(
		'state'        => 'approved',
		'preview_hash' => $snapshot->preview_hash(),
	),
	'payload'    => array(),
);
$cancel_request = new G5_Imp08_S04_Request( $snapshot->public_id(), $cancel_body, $cancel_headers );
g5_imp08_s04_same( true, $controller->permissions_cancel( $cancel_request ), 'C74 REST permission handoff failed.' );
g5_imp08_s04_same( Purge_Control_Rest_Controller::ACTION_CANCEL, $policy->request?->action_key(), 'C74 policy action drifted.' );
g5_imp08_s04_same( array(), $policy->request?->required_core_capabilities(), 'C74 unexpectedly required delete_plugins.' );
$cancel_response = $controller->cancel( $cancel_request );
g5_imp08_s04_assert( $cancel_response instanceof WP_REST_Response, 'C74 did not return the common REST envelope.' );
g5_imp08_s04_same( 200, $cancel_response->get_status(), 'C74 REST status must be 200.' );
g5_imp08_s04_same( 'cancelled', $cancel_response->get_data()['data']['purge_job']['state'], 'C74 REST state drifted.' );
g5_imp08_s04_same( null, $cancel_response->get_data()['data']['purge_job']['checkpoint'], 'Pre-execution C74 exposed a checkpoint.' );

$module = new Purge_Control_Rest_Module( $controller );
$module->register();
$module->register();
g5_imp08_s04_same( 1, count( $GLOBALS['g5_imp08_s04_hooks']['rest_api_init'] ?? array() ), 'C73/C74 module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_imp08_s04_same( 2, count( $GLOBALS['g5_imp08_s04_routes'] ), 'C73/C74 routes were not registered exactly once.' );
g5_imp08_s04_same( Purge_Control_Rest_Controller::ROUTE_EXECUTE, $GLOBALS['g5_imp08_s04_routes'][0][1], 'C73 route drifted.' );
g5_imp08_s04_same( Purge_Control_Rest_Controller::ROUTE_CANCEL, $GLOBALS['g5_imp08_s04_routes'][1][1], 'C74 route drifted.' );

$bad_body            = $execute_body;
$bad_body['payload'] = array( 'reason' => 'not-allowed' );
$bad                 = $controller->permissions_execute( new G5_Imp08_S04_Request( $snapshot->public_id(), $bad_body, $headers ) );
g5_imp08_s04_assert( $bad instanceof WP_Error, 'C73 accepted a nonempty payload.' );
g5_imp08_s04_same( 422, $bad->get_error_data()['status'], 'C73 malformed body status drifted.' );

$targets   = new Purge_Target_Catalog( $catalog );
$protected = array(
	'oj_ai_installations',
	'oj_ai_schema_migrations',
	'oj_ai_audit_events',
	'oj_ai_outbox_events',
	'oj_ai_purge_jobs',
);
g5_imp08_s04_same( $protected, Purge_Target_Catalog::protected_tables(), 'C73 preserved ledger set drifted.' );
$expected_targets = array();
foreach ( array_reverse( $catalog->creation_order() ) as $definition ) {
	if ( ! in_array( $definition->name(), $protected, true ) ) {
		$expected_targets[] = $definition->name();
	}
}
g5_imp08_s04_same( $expected_targets, $targets->table_names(), 'C73 target order is not reverse dependency order.' );
g5_imp08_s04_same( 43, count( $targets->table_names() ), 'C73 target count is not exactly 43.' );
g5_imp08_s04_same( 10, $targets->expected_row_count( $stored_preview ), 'C73 target total included five control ledgers.' );

$repository_source = file_get_contents(
	$root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Operations' . DIRECTORY_SEPARATOR
	. 'Purge' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Purge_Operation_Repository.php'
);
g5_imp08_s04_assert( is_string( $repository_source ), 'C73 repository source could not be read.' );
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'TRUNCATE TABLE', 'ALTER TABLE' ) as $forbidden ) {
	g5_imp08_s04_assert( ! str_contains( $repository_source, $forbidden ), 'C73 repository contains forbidden marker: ' . $forbidden );
}
$file_delete    = strpos( $repository_source, '$this->files->delete( $storage_key );' );
$metadata_purge = strpos( $repository_source, "'UPDATE ' . self::TABLE_PRIVATE_FILES" );
g5_imp08_s04_assert( false !== $file_delete && false !== $metadata_purge && $file_delete < $metadata_purge, 'C73 did not delete private bytes before metadata purge.' );
foreach (
	array(
		'START TRANSACTION',
		'FOR UPDATE',
		'assert_maintenance_state',
		'Installation_State::MAINTENANCE_REQUIRED',
		'Execute_Purge::BATCH_SIZE',
		'Execute_Purge::TIME_LIMIT_SECONDS',
		'purge_postcondition_mismatch',
	) as $marker
) {
	g5_imp08_s04_assert( str_contains( $repository_source, $marker ), 'C73 repository marker is missing: ' . $marker );
}

echo "G5-IMP-08-S04 passed: C73 exact approval delta, maintenance, bounded reverse-order purge, private-byte ordering, durable replay/retry, and C74 safe cancellation are frozen.\n";
