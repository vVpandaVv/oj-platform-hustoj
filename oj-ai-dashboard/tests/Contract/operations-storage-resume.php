<?php
/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 * (loop-until-idle, bounded iterations, per-worker exception isolation) and the
 * Storage_Jobs_Rest_Controller permission gate, exact-shape body, and envelope.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\Diagnostics\Application\Resume_Storage_Jobs;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Storage_Jobs_Rest_Controller;
use OJAI\Dashboard\Operations\Diagnostics\Presentation\Storage_Jobs_Rest_Module;
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
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
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
	 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
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

$GLOBALS['osr_hooks']  = array();
$GLOBALS['osr_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['osr_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['osr_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Osr assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function osr_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Osr same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function osr_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Osr id.
 *
 * @param int $number Number.
 * @return string
 */
function osr_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * Never claims anything -- the cheapest possible IDLE worker.
 */
final class OSR_Idle_Scan_Repository implements Student_Attachment_Scan_Repository_Port_Interface {
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
		throw new RuntimeException( 'OSR idle scan repository must never complete an item.' );
	}
}

/**
 * Always claims a metadata-mismatched item, forcing an immediate (cheap) non-IDLE reject every tick.
 */
final class OSR_Always_Due_Scan_Repository implements Student_Attachment_Scan_Repository_Port_Interface {
	/**
	 * Claim calls.
	 *
	 * @var int
	 */
	public int $claim_calls = 0;
	/**
	 * Complete calls.
	 *
	 * @var int
	 */
	public int $complete_calls = 0;
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Scan_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Scan_Item {
		++$this->claim_calls;
		return new Submission_Attachment_Scan_Item(
			array(
				'attachment_byte_size'     => 10,
				'attachment_id'            => osr_id( 2 ),
				'attachment_lock_version'  => 0,
				'attachment_mime_type'     => 'application/json',
				'attachment_original_name' => 'answer.json',
				'attachment_sha256'        => str_repeat( 'a', 64 ),
				'claimed_at_utc'           => '2026-08-22 11:00:00',
				'file_byte_size'           => 99,
				'file_id'                  => osr_id( 3 ),
				'file_lock_version'        => 1,
				'file_mime_type'           => 'application/json',
				'file_original_name'       => 'answer.json',
				'file_sha256'              => str_repeat( 'a', 64 ),
				'owner_user_id'            => 7,
				'response_id'              => osr_id( 4 ),
				'storage_key'              => 'submission-attachments/aa/answer.json',
				'submission_id'            => osr_id( 1 ),
			)
		);
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
	 */
	public function complete( Submission_Attachment_Scan_Item $item, string $outcome, ?string $reason_code, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		++$this->complete_calls;
		return true;
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Idle_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
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
		throw new RuntimeException( 'OSR idle cleanup repository must never purge an item.' );
	}
}

/**
 * Claims exactly $remaining items, then reports idle.
 */
final class OSR_Counting_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Purge calls.
	 *
	 * @var int
	 */
	public int $purge_calls = 0;
	/**
	 * Initialize osr counting cleanup repository dependencies and state.
	 *
	 * @param int  $remaining Remaining.
	 * @param bool $stale Stale.
	 */
	public function __construct( private int $remaining, private bool $stale = false ) {
	}
	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
		if ( $this->remaining <= 0 ) {
			return null;
		}
		--$this->remaining;
		return new Submission_Attachment_Cleanup_Item(
			array(
				'attachment_id'           => osr_id( 2 ),
				'attachment_lock_version' => 3,
				'claimed_at_utc'          => '2026-08-22 11:00:00',
				'file_id'                 => osr_id( 3 ),
				'file_lock_version'       => 4,
				'owner_user_id'           => 7,
				'scan_state'              => 'clean',
				'sha256'                  => str_repeat( 'a', 64 ),
				'storage_key'             => 'submission-attachments/aa/answer.pdf',
				'submission_id'           => osr_id( 1 ),
			)
		);
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
	 */
	public function purge_claimed( Submission_Attachment_Cleanup_Item $item, callable $delete, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		++$this->purge_calls;
		if ( $this->stale ) {
			return false;
		}
		$delete( $item->storage_key() );
		return true;
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Idle_Zip_Scan_Repository implements Submission_Zip_Scan_Repository_Port_Interface {
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
		throw new RuntimeException( 'OSR idle ZIP scan repository must never complete a job.' );
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
		throw new RuntimeException( 'OSR idle ZIP scan repository must never reject a job.' );
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Idle_Zip_Cleanup_Repository implements Submission_Zip_Cleanup_Repository_Port_Interface {
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
		throw new RuntimeException( 'OSR idle ZIP cleanup repository must never purge an item.' );
	}
}

/**
 * Simulates a transient repository connectivity failure at claim time.
 */
final class OSR_Throwing_Zip_Cleanup_Repository implements Submission_Zip_Cleanup_Repository_Port_Interface {
	/**
	 * Claim calls.
	 *
	 * @var int
	 */
	public int $claim_calls = 0;
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Cleanup_Item {
		++$this->claim_calls;
		throw new RuntimeException( 'Injected OSR ZIP cleanup outage.' );
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
		throw new RuntimeException( 'OSR throwing ZIP cleanup repository must never purge an item.' );
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Noop_File_Store implements Private_File_Store_Port_Interface {
	/**
	 * Deletions.
	 *
	 * @var array<int,string>
	 */
	public array $deletions = array();
	/**
	 * Initialize osr noop file store dependencies and state.
	 *
	 * @param int|null $fail_after Fail after.
	 */
	public function __construct( private ?int $fail_after = null ) {
	}
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
		throw new RuntimeException( 'OSR noop file store must never quarantine a file.' );
	}
	/**
	 * Delete osr noop file store.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function delete( string $storage_key ): void {
		if ( null !== $this->fail_after && count( $this->deletions ) >= $this->fail_after ) {
			throw new RuntimeException( 'Injected file deletion failure.' );
		}
		$this->deletions[] = $storage_key;
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Unreachable_File_Read implements Private_File_Read_Port_Interface {
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		throw new RuntimeException( 'OSR unreachable file read must never be called.' );
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Unreachable_File_Staging implements Private_File_Staging_Port_Interface {
	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		throw new RuntimeException( 'OSR unreachable file staging must never be called.' );
	}
	/**
	 * Insert quarantined.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_quarantined( array $record ): void {
		throw new RuntimeException( 'OSR unreachable file staging must never be called.' );
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
		throw new RuntimeException( 'OSR unreachable file staging must never be called.' );
	}
}

/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Unreachable_Malware implements Submission_Attachment_Malware_Scanner_Port_Interface {
	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		throw new RuntimeException( 'OSR unreachable malware scanner must never be called.' );
	}
}

/**
 * Osr scan worker.
 *
 * @param Student_Attachment_Scan_Repository_Port_Interface $repository Persistence boundary used by this operation.
 * @return Run_Next_Submission_Attachment_Scan
 */
function osr_scan_worker( Student_Attachment_Scan_Repository_Port_Interface $repository ) {
	return new Run_Next_Submission_Attachment_Scan(
		$repository,
		new OSR_Unreachable_File_Read(),
		new OSR_Unreachable_Malware(),
		new Submission_Attachment_Content_Inspector(),
		new Public_Id_Generator()
	);
}

/**
 * Osr cleanup worker.
 *
 * @param Student_Attachment_Cleanup_Repository_Port_Interface $repository Persistence boundary used by this operation.
 * @param Private_File_Store_Port_Interface|null               $files Files.
 * @return Run_Next_Submission_Attachment_Cleanup
 */
function osr_cleanup_worker( Student_Attachment_Cleanup_Repository_Port_Interface $repository, ?Private_File_Store_Port_Interface $files = null ) {
	return new Run_Next_Submission_Attachment_Cleanup( $repository, $files ?? new OSR_Noop_File_Store(), new Public_Id_Generator() );
}

/**
 * Osr zip scan worker.
 *
 * @param Submission_Zip_Scan_Repository_Port_Interface $repository Persistence boundary used by this operation.
 * @return Run_Next_Submission_Zip_Scan
 */
function osr_zip_scan_worker( Submission_Zip_Scan_Repository_Port_Interface $repository ) {
	$profile = new Submission_Zip_Upload_Profile();
	$reader  = new Submission_Zip_Entry_Reader( $profile );
	return new Run_Next_Submission_Zip_Scan(
		$repository,
		new OSR_Unreachable_File_Read(),
		new OSR_Noop_File_Store(),
		new OSR_Unreachable_File_Staging(),
		new OSR_Unreachable_Malware(),
		$reader,
		new Submission_Zip_Item_Content_Inspector( $reader ),
		new Submission_Zip_Suggestion_Matcher(),
		new Public_Id_Generator()
	);
}

/**
 * Osr zip cleanup worker.
 *
 * @param Submission_Zip_Cleanup_Repository_Port_Interface $repository Persistence boundary used by this operation.
 * @return Run_Next_Submission_Zip_Cleanup
 */
function osr_zip_cleanup_worker( Submission_Zip_Cleanup_Repository_Port_Interface $repository ) {
	return new Run_Next_Submission_Zip_Cleanup( $repository, new OSR_Noop_File_Store(), new Public_Id_Generator() );
}

// --- Scenario A: every worker is idle -> zero iterations, nothing else touched. ---
/**
 * Osr export cleanup worker.
 *
 * @param string $state State.
 * @return \OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup
 */
function osr_export_cleanup_worker( string $state = 'idle' ): \OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup {
	$repository = new class( $state ) implements \OJAI\Dashboard\Reporting\Export\Port\Export_Cleanup_Repository_Port_Interface {
		/**
		 * Initialize operations-storage-resume dependencies and state.
		 *
		 * @param string $state State.
		 */
		public function __construct( private string $state ) {
		}
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
			return $this->state;
		}
	};
	return new \OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup( $repository, new OSR_Noop_File_Store(), new Public_Id_Generator() );
}

$idle_result = ( new Resume_Storage_Jobs(
	osr_scan_worker( new OSR_Idle_Scan_Repository() ),
	osr_cleanup_worker( new OSR_Idle_Cleanup_Repository() ),
	osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
	osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
	osr_export_cleanup_worker()
) )->execute();
osr_same( 0, $idle_result->total_iterations(), 'An idle backlog produced a nonzero iteration count.' );
osr_same( true, $idle_result->all_succeeded(), 'An idle backlog was not reported as fully succeeded.' );
osr_same(
	array(
		'attachment_scan'    => array(
			'iterations' => 0,
			'succeeded'  => true,
		),
		'attachment_cleanup' => array(
			'iterations' => 0,
			'succeeded'  => true,
		),
		'zip_scan'           => array(
			'iterations' => 0,
			'succeeded'  => true,
		),
		'zip_cleanup'        => array(
			'iterations' => 0,
			'succeeded'  => true,
		),
		'export_cleanup'     => array(
			'iterations' => 0,
			'succeeded'  => true,
		),
	),
	$idle_result->projection(),
	'Idle projection shape drifted.'
);

// --- Scenario B: attachment cleanup actually drains three items, then reports idle. ---
$counting_cleanup = new OSR_Counting_Cleanup_Repository( 3 );
$drained_result   = ( new Resume_Storage_Jobs(
	osr_scan_worker( new OSR_Idle_Scan_Repository() ),
	osr_cleanup_worker( $counting_cleanup ),
	osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
	osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
	osr_export_cleanup_worker()
) )->execute();
osr_same( 3, $drained_result->attachment_cleanup()->iterations(), 'A three-item backlog was not fully drained in one request.' );
osr_same( true, $drained_result->attachment_cleanup()->succeeded(), 'A successfully drained worker was marked failed.' );
osr_same( 3, $counting_cleanup->purge_calls, 'The cleanup repository was not asked to purge exactly three claimed items.' );
osr_same( 3, $drained_result->total_iterations(), 'Total iterations did not sum the single active worker.' );

// --- Scenario C: one worker's claim throws (a transient repository outage); the rest are unaffected. ---
foreach ( array( 'retry_wait', 'stale', 'partial' ) as $scenario ) {
	$repository = new OSR_Counting_Cleanup_Repository( 10, 'stale' === $scenario );
	$files      = new OSR_Noop_File_Store( 'partial' === $scenario ? 1 : 0 );
	$result     = ( new Resume_Storage_Jobs(
		osr_scan_worker( new OSR_Idle_Scan_Repository() ),
		osr_cleanup_worker( $repository, $files ),
		osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
		osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
		osr_export_cleanup_worker()
	) )->execute();
	$completed  = 'partial' === $scenario ? 1 : 0;
	osr_same( false, $result->all_succeeded(), $scenario . ' must not report success.' );
	osr_same( $completed, $result->attachment_cleanup()->iterations(), $scenario . ' must count only completed purges.' );
	osr_same( $completed + 1, $repository->purge_calls, $scenario . ' must stop at the first incomplete claim.' );
	osr_same( true, $result->zip_cleanup()->succeeded(), 'Incomplete attachment work must not block ZIP work.' );
}

$throwing_zip_cleanup = new OSR_Throwing_Zip_Cleanup_Repository();
$partial_result       = ( new Resume_Storage_Jobs(
	osr_scan_worker( new OSR_Idle_Scan_Repository() ),
	osr_cleanup_worker( new OSR_Idle_Cleanup_Repository() ),
	osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
	osr_zip_cleanup_worker( $throwing_zip_cleanup ),
	osr_export_cleanup_worker()
) )->execute();
osr_same( false, $partial_result->zip_cleanup()->succeeded(), 'A throwing worker was reported as succeeded.' );
osr_same( 0, $partial_result->zip_cleanup()->iterations(), 'A worker that failed on its first claim reported a nonzero count.' );
osr_same( true, $partial_result->attachment_scan()->succeeded(), 'One worker throwing marked an unrelated worker as failed.' );
osr_same( true, $partial_result->attachment_cleanup()->succeeded(), 'One worker throwing marked an unrelated worker as failed.' );
osr_same( true, $partial_result->zip_scan()->succeeded(), 'One worker throwing marked an unrelated worker as failed.' );
osr_same( false, $partial_result->all_succeeded(), 'A partial failure was reported as a full success.' );
osr_assert( $throwing_zip_cleanup->claim_calls >= 1, 'The failing worker was never actually attempted.' );

// --- Scenario D: a backlog that never reports IDLE is still bounded by the per-request iteration cap. ---
$always_due_scan = new OSR_Always_Due_Scan_Repository();
$capped_result   = ( new Resume_Storage_Jobs(
	osr_scan_worker( $always_due_scan ),
	osr_cleanup_worker( new OSR_Idle_Cleanup_Repository() ),
	osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
	osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
	osr_export_cleanup_worker()
) )->execute();
osr_same( Resume_Storage_Jobs::MAX_ITERATIONS_PER_WORKER, $capped_result->attachment_scan()->iterations(), 'An endless backlog was not bounded by the per-worker iteration cap.' );
osr_same( true, $capped_result->attachment_scan()->succeeded(), 'Hitting the iteration cap was reported as a worker failure.' );
osr_same( Resume_Storage_Jobs::MAX_ITERATIONS_PER_WORKER, $always_due_scan->claim_calls, 'The bounded worker was claimed a different number of times than it ticked.' );
osr_same( Resume_Storage_Jobs::MAX_ITERATIONS_PER_WORKER, $always_due_scan->complete_calls, 'The bounded worker did not resolve every claimed item.' );

// --- REST boundary: permission gate, exact body, envelope shape, and safe-to-repeat idempotence. ---
/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Current_Actor implements Current_Actor_Port_Interface {
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
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify osr nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'osr-nonce' === $nonce;
	}
}
/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Policy implements Access_Policy_Port_Interface {
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
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Request {
	/**
	 * Initialize osr request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param string              $idempotency Idempotency.
	 */
	public function __construct( private array $body, private string $idempotency = 'storage-resume-request-0001' ) {
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
			'x-wp-nonce' => 'osr-nonce',
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

$policy       = new OSR_Policy();
$correlations = new Correlation_Id_Factory( static fn (): string => 'osr-correlation-0001' );
$envelopes    = new Rest_Envelope_Factory();
$handoff      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new OSR_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new OSR_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$rest_command = new Resume_Storage_Jobs(
	osr_scan_worker( new OSR_Idle_Scan_Repository() ),
	osr_cleanup_worker( new OSR_Counting_Cleanup_Repository( 2 ) ),
	osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
	osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
	osr_export_cleanup_worker()
);
$controller   = new Storage_Jobs_Rest_Controller( $handoff, $rest_command, $envelopes, $correlations );
$module       = new Storage_Jobs_Rest_Module( $controller );
$module->register();
$module->register();
osr_same( 1, count( $GLOBALS['osr_hooks']['rest_api_init'] ?? array() ), 'Storage jobs module registered its REST lifecycle more than once.' );
( $GLOBALS['osr_hooks']['rest_api_init'][0] )();
$controller->register_routes();
osr_same( 1, count( $GLOBALS['osr_routes'] ), 'Storage jobs route registered more than once.' );
osr_same( 'oj-ai/v1', $GLOBALS['osr_routes'][0][0], 'Storage jobs REST namespace drifted.' );
osr_same( '/operations/storage/commands/resume', $GLOBALS['osr_routes'][0][1], 'Storage jobs REST route drifted.' );
osr_same( 'POST', $GLOBALS['osr_routes'][0][2]['methods'], 'Storage jobs route is not POST-only.' );

$request = new OSR_Request( array( 'command_id' => 'storage-resume-command-0001' ) );
osr_same( true, $controller->permissions_resume( $request ), 'Storage jobs permission handoff failed for an allowed actor.' );
osr_assert( $policy->last_request instanceof Access_Policy_Request, 'Storage jobs controller did not call the common access policy.' );
osr_same( Access_Policy_Request::SHELL_CONSOLE, $policy->last_request->shell(), 'Storage jobs bypassed the Console shell policy.' );
osr_same( 'operations.storage.resume', $policy->last_request->action_key(), 'Storage jobs action key drifted.' );
osr_same( array( Addon_Capabilities::MANAGE_POLICIES ), $policy->last_request->required_capabilities(), 'Storage jobs capability gate drifted from the diagnostics settings Page gate.' );

$response = $controller->resume( $request );
osr_assert( $response instanceof WP_REST_Response, 'Storage jobs did not return the common success envelope.' );
osr_same( 200, $response->get_status(), 'Storage jobs success status drifted.' );
$payload = $response->get_data();
osr_same( array( 'attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup' ), array_keys( $payload['data']['storage_jobs']['workers'] ), 'Storage jobs response worker keys drifted.' );
osr_same( 2, $payload['data']['storage_jobs']['workers']['attachment_cleanup']['iterations'], 'Storage jobs response lost the real drained count.' );
osr_same( 2, $payload['meta']['total_iterations'], 'Storage jobs response meta total drifted.' );
osr_same( true, $payload['meta']['all_succeeded'], 'Storage jobs response meta success flag drifted.' );
osr_same( 'osr-correlation-0001', $payload['meta']['correlation_id'], 'Storage jobs response lost its correlation ID.' );
osr_same( array(), $payload['actions'], 'Storage jobs response exposed an unexpected follow-up action.' );

// Calling the exact same allowed request again is safe -- no idempotency receipt cache to conflict,
// only the per-item repository claims underneath, which this fake already reports as exhausted (idle).
$second_request = new OSR_Request( array( 'command_id' => 'storage-resume-command-0002' ) );
osr_same( true, $controller->permissions_resume( $second_request ), 'Storage jobs permission handoff failed on a repeat call.' );
$second_response = $controller->resume( $second_request );
osr_same( 200, $second_response->get_status(), 'Repeating the safe-to-repeat command failed on its second call.' );
osr_same( 0, $second_response->get_data()['meta']['total_iterations'], 'A second call did not observe the already-drained backlog as idle.' );

// A denied capability must fail closed before any worker is touched.
$denied_policy             = new OSR_Policy();
$denied_policy->allowed    = false;
$denied_command_repository = new OSR_Counting_Cleanup_Repository( 5 );
$denied_handoff            = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new OSR_Current_Actor() ),
	new Rest_Request_Authorizer( $denied_policy, new OSR_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$denied_controller         = new Storage_Jobs_Rest_Controller(
	$denied_handoff,
	new Resume_Storage_Jobs(
		osr_scan_worker( new OSR_Idle_Scan_Repository() ),
		osr_cleanup_worker( $denied_command_repository ),
		osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
		osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
		osr_export_cleanup_worker()
	),
	$envelopes,
	$correlations
);
$denied_request            = new OSR_Request( array( 'command_id' => 'storage-resume-command-denied' ) );
$denied                    = $denied_controller->permissions_resume( $denied_request );
osr_assert( $denied instanceof WP_Error, 'A denied capability was not rejected.' );
osr_same( 'oj_ai_access_denied', $denied->get_error_code(), 'Denied storage jobs error code drifted.' );
osr_same( 0, $denied_command_repository->purge_calls, 'A denied permission still let the cleanup worker run before the command was ever executed.' );

// Missing Idempotency-Key must fail the permission handoff before the command ever runs.
$missing_key_request = new OSR_Request( array( 'command_id' => 'storage-resume-command-no-key' ), '' );
$missing_key_result  = $controller->permissions_resume( $missing_key_request );
osr_assert( $missing_key_result instanceof WP_Error, 'A missing Idempotency-Key was accepted.' );
osr_same( 'oj_ai_validation_failed', $missing_key_result->get_error_code(), 'Missing Idempotency-Key error code drifted.' );

// The request body must be the exact {command_id} shape -- nothing more, nothing less.
/**
 * Manual storage-worker resume command contract: Resume_Storage_Jobs orchestration
 */
final class OSR_Shape_Policy implements Access_Policy_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}
$shape_handoff       = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new OSR_Current_Actor() ),
	new Rest_Request_Authorizer( new OSR_Shape_Policy(), new OSR_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$shape_controller    = new Storage_Jobs_Rest_Controller(
	$shape_handoff,
	new Resume_Storage_Jobs(
		osr_scan_worker( new OSR_Idle_Scan_Repository() ),
		osr_cleanup_worker( new OSR_Idle_Cleanup_Repository() ),
		osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
		osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
		osr_export_cleanup_worker()
	),
	$envelopes,
	$correlations
);
$extra_field_request = new OSR_Request(
	array(
		'command_id' => 'storage-resume-extra',
		'iterations' => 999,
	)
);
osr_same( true, $shape_controller->permissions_resume( $extra_field_request ), 'Shape validation ran before permission handoff unexpectedly.' );
$extra_field_response = $shape_controller->resume( $extra_field_request );
osr_assert( $extra_field_response instanceof WP_Error, 'An extra request-body field was accepted.' );
osr_same( 'oj_ai_validation_failed', $extra_field_response->get_error_code(), 'Extra-field rejection error code drifted.' );

$empty_command_request = new OSR_Request( array( 'command_id' => '' ) );
osr_same( true, $shape_controller->permissions_resume( $empty_command_request ), 'Storage jobs permission handoff failed for an empty command_id request.' );
$empty_command_response = $shape_controller->resume( $empty_command_request );
osr_assert( $empty_command_response instanceof WP_Error, 'An empty command_id was accepted.' );
osr_same( 'oj_ai_validation_failed', $empty_command_response->get_error_code(), 'Empty command_id rejection error code drifted.' );

foreach ( array( 'purged', 'retry_wait', 'stale' ) as $export_state ) {
	$result = ( new Resume_Storage_Jobs(
		osr_scan_worker( new OSR_Idle_Scan_Repository() ),
		osr_cleanup_worker( new OSR_Idle_Cleanup_Repository() ),
		osr_zip_scan_worker( new OSR_Idle_Zip_Scan_Repository() ),
		osr_zip_cleanup_worker( new OSR_Idle_Zip_Cleanup_Repository() ),
		osr_export_cleanup_worker( $export_state )
	) )->execute();
	$export = $result->projection()['export_cleanup'];
	osr_same( 'purged' === $export_state, $export['succeeded'], 'Export cleanup failure was counted as success.' );
	osr_same( 'purged' === $export_state ? Resume_Storage_Jobs::MAX_ITERATIONS_PER_WORKER : 0, $export['iterations'], 'Export cleanup cap or completed count drifted.' );
	osr_same( true, $result->attachment_cleanup()->succeeded(), 'Export cleanup failure broke unrelated workers.' );
}
fwrite( STDOUT, "Operations storage resume command passed: five bounded workers, export success/failure/cap, exception isolation, manage_policies gate, exact command_id body, safe-to-repeat REST envelope.\n" );
