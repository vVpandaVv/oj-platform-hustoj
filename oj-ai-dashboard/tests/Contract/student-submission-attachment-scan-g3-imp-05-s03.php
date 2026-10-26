<?php
/**
 * G3-IMP-05-S03 staged attachment scan boundary contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Scan;
use OJAI\Dashboard\Submission\Student\Application\Run_Submission_Attachment_Scan_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Inspector;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C26 id.
 *
 * @param int $number Number.
 * @return string
 */
function c26_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-05-S03 staged attachment scan boundary contract.
 */
final class C26_Scans implements Student_Attachment_Scan_Repository_Port_Interface {
	/**
	 * Next.
	 *
	 * @var Submission_Attachment_Scan_Item|null
	 */
	public ?Submission_Attachment_Scan_Item $next;
	/**
	 * Complete result.
	 *
	 * @var bool
	 */
	public bool $complete_result = true;
	/**
	 * Completions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $completions = array();
	/**
	 * Claims.
	 *
	 * @var array<int,array{now:string,stale_before:string}>
	 */
	public array $claims = array();

	/**
	 * Initialize c26 scans dependencies and state.
	 *
	 * @param Submission_Attachment_Scan_Item|null $next Next.
	 */
	public function __construct( ?Submission_Attachment_Scan_Item $next ) {
		$this->next = $next;
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Scan_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Scan_Item {
		$this->claims[] = array(
			'now'          => $now_utc,
			'stale_before' => $stale_before_utc,
		);
		$item           = $this->next;
		$this->next     = null;
		return $item;
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
	public function complete(
		Submission_Attachment_Scan_Item $item,
		string $outcome,
		?string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		$this->completions[] = array(
			'attachment_id'    => $item->attachment_id(),
			'outcome'          => $outcome,
			'reason_code'      => $reason_code,
			'audit_public_id'  => $audit_public_id,
			'outbox_public_id' => $outbox_public_id,
			'occurred_at_utc'  => $occurred_at_utc,
		);
		return $this->complete_result;
	}
}

/**
 * G3-IMP-05-S03 staged attachment scan boundary contract.
 */
final class C26_Files implements Private_File_Read_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize c26 files dependencies and state.
	 *
	 * @param string $path Path.
	 * @param bool   $unavailable Unavailable.
	 */
	public function __construct( private string $path, public bool $unavailable = false ) {
	}
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		++$this->calls;
		g3_same( 'submission-attachments/aa/answer.json', $storage_key, 'S03 must use only the claimed private storage key.' );
		g3_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $sha256 ), 'S03 must pass a strict expected hash to private storage.' );
		if ( $this->unavailable ) {
			throw new RuntimeException( 'Injected S03 storage outage.' );
		}
		return $this->path;
	}
}

/**
 * G3-IMP-05-S03 staged attachment scan boundary contract.
 */
final class C26_Malware implements Submission_Attachment_Malware_Scanner_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize c26 malware dependencies and state.
	 *
	 * @param Submission_Attachment_Malware_Verdict $verdict Verdict.
	 */
	public function __construct( private Submission_Attachment_Malware_Verdict $verdict ) {
	}
	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		++$this->calls;
		g3_assert( is_file( $path ), 'S03 malware scan must receive the internal readable path.' );
		return $this->verdict;
	}
}

/**
 * C26 item.
 *
 * @param string              $path Path.
 * @param array<string,mixed> $overrides Overrides.
 * @return Submission_Attachment_Scan_Item
 */
function c26_item( string $path, array $overrides = array() ): Submission_Attachment_Scan_Item {
	$size = filesize( $path );
	$hash = hash_file( 'sha256', $path );
	g3_assert( is_int( $size ) && is_string( $hash ), 'S03 fixture metadata could not be calculated.' );
	return new Submission_Attachment_Scan_Item(
		array_replace(
			array(
				'attachment_byte_size'     => $size,
				'attachment_id'            => c26_id( 2 ),
				'attachment_lock_version'  => 0,
				'attachment_mime_type'     => 'application/json',
				'attachment_original_name' => 'answer.json',
				'attachment_sha256'        => $hash,
				'claimed_at_utc'           => '2026-08-22 11:00:00',
				'file_byte_size'           => $size,
				'file_id'                  => c26_id( 3 ),
				'file_lock_version'        => 1,
				'file_mime_type'           => 'application/json',
				'file_original_name'       => 'answer.json',
				'file_sha256'              => $hash,
				'owner_user_id'            => 7,
				'response_id'              => c26_id( 4 ),
				'storage_key'              => 'submission-attachments/aa/answer.json',
				'submission_id'            => c26_id( 1 ),
			),
			$overrides
		)
	);
}

/**
 * C26 run.
 *
 * @param Submission_Attachment_Scan_Item       $item Item.
 * @param string                                $path Path.
 * @param Submission_Attachment_Malware_Verdict $verdict Verdict.
 * @param bool                                  $commit Commit.
 * @return array{0:Run_Submission_Attachment_Scan_Result,1:C26_Scans,2:C26_Files,3:C26_Malware}
 */
function c26_run( Submission_Attachment_Scan_Item $item, string $path, Submission_Attachment_Malware_Verdict $verdict, bool $commit = true ): array {
	static $seed                 = 40;
	$repository                  = new C26_Scans( $item );
	$repository->complete_result = $commit;
	$files                       = new C26_Files( $path );
	$malware                     = new C26_Malware( $verdict );
	$ids                         = new Public_Id_Generator(
		static function ( int $length ) use ( &$seed ): string {
			++$seed;
			return str_repeat( chr( $seed ), $length );
		}
	);
	$worker                      = new Run_Next_Submission_Attachment_Scan(
		$repository,
		$files,
		$malware,
		new Submission_Attachment_Content_Inspector(),
		$ids,
		static fn (): string => '2026-08-22 11:00:00'
	);
	return array( $worker->run_once(), $repository, $files, $malware );
}

$valid_path   = tempnam( sys_get_temp_dir(), 'ojai-c26-valid-' );
$invalid_path = tempnam( sys_get_temp_dir(), 'ojai-c26-invalid-' );
$png_path     = tempnam( sys_get_temp_dir(), 'ojai-c26-png-' );
$text_path    = tempnam( sys_get_temp_dir(), 'ojai-c26-text-' );
g3_assert( is_string( $valid_path ) && is_string( $invalid_path ) && is_string( $png_path ) && is_string( $text_path ), 'S03 temporary fixtures could not be created.' );
register_shutdown_function(
	static function () use ( $valid_path, $invalid_path, $png_path, $text_path ): void {
		foreach ( array( $valid_path, $invalid_path, $png_path, $text_path ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
);
$drawing = array(
	'appState' => (object) array(),
	'elements' => array(),
	'files'    => (object) array(),
	'image'    => array(
		'data_url'   => 'data:image/png;base64,iVBORw0KGgo=',
		'height'     => 1,
		'mime'       => 'image/png',
		'updated_at' => '2026-08-22T11:00:00Z',
		'width'      => 1,
	),
	'source'   => 'oj-ai-dashboard',
	'type'     => 'excalidraw_response',
	'version'  => 1,
);
g3_assert( false !== file_put_contents( $valid_path, json_encode( $drawing, JSON_THROW_ON_ERROR ) ), 'S03 valid fixture could not be written.' );
g3_assert( false !== file_put_contents( $invalid_path, '{}' ), 'S03 invalid fixture could not be written.' );
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
g3_assert( is_string( $png_bytes ) && false !== file_put_contents( $png_path, $png_bytes ), 'S03 PNG fixture could not be written.' );
g3_assert( false !== file_put_contents( $text_path, "학생 답변\n" ), 'S03 UTF-8 text fixture could not be written.' );

[ $ready, $ready_repository, $ready_files, $ready_malware ] = c26_run( c26_item( $valid_path ), $valid_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::READY, $ready->state(), 'A clean S03 attachment must become ready.' );
g3_same( '2026-08-22 10:55:00', $ready_repository->claims[0]['stale_before'], 'S03 must reclaim only after its frozen five-minute lease.' );
g3_same(
	array(
		'outcome'     => 'ready',
		'reason_code' => null,
	),
	array_intersect_key(
		$ready_repository->completions[0],
		array(
			'outcome'     => true,
			'reason_code' => true,
		)
	),
	'S03 clean completion must be ready without a rejection reason.'
);
g3_same( 1, $ready_files->calls, 'S03 must read a clean private file once.' );
g3_same( 1, $ready_malware->calls, 'S03 must scan bytes before deep content parsing.' );

$png_item       = c26_item(
	$png_path,
	array(
		'attachment_mime_type'     => 'image/png',
		'attachment_original_name' => 'answer.png',
		'file_mime_type'           => 'image/png',
		'file_original_name'       => 'answer.png',
	)
);
[ $png_result ] = c26_run( $png_item, $png_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::READY, $png_result->state(), 'A byte-valid PNG must pass S03 image inspection.' );

$text_item       = c26_item(
	$text_path,
	array(
		'attachment_mime_type'     => 'text/plain',
		'attachment_original_name' => 'answer.txt',
		'file_mime_type'           => 'text/plain',
		'file_original_name'       => 'answer.txt',
	)
);
[ $text_result ] = c26_run( $text_item, $text_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::READY, $text_result->state(), 'Valid UTF-8 text must pass S03 content inspection.' );

$disguised_item                              = c26_item(
	$text_path,
	array(
		'attachment_mime_type'     => 'application/pdf',
		'attachment_original_name' => 'answer.pdf',
		'file_mime_type'           => 'application/pdf',
		'file_original_name'       => 'answer.pdf',
	)
);
[ $disguised_result, $disguised_repository ] = c26_run( $disguised_item, $text_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::REJECTED, $disguised_result->state(), 'A text file disguised as PDF must fail magic inspection.' );
g3_same( 'attachment_type_mismatch', $disguised_repository->completions[0]['reason_code'], 'S03 must retain a safe type mismatch reason.' );

[ $infected, $infected_repository ] = c26_run( c26_item( $valid_path ), $valid_path, Submission_Attachment_Malware_Verdict::infected() );
g3_same( Run_Submission_Attachment_Scan_Result::REJECTED, $infected->state(), 'Malware must terminate S03 as rejected.' );
g3_same( 'attachment_malware_detected', $infected_repository->completions[0]['reason_code'], 'S03 must persist only a stable malware reason.' );

[ $unavailable, $unavailable_repository ] = c26_run( c26_item( $valid_path ), $valid_path, Submission_Attachment_Malware_Verdict::unavailable() );
g3_same( Run_Submission_Attachment_Scan_Result::RETRY_WAIT, $unavailable->state(), 'Scanner unavailability must preserve quarantined pending state for lease retry.' );
g3_same( 0, count( $unavailable_repository->completions ), 'A retryable S03 outage must perform no terminal metadata write.' );

$mismatch_item = c26_item( $valid_path, array( 'file_mime_type' => 'text/plain' ) );
[ $mismatch, $mismatch_repository, $mismatch_files, $mismatch_malware ] = c26_run( $mismatch_item, $valid_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::REJECTED, $mismatch->state(), 'Cross-table metadata drift must fail closed.' );
g3_same( 'attachment_metadata_mismatch', $mismatch_repository->completions[0]['reason_code'], 'S03 must retain a stable metadata mismatch reason.' );
g3_same( 0, $mismatch_files->calls, 'S03 must reject metadata drift before reading bytes.' );
g3_same( 0, $mismatch_malware->calls, 'S03 must reject metadata drift before malware scanning.' );

$actual_size = filesize( $valid_path );
g3_assert( is_int( $actual_size ), 'S03 valid fixture size disappeared.' );
$size_item = c26_item(
	$valid_path,
	array(
		'attachment_byte_size' => $actual_size + 1,
		'file_byte_size'       => $actual_size + 1,
	)
);
[ $size_result, $size_repository, , $size_malware ] = c26_run( $size_item, $valid_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::REJECTED, $size_result->state(), 'A private byte-size mismatch must be rejected.' );
g3_same( 'attachment_size_mismatch', $size_repository->completions[0]['reason_code'], 'S03 must retain a stable byte-size reason.' );
g3_same( 0, $size_malware->calls, 'S03 must enforce size before malware scanning.' );

[ $invalid, $invalid_repository ] = c26_run( c26_item( $invalid_path ), $invalid_path, Submission_Attachment_Malware_Verdict::clean() );
g3_same( Run_Submission_Attachment_Scan_Result::REJECTED, $invalid->state(), 'An invalid drawing envelope must be rejected after scanning.' );
g3_same( 'attachment_drawing_invalid', $invalid_repository->completions[0]['reason_code'], 'S03 must retain a stable drawing-envelope reason.' );

[ $stale, $stale_repository ] = c26_run( c26_item( $valid_path ), $valid_path, Submission_Attachment_Malware_Verdict::clean(), false );
g3_same( Run_Submission_Attachment_Scan_Result::STALE, $stale->state(), 'A C25 or reclaimed-lease race must discard stale S03 completion.' );
g3_same( 1, count( $stale_repository->completions ), 'S03 may attempt one CAS completion after an external scan.' );

$repository_source  = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Attachment_Scan_Repository.php' );
$scanner_source     = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Clamav_Instream_Submission_Attachment_Scanner.php' );
$inspector_source   = (string) file_get_contents( $addon_root . '/src/Submission/Student/Domain/Submission_Attachment_Content_Inspector.php' );
$worker_source      = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Submission_Attachment_Scan_Worker_Module.php' );
$composition_source = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
foreach ( array( $repository_source, $scanner_source, $inspector_source, $worker_source, $composition_source ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S03 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S03 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S03 must not consume OJ Platform internals.' );
}
foreach ( array( "'oj_ai_submission_attachments'", "'oj_ai_private_files'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", 'START TRANSACTION', 'FOR UPDATE', 'lock_version = 0 OR f.updated_at_utc <= %s', 'submission.attachment.scan.completed' ) as $marker ) {
	g3_assert( str_contains( $repository_source, $marker ), 'S03 atomic lease marker is missing: ' . $marker );
}
foreach ( array( 'zINSTREAM\\0', 'tcp://127.0.0.1:3310', 'stream_socket_client', 'MAX_RESPONSE_BYTES' ) as $marker ) {
	g3_assert( str_contains( $scanner_source, $marker ), 'S03 local ClamAV boundary marker is missing: ' . $marker );
}
foreach ( array( 'MAX_OFFICE_RATIO', 'encryption_method', 'zip_entry_is_link', '[A-Za-z]:', 'attachment_container_unsafe' ) as $marker ) {
	g3_assert( str_contains( $inspector_source, $marker ), 'S03 bounded content marker is missing: ' . $marker );
}
foreach ( array( 'oj_ai_dashboard_submission_attachment_scan_sweep', 'oj_ai_dashboard_every_minute', 'wp_schedule_event', 'run_once' ) as $marker ) {
	g3_assert( str_contains( $worker_source, $marker ), 'S03 worker marker is missing: ' . $marker );
}
g3_assert( str_contains( $composition_source, 'Submission_Attachment_Scan_Worker_Module' ), 'The single Composition Root must wire S03.' );

echo "G3-IMP-05-S03 passed: reclaimable lease, size/type inspection, malware boundary, atomic terminal state, and stale completion protection.\n";
