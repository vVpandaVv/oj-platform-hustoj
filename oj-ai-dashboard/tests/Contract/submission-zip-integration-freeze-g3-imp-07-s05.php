<?php
/**
 * G3-IMP-07-S05 submission ZIP integration and deferred cleanup freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Cleanup;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Submission_Zip_Cleanup_Result;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-07-S05 submission ZIP integration and deferred cleanup freeze.
 */
final class S05_Zip_Cleanup_Port implements Submission_Zip_Cleanup_Repository_Port_Interface {
	/**
	 * Queue.
	 *
	 * @var array<int,Submission_Zip_Cleanup_Item>
	 */
	public array $queue = array();
	/**
	 * Outcomes.
	 *
	 * @var array<int,bool|null|string>
	 */
	public array $outcomes = array();
	/**
	 * Purges.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $purges = array();
	/**
	 * Claimed now.
	 *
	 * @var string|null
	 */
	public ?string $claimed_now = null;
	/**
	 * Claimed stale before.
	 *
	 * @var string|null
	 */
	public ?string $claimed_stale_before = null;

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Cleanup_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Cleanup_Item {
		$this->claimed_now          = $now_utc;
		$this->claimed_stale_before = $stale_before_utc;
		return array_shift( $this->queue );
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
	public function purge_claimed(
		Submission_Zip_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): ?bool {
		$outcome = array_shift( $this->outcomes );
		if ( 'throw' === $outcome ) {
			throw new RuntimeException( 'storage unavailable' );
		}
		if ( null === $outcome ) {
			return null;
		}
		$delete( $item->storage_key() );
		$this->purges[] = array(
			'audit_public_id'  => $audit_public_id,
			'file_id'          => $item->file_id(),
			'file_kind'        => $item->file_kind(),
			'occurred_at_utc'  => $occurred_at_utc,
			'outbox_public_id' => $outbox_public_id,
			'upload_id'        => $item->upload_id(),
		);
		return $outcome;
	}
}

/**
 * G3-IMP-07-S05 submission ZIP integration and deferred cleanup freeze.
 */
final class S05_Zip_Files implements Private_File_Store_Port_Interface {
	/**
	 * Deleted.
	 *
	 * @var array<int,string>
	 */
	public array $deleted = array();
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
	 * Delete s05 zip files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function delete( string $storage_key ): void {
		if ( $this->fail_delete ) {
			throw new RuntimeException( 'delete failed' );
		}
		$this->deleted[] = $storage_key;
	}
}

/**
 * S05 zip cleanup record.
 *
 * @param array<string,mixed> $overrides Overrides.
 * @return array
 */
function s05_zip_cleanup_record( array $overrides = array() ): array {
	global $s05_zip_assignment_id, $s05_zip_upload_id, $s05_zip_source_file_id;
	return array_replace(
		array(
			'assignment_id'        => $s05_zip_assignment_id,
			'claimed_at_utc'       => '2026-08-22 14:30:00',
			'file_id'              => $s05_zip_source_file_id,
			'file_kind'            => 'source',
			'file_lock_version'    => 8,
			'file_owner_public_id' => $s05_zip_assignment_id,
			'file_owner_type'      => 'assignment',
			'file_state'           => 'ready',
			'item_no'              => null,
			'owner_user_id'        => 42,
			'scan_state'           => 'clean',
			'sha256'               => hash( 'sha256', 'zip-cleanup-source' ),
			'storage_key'          => 'submission-zips/source/source.zip',
			'upload_id'            => $s05_zip_upload_id,
			'upload_lock_version'  => 12,
		),
		$overrides
	);
}

$s05_zip_seed           = 81;
$s05_zip_ids            = new Public_Id_Generator(
	static function ( int $length ) use ( &$s05_zip_seed ): string {
		++$s05_zip_seed;
		return str_repeat( chr( $s05_zip_seed ), $length );
	}
);
$s05_zip_assignment_id  = $s05_zip_ids->create()->value();
$s05_zip_upload_id      = $s05_zip_ids->create()->value();
$s05_zip_source_file_id = $s05_zip_ids->create()->value();
$s05_zip_item_file_id   = $s05_zip_ids->create()->value();

$s05_source = new Submission_Zip_Cleanup_Item( s05_zip_cleanup_record() );
$s05_item   = new Submission_Zip_Cleanup_Item(
	s05_zip_cleanup_record(
		array(
			'file_id'              => $s05_zip_item_file_id,
			'file_kind'            => 'item',
			'file_owner_public_id' => $s05_zip_upload_id,
			'file_owner_type'      => 'submission_zip',
			'file_state'           => 'quarantined',
			'item_no'              => 2,
			'sha256'               => hash( 'sha256', 'zip-cleanup-item' ),
			'storage_key'          => 'submission-zips/items/item-0002.pdf',
		)
	)
);

$s05_idle_port   = new S05_Zip_Cleanup_Port();
$s05_idle_files  = new S05_Zip_Files();
$s05_idle_worker = new Run_Next_Submission_Zip_Cleanup(
	$s05_idle_port,
	$s05_idle_files,
	$s05_zip_ids,
	static fn (): string => '2026-08-22 14:30:00'
);
$s05_idle        = $s05_idle_worker->run_once();
g3_same( Run_Submission_Zip_Cleanup_Result::IDLE, $s05_idle->state(), 'S05 cleanup must be idle without a due file.' );
g3_same( '2026-08-22 14:30:00', $s05_idle_port->claimed_now, 'S05 cleanup claim time must be exact.' );
g3_same( '2026-08-22 14:25:00', $s05_idle_port->claimed_stale_before, 'S05 cleanup retry lease must be 300 seconds.' );

$s05_port           = new S05_Zip_Cleanup_Port();
$s05_port->queue    = array( $s05_source, $s05_item );
$s05_port->outcomes = array( false, true );
$s05_files          = new S05_Zip_Files();
$s05_worker         = new Run_Next_Submission_Zip_Cleanup(
	$s05_port,
	$s05_files,
	$s05_zip_ids,
	static fn (): string => '2026-08-22 14:30:00'
);
$s05_source_result  = $s05_worker->run_once();
g3_same( Run_Submission_Zip_Cleanup_Result::PURGED, $s05_source_result->state(), 'S05 must purge a claimed ZIP source.' );
g3_same( 'source', $s05_source_result->file_kind(), 'S05 source result kind must be stable.' );
g3_assert( ! $s05_source_result->cleanup_complete(), 'S05 source purge must retain cleanup while item files remain.' );
g3_same( array( 'submission-zips/source/source.zip' ), $s05_files->deleted, 'S05 must delete only the claimed source bytes.' );

$s05_item_result = $s05_worker->run_once();
g3_same( Run_Submission_Zip_Cleanup_Result::PURGED, $s05_item_result->state(), 'S05 must purge a claimed unimported item.' );
g3_same( 'item', $s05_item_result->file_kind(), 'S05 item result kind must be stable.' );
g3_assert( $s05_item_result->cleanup_complete(), 'S05 last file purge must complete root cleanup.' );
g3_same(
	array( 'submission-zips/source/source.zip', 'submission-zips/items/item-0002.pdf' ),
	$s05_files->deleted,
	'S05 cleanup must process one file per run in claim order.'
);

$s05_stale_port           = new S05_Zip_Cleanup_Port();
$s05_stale_port->queue    = array( $s05_source );
$s05_stale_port->outcomes = array( null );
$s05_stale_files          = new S05_Zip_Files();
$s05_stale_worker         = new Run_Next_Submission_Zip_Cleanup(
	$s05_stale_port,
	$s05_stale_files,
	$s05_zip_ids,
	static fn (): string => '2026-08-22 14:30:00'
);
$s05_stale                = $s05_stale_worker->run_once();
g3_same( Run_Submission_Zip_Cleanup_Result::STALE, $s05_stale->state(), 'S05 stale root or item claim must not purge.' );
g3_same( array(), $s05_stale_files->deleted, 'S05 stale claim must not touch bytes.' );

$s05_retry_port               = new S05_Zip_Cleanup_Port();
$s05_retry_port->queue        = array( $s05_source );
$s05_retry_port->outcomes     = array( false );
$s05_retry_files              = new S05_Zip_Files();
$s05_retry_files->fail_delete = true;
$s05_retry_worker             = new Run_Next_Submission_Zip_Cleanup(
	$s05_retry_port,
	$s05_retry_files,
	$s05_zip_ids,
	static fn (): string => '2026-08-22 14:30:00'
);
$s05_retry                    = $s05_retry_worker->run_once();
g3_same( Run_Submission_Zip_Cleanup_Result::RETRY_WAIT, $s05_retry->state(), 'S05 byte deletion failure must remain retryable.' );
g3_same( 'submission_zip_cleanup_unavailable', $s05_retry->reason_code(), 'S05 retry must expose only a stable reason.' );
g3_same( array(), $s05_retry_files->deleted, 'S05 failed deletion must not be recorded as purged.' );

$s05_bad_record = s05_zip_cleanup_record( array( 'storage_key' => 'submission-zips/../escape.zip' ) );
try {
	new Submission_Zip_Cleanup_Item( $s05_bad_record );
	throw new RuntimeException( 'S05 traversal storage key must be rejected.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	// Expected.
}

$s05_root                = dirname( __DIR__, 2 );
$s05_repository_source   = file_get_contents( $s05_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Cleanup_Repository.php' );
$s05_module_source       = file_get_contents( $s05_root . '/src/Submission/ZipImport/Infrastructure/Submission_Zip_Cleanup_Worker_Module.php' );
$s05_composition_source  = file_get_contents( $s05_root . '/src/Core/Composition_Root.php' );
$s05_deactivation_source = file_get_contents( $s05_root . '/src/Core/Deactivation.php' );
$s05_profile_source      = file_get_contents( $s05_root . '/src/Submission/ZipImport/Domain/Submission_Zip_Upload_Profile.php' );
$s05_mapping_source      = file_get_contents( $s05_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Mapping_Repository.php' );
$s05_finalization_source = file_get_contents( $s05_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Finalization_Repository.php' );
$s05_gate_source         = file_get_contents( $s05_root . '/scripts/check-submission-zip-g3-imp-07.ps1' );

foreach ( array( $s05_repository_source, $s05_module_source, $s05_composition_source, $s05_deactivation_source ) as $source ) {
	g3_assert( is_string( $source ), 'S05 implementation source must be readable.' );
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S05 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S05 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S05 must not consume OJ Platform internals.' );
}

foreach ( array(
	"'oj_ai_assignments'",
	"'oj_ai_zip_uploads'",
	"'oj_ai_zip_upload_items'",
	"'oj_ai_private_files'",
	"'oj_ai_audit_events'",
	"'oj_ai_outbox_events'",
	"array( 'completed', 'canceled', 'rejected' )",
	'submission_zip_staging',
	'submission_zip_source',
	'submission_zip_item',
	'legal_hold_until_utc IS NULL',
	'purged_at_utc IS NULL',
	'cleanup_after_utc = NULL',
	'remaining_file_count',
	'submission_zip.file.purged',
	'$this->required_utc( $root[\'cleanup_after_utc\'] ?? null )',
	'START TRANSACTION',
	'FOR UPDATE',
	'ROLLBACK',
) as $marker ) {
	g3_assert( str_contains( $s05_repository_source, $marker ), 'S05 cleanup repository marker is missing: ' . $marker );
}
g3_assert( ! str_contains( $s05_repository_source, 'DELETE FROM' ), 'S05 must preserve metadata and delete only private bytes.' );
g3_assert( ! str_contains( $s05_repository_source, "'submission_attachment'" ), 'S05 cleanup must not target promoted submission attachments.' );

$s05_root_lock = strpos( $s05_repository_source, '$root = $this->root(' );
$s05_item_lock = strpos( $s05_repository_source, '$zip_item = $this->zip_item(' );
$s05_file_lock = strpos( $s05_repository_source, '$file = $this->private_file(' );
g3_assert(
	false !== $s05_root_lock && false !== $s05_item_lock && false !== $s05_file_lock
	&& $s05_root_lock < $s05_item_lock && $s05_item_lock < $s05_file_lock,
	'S05 cleanup lock order must be root, item, then private file.'
);

foreach ( array( 'SWEEP_HOOK', 'oj_ai_dashboard_submission_zip_cleanup_sweep', "'interval' => 60", 'run_once', 'clear' ) as $marker ) {
	g3_assert( str_contains( $s05_module_source, $marker ), 'S05 cleanup module marker is missing: ' . $marker );
}
foreach ( array( 'Submission_Zip_Cleanup_Worker_Module', 'Run_Next_Submission_Zip_Cleanup', 'WordPress_Submission_Zip_Cleanup_Repository' ) as $marker ) {
	g3_assert( str_contains( $s05_composition_source, $marker ), 'S05 Composition Root marker is missing: ' . $marker );
}
g3_assert( str_contains( $s05_deactivation_source, 'Submission_Zip_Cleanup_Worker_Module::clear()' ), 'S05 deactivation must clear the ZIP cleanup schedule.' );

foreach ( array(
	'MAX_ARCHIVE_BYTES      = 104857600',
	'MAX_REGULAR_ENTRIES    = 500',
	'MAX_ENTRY_BYTES        = 26214400',
	'MAX_UNCOMPRESSED_BYTES = 524288000',
	'MAX_COMPRESSION_RATIO  = 20',
	'MAX_PATH_BYTES         = 500',
) as $marker ) {
	g3_assert( str_contains( $s05_profile_source, $marker ), 'S05 frozen ZIP safety bound is missing: ' . $marker );
}
foreach ( array( 'expected_mapping_version', 'FOR UPDATE', 'ROLLBACK' ) as $marker ) {
	g3_assert( str_contains( $s05_mapping_source, $marker ), 'S05 mapping race marker is missing: ' . $marker );
}
foreach ( array( 'record_item_failure', 'submission_official_exists', 'confirmation_lease', 'cleanup_after_utc' ) as $marker ) {
	g3_assert( str_contains( $s05_finalization_source, $marker ), 'S05 partial finalization marker is missing: ' . $marker );
}
foreach ( array(
	'submission-zip-stage-g3-imp-07-s01.php',
	'submission-zip-scan-preview-g3-imp-07-s02.php',
	'submission-zip-mapping-g3-imp-07-s03.php',
	'submission-zip-confirm-cancel-g3-imp-07-s04.php',
	'submission-zip-integration-freeze-g3-imp-07-s05.php',
) as $marker ) {
	g3_assert( str_contains( $s05_gate_source, $marker ), 'S05 aggregate gate marker is missing: ' . $marker );
}

echo "G3-IMP-07-S05 passed: frozen ZIP bounds, path and malware gates, mapping CAS, partial finalization, 300-second cleanup retry, legal hold, promoted-file protection, and S01-S05 aggregation.\n";
