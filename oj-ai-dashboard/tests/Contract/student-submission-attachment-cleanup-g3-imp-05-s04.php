<?php
/**
 * G3-IMP-05-S04 attachment expiration and cleanup recovery contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Cleanup;
use OJAI\Dashboard\Submission\Student\Application\Run_Submission_Attachment_Cleanup_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Cleanup_Item;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C27 id.
 *
 * @param int $number Number.
 * @return string
 */
function c27_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-05-S04 attachment expiration and cleanup recovery contract.
 */
final class C27_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Claims.
	 *
	 * @var array<int,array{now:string,stale_before:string}>
	 */
	public array $claims = array();
	/**
	 * Expirations.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $expirations = array();
	/**
	 * Purges.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $purges = array();
	/**
	 * Purge result.
	 *
	 * @var bool
	 */
	public bool $purge_result = true;
	/**
	 * Invoke delete.
	 *
	 * @var bool
	 */
	public bool $invoke_delete = true;

	/**
	 * Initialize c27 cleanup repository dependencies and state.
	 *
	 * @param Submission_Attachment_Cleanup_Item|null $claimed Claimed.
	 * @param Submission_Attachment_Cleanup_Item|null $expired Expired.
	 */
	public function __construct(
		public ?Submission_Attachment_Cleanup_Item $claimed,
		public ?Submission_Attachment_Cleanup_Item $expired = null
	) {
	}

	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
		$this->claims[] = array(
			'now'          => $now_utc,
			'stale_before' => $stale_before_utc,
		);
		$item           = $this->claimed;
		$this->claimed  = null;
		return $item;
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
		$this->expirations[] = array(
			'now'              => $now_utc,
			'audit_public_id'  => $audit_public_id,
			'outbox_public_id' => $outbox_public_id,
		);
		$item                = $this->expired;
		$this->expired       = null;
		return $item;
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
	public function purge_claimed(
		Submission_Attachment_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		$this->purges[] = array(
			'attachment_id'    => $item->attachment_id(),
			'file_id'          => $item->file_id(),
			'audit_public_id'  => $audit_public_id,
			'outbox_public_id' => $outbox_public_id,
			'occurred_at_utc'  => $occurred_at_utc,
		);
		if ( $this->invoke_delete ) {
			$delete( $item->storage_key() );
		}
		return $this->purge_result;
	}
}

/**
 * G3-IMP-05-S04 attachment expiration and cleanup recovery contract.
 */
final class C27_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Deletions.
	 *
	 * @var array<int,string>
	 */
	public array $deletions = array();
	/**
	 * Fail delete.
	 *
	 * @var bool
	 */
	public bool $fail_delete = false;

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
	 * Delete c27 private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function delete( string $storage_key ): void {
		$this->deletions[] = $storage_key;
		if ( $this->fail_delete ) {
			throw new RuntimeException( 'Injected S04 delete outage.' );
		}
	}
}

/**
 * C27 item.
 *
 * @param array<string,mixed> $overrides Overrides.
 * @return Submission_Attachment_Cleanup_Item
 */
function c27_item( array $overrides = array() ): Submission_Attachment_Cleanup_Item {
	return new Submission_Attachment_Cleanup_Item(
		array_replace(
			array(
				'attachment_id'           => c27_id( 2 ),
				'attachment_lock_version' => 3,
				'claimed_at_utc'          => '2026-08-22 11:00:00',
				'file_id'                 => c27_id( 3 ),
				'file_lock_version'       => 4,
				'owner_user_id'           => 7,
				'scan_state'              => 'clean',
				'sha256'                  => str_repeat( 'a', 64 ),
				'storage_key'             => 'submission-attachments/aa/answer.pdf',
				'submission_id'           => c27_id( 1 ),
			),
			$overrides
		)
	);
}

/**
 * C27 run.
 *
 * @param Submission_Attachment_Cleanup_Item|null $claimed Claimed.
 * @param Submission_Attachment_Cleanup_Item|null $expired Expired.
 * @param callable|null                           $configure Configure.
 * @return array{0:Run_Submission_Attachment_Cleanup_Result,1:C27_Cleanup_Repository,2:C27_Private_Files}
 */
function c27_run(
	?Submission_Attachment_Cleanup_Item $claimed,
	?Submission_Attachment_Cleanup_Item $expired = null,
	?callable $configure = null
): array {
	static $seed = 70;
	$repository  = new C27_Cleanup_Repository( $claimed, $expired );
	$files       = new C27_Private_Files();
	if ( null !== $configure ) {
		$configure( $repository, $files );
	}
	$ids    = new Public_Id_Generator(
		static function ( int $length ) use ( &$seed ): string {
			++$seed;
			return str_repeat( chr( $seed ), $length );
		}
	);
	$worker = new Run_Next_Submission_Attachment_Cleanup(
		$repository,
		$files,
		$ids,
		static fn (): string => '2026-08-22 11:00:00'
	);
	return array( $worker->run_once(), $repository, $files );
}

[ $claimed_result, $claimed_repository, $claimed_files ] = c27_run( c27_item() );
g3_same( Run_Submission_Attachment_Cleanup_Result::PURGED, $claimed_result->state(), 'An existing expired cleanup claim must purge.' );
g3_same( '2026-08-22 10:55:00', $claimed_repository->claims[0]['stale_before'], 'S04 purge retries must use the frozen five-minute backoff.' );
g3_same( 0, count( $claimed_repository->expirations ), 'An existing expired claim must be drained before new expiration.' );
g3_same( array( 'submission-attachments/aa/answer.pdf' ), $claimed_files->deletions, 'S04 must delete only the claimed private storage key.' );

[ $due_result, $due_repository, $due_files ] = c27_run( null, c27_item() );
g3_same( Run_Submission_Attachment_Cleanup_Result::PURGED, $due_result->state(), 'A newly expired attachment must continue to purge in the same sweep.' );
g3_same( 1, count( $due_repository->expirations ), 'S04 must durably expire a due staged attachment.' );
g3_same( 1, count( $due_repository->purges ), 'S04 must attempt purge after durable expiration.' );
g3_same( 1, count( $due_files->deletions ), 'A newly expired attachment must delete its private bytes.' );

[ $retry_result, $retry_repository, $retry_files ] = c27_run(
	c27_item(),
	null,
	static function ( C27_Cleanup_Repository $repository, C27_Private_Files $files ): void {
		unset( $repository );
		$files->fail_delete = true;
	}
);
g3_same( Run_Submission_Attachment_Cleanup_Result::RETRY_WAIT, $retry_result->state(), 'A binary deletion outage must remain retryable.' );
g3_same( 'attachment_cleanup_unavailable', $retry_result->reason_code(), 'S04 must expose only its stable internal retry reason.' );
g3_same( 1, count( $retry_repository->purges ), 'A deletion outage must retain one durable purge attempt.' );
g3_same( 1, count( $retry_files->deletions ), 'A retryable deletion failure must not silently skip the delete call.' );

[ $hold_result, $hold_repository, $hold_files ] = c27_run(
	c27_item(),
	null,
	static function ( C27_Cleanup_Repository $repository, C27_Private_Files $files ): void {
		unset( $files );
		$repository->invoke_delete = false;
		$repository->purge_result  = false;
	}
);
g3_same( Run_Submission_Attachment_Cleanup_Result::STALE, $hold_result->state(), 'A legal-hold or lock race must discard the stale purge claim.' );
g3_same( 0, count( $hold_files->deletions ), 'A legal-hold recheck must prevent byte deletion.' );
g3_same( 1, count( $hold_repository->purges ), 'A legal-hold race may attempt exactly one guarded purge.' );

$orphan                             = c27_item(
	array(
		'attachment_id'           => null,
		'attachment_lock_version' => null,
		'scan_state'              => 'pending',
	)
);
[ $orphan_result, , $orphan_files ] = c27_run( $orphan );
g3_same( Run_Submission_Attachment_Cleanup_Result::PURGED, $orphan_result->state(), 'An expired private staging orphan must be purged without an attachment row.' );
g3_same( 1, count( $orphan_files->deletions ), 'An orphan cleanup must delete its private bytes.' );

[ $idle_result, $idle_repository, $idle_files ] = c27_run( null );
g3_same( Run_Submission_Attachment_Cleanup_Result::IDLE, $idle_result->state(), 'S04 must be idle when no expired or due item exists.' );
g3_same( 1, count( $idle_repository->expirations ), 'An idle sweep must check due expiration after purge claims.' );
g3_same( 0, count( $idle_files->deletions ), 'An idle sweep must not touch private storage.' );

$repository_source   = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Attachment_Cleanup_Repository.php' );
$worker_source       = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Submission_Attachment_Cleanup_Worker_Module.php' );
$composition_source  = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
$deactivation_source = (string) file_get_contents( $addon_root . '/src/Core/Deactivation.php' );
$stage_source        = (string) file_get_contents( $addon_root . '/src/Submission/Student/Application/Stage_Own_Attachment.php' );
foreach ( array( $repository_source, $worker_source, $composition_source, $deactivation_source, $stage_source ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S04 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S04 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S04 must not consume OJ Platform internals.' );
}
foreach ( array(
	"'oj_ai_submission_attachments'",
	"'oj_ai_private_files'",
	"'oj_ai_audit_events'",
	"'oj_ai_outbox_events'",
	'START TRANSACTION',
	'FOR UPDATE',
	'expires_at_utc <= %s',
	'legal_hold_until_utc IS NULL',
	"'expired'",
	"'purged'",
	'submission.attachment.expired',
	'submission.attachment.purged',
) as $marker ) {
	g3_assert( str_contains( $repository_source, $marker ), 'S04 durable cleanup marker is missing: ' . $marker );
}
$expiration_lock      = Ojai_Php_Source_Contract::position( $repository_source, '$attachment = $this->attachment_by_file( $file_id, true );' );
$expiration_file_lock = Ojai_Php_Source_Contract::position( $repository_source, '$file = $this->private_file( $file_id, true );' );
$purge_lock           = Ojai_Php_Source_Contract::position( $repository_source, '$attachment = $this->attachment_by_file( $item->file_id(), true );' );
$purge_file_lock      = Ojai_Php_Source_Contract::position( $repository_source, '$file = $this->private_file( $item->file_id(), true );' );
$delete_call          = Ojai_Php_Source_Contract::position( $repository_source, '$delete( $item->storage_key() );' );
g3_assert( false !== $expiration_lock && false !== $expiration_file_lock && $expiration_lock < $expiration_file_lock, 'S04 expiration must lock attachment before private file.' );
g3_assert( false !== $purge_lock && false !== $purge_file_lock && false !== $delete_call && $purge_lock < $purge_file_lock && $purge_file_lock < $delete_call, 'S04 purge must lock attachment then private file before byte deletion.' );
foreach ( array( 'oj_ai_dashboard_submission_attachment_cleanup_sweep', 'oj_ai_dashboard_every_minute', 'wp_schedule_event', 'run_once' ) as $marker ) {
	g3_assert( str_contains( $worker_source, $marker ), 'S04 cleanup worker marker is missing: ' . $marker );
}
g3_assert( str_contains( $composition_source, 'Submission_Attachment_Cleanup_Worker_Module' ), 'The single Composition Root must wire S04.' );
g3_assert( str_contains( $deactivation_source, 'Submission_Attachment_Cleanup_Worker_Module::clear()' ), 'Deactivation must clear the S04 schedule.' );
$compensation_start = strpos( $stage_source, 'private function compensate_metadata_and_file' );
$compensation_end   = false === $compensation_start ? false : strpos( $stage_source, 'private function compensate_file', $compensation_start );
$compensation       = false === $compensation_start || false === $compensation_end ? '' : substr( $stage_source, $compensation_start, $compensation_end - $compensation_start );
g3_assert( strpos( $compensation, 'files->delete' ) < strpos( $compensation, 'metadata->mark_purged' ), 'C24 compensation must delete bytes before recording purged metadata.' );

echo "G3-IMP-05-S04 passed: 24-hour expiration, orphan cleanup, legal-hold lock, retry backoff, and idempotent byte purge.\n";
