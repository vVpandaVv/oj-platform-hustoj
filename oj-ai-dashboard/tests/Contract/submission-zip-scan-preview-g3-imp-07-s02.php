<?php
/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-zip-stage-g3-imp-07-s01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Scan;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Submission_Zip_Scan_Result;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Verdict;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Scan_Job;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Suggestion_Matcher;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Preview_Query;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * Q35 paths.
 *
 * @var array<int,string> $q35_paths
 */
$q35_paths = array();

/**
 * Q35 zip.
 *
 * @param array<int,array{name:string,data:string,method?:int}> $entries Entries.
 * @return string
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q35_zip( array $entries ): string {
	global $q35_paths;
	$locals  = '';
	$central = '';
	foreach ( $entries as $entry ) {
		$name       = $entry['name'];
		$data       = $entry['data'];
		$method     = $entry['method'] ?? 0;
		$compressed = 8 === $method ? gzdeflate( $data, 6 ) : $data;
		if ( ! is_string( $compressed ) ) {
			throw new RuntimeException( 'Q35 fixture compression failed.' );
		}
		$crc      = crc32( $data );
		$offset   = strlen( $locals );
		$locals  .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen( $compressed ), strlen( $data ), strlen( $name ), 0 ) . $name . $compressed;
		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, ( 3 << 8 ) | 20, 20, 0, $method, 0, 0, $crc, strlen( $compressed ), strlen( $data ), strlen( $name ), 0, 0, 0, 0, 0100644 << 16, $offset ) . $name;
	}
	$count = count( $entries );
	$eocd  = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $locals ), 0 );
	$path  = tempnam( sys_get_temp_dir(), 'oj-q35-' );
	if ( false === $path || false === file_put_contents( $path, $locals . $central . $eocd ) ) {
		throw new RuntimeException( 'Q35 fixture write failed.' );
	}
	$q35_paths[] = $path;
	return $path;
}

/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 */
final class Q35_Files implements Private_File_Read_Port_Interface, Private_File_Store_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array{path:string,sha256:string}>
	 */
	public array $rows = array();
	/**
	 * Deleted.
	 *
	 * @var array<int,string>
	 */
	public array $deleted = array();
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		$row = $this->rows[ $storage_key ] ?? null;
		if ( ! is_array( $row ) || ! hash_equals( $sha256, $row['sha256'] ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $row['path'] ) ) ) {
			throw new RuntimeException( 'Private source unavailable.' );
		}
		return $row['path'];
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
		if ( isset( $this->rows[ $storage_key ] ) ) {
			return false;
		}
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $source_path ) ) ) {
			throw new RuntimeException( 'Private item hash drifted.' );
		}
		$this->rows[ $storage_key ] = array(
			'path'   => $source_path,
			'sha256' => $sha256,
		);
		return true;
	}
	/**
	 * Delete q35 files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		unset( $this->rows[ $storage_key ] );
		$this->deleted[] = $storage_key;
	}
}

/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 */
final class Q35_Metadata implements Private_File_Staging_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array|null
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		return $this->rows[ $storage_key ] ?? null;
	}
	/**
	 * Insert quarantined.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	public function insert_quarantined( array $record ): void {
		$this->rows[ $record['storage_key'] ] = array_merge(
			$record,
			array(
				'created_by_user_id' => $record['actor_user_id'],
				'state'              => 'quarantined',
			)
		);
	}
	/**
	 * Mark purged.
	 *
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $purged_at_utc Purged at utc.
	 * @return void
	 */
	public function mark_purged( string $file_id, int $actor_user_id, string $purged_at_utc ): void {
		foreach ( $this->rows as &$row ) {
			if ( $file_id === $row['public_id'] && $actor_user_id === $row['actor_user_id'] ) {
				$row['state']         = 'purged';
				$row['purged_at_utc'] = $purged_at_utc;
			}
		}
		unset( $row );
	}
}

/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 */
final class Q35_Scanner implements Submission_Attachment_Malware_Scanner_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize q35 scanner dependencies and state.
	 *
	 * @param string $mode Mode.
	 */
	public function __construct( private string $mode = 'payload' ) {
	}
	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		++$this->calls;
		if ( 'unavailable' === $this->mode ) {
			return Submission_Attachment_Malware_Verdict::unavailable();
		}
		if ( 'source_infected' === $this->mode && 1 === $this->calls ) {
			return Submission_Attachment_Malware_Verdict::infected();
		}
		$bytes = (string) file_get_contents( $path );
		return $this->calls > 1 && str_contains( $bytes, 'MALWARE' )
			? Submission_Attachment_Malware_Verdict::infected()
			: Submission_Attachment_Malware_Verdict::clean();
	}
}

/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 */
final class Q35_Scan_Repository implements Submission_Zip_Scan_Repository_Port_Interface {
	/**
	 * Job.
	 *
	 * @var Submission_Zip_Scan_Job|null
	 */
	public ?Submission_Zip_Scan_Job $job;
	/**
	 * Items.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();
	/**
	 * Mapping.
	 *
	 * @var array<string,mixed>
	 */
	public array $mapping = array();
	/**
	 * Rejected reason.
	 *
	 * @var string|null
	 */
	public ?string $rejected_reason = null;
	/**
	 * Complete result.
	 *
	 * @var bool
	 */
	public bool $complete_result = true;
	/**
	 * Initialize q35 scan repository dependencies and state.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 */
	public function __construct( Submission_Zip_Scan_Job $job ) {
		$this->job = $job;
	}
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Scan_Job|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Scan_Job {
		unset( $now_utc, $stale_before_utc );
		$job       = $this->job;
		$this->job = null;
		return $job;
	}
	/**
	 * Complete.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param array                   $items Items.
	 * @param array                   $mapping Mapping.
	 * @param string                  $audit_public_id Audit public id.
	 * @param string                  $outbox_public_id Outbox public id.
	 * @param string                  $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function complete( Submission_Zip_Scan_Job $job, array $items, array $mapping, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		unset( $job, $audit_public_id, $outbox_public_id, $occurred_at_utc );
		$this->items   = $items;
		$this->mapping = $mapping;
		return $this->complete_result;
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
	 */
	public function reject( Submission_Zip_Scan_Job $job, string $reason_code, string $audit_public_id, string $outbox_public_id, string $occurred_at_utc ): bool {
		unset( $job, $audit_public_id, $outbox_public_id, $occurred_at_utc );
		$this->rejected_reason = $reason_code;
		return true;
	}
}

/**
 * Q35 job.
 *
 * @param string                         $archive Archive.
 * @param string                         $assignment_id Distribution public identifier.
 * @param string                         $upload_id Upload id.
 * @param string                         $file_id File id.
 * @param array<int,array<string,mixed>> $responses Responses.
 * @param array<int,array<string,mixed>> $targets Targets.
 * @return Submission_Zip_Scan_Job
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q35_job( string $archive, string $assignment_id, string $upload_id, string $file_id, array $responses, array $targets ): Submission_Zip_Scan_Job {
	$size = filesize( $archive );
	$sha  = hash_file( 'sha256', $archive );
	if ( ! is_int( $size ) || ! is_string( $sha ) ) {
		throw new RuntimeException( 'Q35 source metadata failed.' );
	}
	$profile      = ( new Submission_Zip_Upload_Profile() )->inspect(
		array(
			'error'    => UPLOAD_ERR_OK,
			'name'     => 'submission.zip',
			'size'     => $size,
			'tmp_name' => $archive,
			'type'     => 'application/zip',
		)
	);
	$source       = array(
		'byte_size'              => $size,
		'compressed_entry_bytes' => $profile['compressed_entry_bytes'],
		'entry_count'            => $profile['entry_count'],
		'sha256'                 => $sha,
		'uncompressed_bytes'     => $profile['uncompressed_bytes'],
	);
	$mapping_json = ( new Canonical_Json() )->encode(
		array(
			'items'            => array(),
			'mapping_revision' => 0,
			'source'           => $source,
		)
	);
	return new Submission_Zip_Scan_Job(
		array(
			'actor_user_id'            => 42,
			'assignment_id'            => $assignment_id,
			'claimed_at_utc'           => '2026-08-22 12:00:00',
			'context_id'               => 'class-21',
			'context_revision'         => 'class-r4',
			'context_type'             => 'class',
			'mapping_hash'             => hash( 'sha256', $mapping_json ),
			'mapping_revision'         => 0,
			'mapping_source'           => $source,
			'source_byte_size'         => $size,
			'source_expires_at_utc'    => '2026-08-23 11:00:00',
			'source_file_id'           => $file_id,
			'source_file_lock_version' => 0,
			'source_original_name'     => 'submission.zip',
			'source_sha256'            => $sha,
			'source_storage_key'       => 'submission-zips/source.zip',
			'targets'                  => $targets,
			'upload_id'                => $upload_id,
			'upload_lock_version'      => 1,
			'upload_responses'         => $responses,
		)
	);
}

/**
 * Q35 worker.
 *
 * @param Submission_Zip_Scan_Job $job Job.
 * @param string                  $archive Archive.
 * @param Q35_Scanner             $scanner Scanner.
 * @param Public_Id_Generator     $ids Ids.
 * @return array{worker:Run_Next_Submission_Zip_Scan,repository:Q35_Scan_Repository,files:Q35_Files,metadata:Q35_Metadata}
 */
function q35_worker( Submission_Zip_Scan_Job $job, string $archive, Q35_Scanner $scanner, Public_Id_Generator $ids ): array {
	$repository                                = new Q35_Scan_Repository( $job );
	$files                                     = new Q35_Files();
	$files->rows['submission-zips/source.zip'] = array(
		'path'   => $archive,
		'sha256' => $job->source_sha256(),
	);
	$metadata                                  = new Q35_Metadata();
	$profile                                   = new Submission_Zip_Upload_Profile();
	$reader                                    = new Submission_Zip_Entry_Reader( $profile );
	$worker                                    = new Run_Next_Submission_Zip_Scan(
		$repository,
		$files,
		$files,
		$metadata,
		$scanner,
		$reader,
		new Submission_Zip_Item_Content_Inspector( $reader ),
		new Submission_Zip_Suggestion_Matcher(),
		$ids,
		static fn (): string => '2026-08-22 12:00:00'
	);
	return array(
		'worker'     => $worker,
		'repository' => $repository,
		'files'      => $files,
		'metadata'   => $metadata,
	);
}

$id_seed          = 80;
$q35_ids          = new Public_Id_Generator(
	static function ( int $length ) use ( &$id_seed ): string {
		++$id_seed;
		return str_repeat( chr( $id_seed ), $length );
	}
);
$assignment_id    = $q35_ids->create()->value();
$upload_id        = $q35_ids->create()->value();
$source_file_id   = $q35_ids->create()->value();
$target_id        = $q35_ids->create()->value();
$text_response_id = $q35_ids->create()->value();
$pdf_response_id  = $q35_ids->create()->value();
$responses        = array(
	array(
		'id'         => $text_response_id,
		'kind'       => 'file_upload',
		'max_bytes'  => 26214400,
		'mime_types' => array( 'text/plain' ),
	),
	array(
		'id'         => $pdf_response_id,
		'kind'       => 'file_upload',
		'max_bytes'  => 26214400,
		'mime_types' => array( 'application/pdf' ),
	),
);
$targets          = array(
	array(
		'display_name'    => '김철수',
		'learner_user_id' => 101,
		'state'           => 'active',
		'target_id'       => $target_id,
	),
);

$deflated         = q35_zip(
	array(
		array(
			'name'   => 'deflated.txt',
			'data'   => 'bounded deflate',
			'method' => 8,
		),
	)
);
$entry_reader     = new Submission_Zip_Entry_Reader( new Submission_Zip_Upload_Profile() );
$deflated_entries = $entry_reader->entries( $deflated, (string) hash_file( 'sha256', $deflated ) );
g3_same( 1, count( $deflated_entries ), 'Q35 reader must list one validated deflate entry.' );
$deflated_output = $entry_reader->extract_to_temporary_file( $deflated, $deflated_entries[0] );
g3_same( 'bounded deflate', file_get_contents( $deflated_output ), 'Q35 reader must stream raw deflate bytes exactly.' );
@unlink( $deflated_output ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

$archive = q35_zip(
	array(
		array(
			'name'   => '김철수/' . $text_response_id . '.txt',
			'data'   => '학생 답안',
			'method' => 8,
		),
		array(
			'name' => '김철수/blocked.html',
			'data' => '<html>blocked</html>',
		),
		array(
			'name' => 'other/report.pdf',
			'data' => "%PDF-1.4\nMALWARE\n%%EOF",
		),
	)
);
$job     = q35_job( $archive, $assignment_id, $upload_id, $source_file_id, $responses, $targets );
$bundle  = q35_worker( $job, $archive, new Q35_Scanner(), $q35_ids );
$result  = $bundle['worker']->run_once();
g3_same( Run_Submission_Zip_Scan_Result::READY, $result->state(), 'A partially rejected Q35 scan must still produce a reviewable preview.' );
g3_same( 3, count( $bundle['repository']->items ), 'Q35 scan must preserve one row per regular ZIP item.' );
g3_same( 'ready', $bundle['repository']->items[0]['state'], 'Allowed text must be ready.' );
g3_same( 'zip_item_type_not_allowed', $bundle['repository']->items[1]['error_code'], 'HTML must be rejected by a stable item code.' );
g3_same( 'zip_item_malware_detected', $bundle['repository']->items[2]['error_code'], 'Infected extracted bytes must be rejected by a stable item code.' );
g3_same( null, $bundle['repository']->items[0]['learner_user_id'] ?? null, 'Q35 suggestions must not confirm a learner mapping.' );
g3_same( 1, $bundle['repository']->mapping['mapping_revision'], 'Q35 scan must advance the optimistic mapping version once.' );
g3_same( 101, $bundle['repository']->mapping['items'][0]['suggestion']['learner']['learner_user_id'], 'Exact filename token should produce a review-only learner suggestion.' );
g3_same( $text_response_id, $bundle['repository']->mapping['items'][0]['suggestion']['response']['response_id'], 'Exact response token should produce a review-only response suggestion.' );
g3_same( 1, count( $bundle['metadata']->rows ), 'Only clean compatible items may enter private item storage.' );
$stored_item = reset( $bundle['metadata']->rows );
g3_same( 'submission_zip', $stored_item['owner_type'] ?? null, 'Extracted item ownership must remain inside the ZIP aggregate.' );
g3_same( 'clean', $stored_item['scan_state'] ?? null, 'A stored ZIP item must already have a clean scan receipt.' );

$stale_job                            = q35_job( $archive, $assignment_id, $q35_ids->create()->value(), $q35_ids->create()->value(), $responses, $targets );
$stale                                = q35_worker( $stale_job, $archive, new Q35_Scanner(), $q35_ids );
$stale['repository']->complete_result = false;
g3_same( Run_Submission_Zip_Scan_Result::STALE, $stale['worker']->run_once()->state(), 'A lost Q35 lease must report stale without committing the root.' );
g3_same( array(), $stale['files']->deleted, 'A stale worker must not delete deterministic files that a newer lease may already reference.' );
$stale_item = reset( $stale['metadata']->rows );
g3_same( 'quarantined', $stale_item['state'] ?? null, 'A stale worker must retain registered private rows for retry or expiry cleanup.' );
g3_same( 'submission_zip_staging', $stale_item['retention_class'] ?? null, 'Retained stale files must remain governed by staging retention.' );

$retry_job = q35_job( $archive, $assignment_id, $q35_ids->create()->value(), $q35_ids->create()->value(), $responses, $targets );
$retry     = q35_worker( $retry_job, $archive, new Q35_Scanner( 'unavailable' ), $q35_ids );
g3_same( Run_Submission_Zip_Scan_Result::RETRY_WAIT, $retry['worker']->run_once()->state(), 'Scanner outage must fail closed and leave the root reclaimable.' );
g3_same( array(), $retry['repository']->items, 'Scanner outage must not commit item rows.' );

$infected_job = q35_job( $archive, $assignment_id, $q35_ids->create()->value(), $q35_ids->create()->value(), $responses, $targets );
$infected     = q35_worker( $infected_job, $archive, new Q35_Scanner( 'source_infected' ), $q35_ids );
g3_same( Run_Submission_Zip_Scan_Result::REJECTED, $infected['worker']->run_once()->state(), 'Infected source ZIP must reject the aggregate.' );
g3_same( 'zip_source_malware_detected', $infected['repository']->rejected_reason, 'Source malware reason drifted.' );

$html = tempnam( sys_get_temp_dir(), 'oj-q35-html-' );
if ( false === $html ) {
	throw new RuntimeException( 'Q35 HTML fixture failed.' );
}
$q35_paths[] = $html;
file_put_contents( $html, '<html>no</html>' );
$inspector    = new Submission_Zip_Item_Content_Inspector( $entry_reader );
$html_verdict = $inspector->inspect( $html, 'answer.html', (int) filesize( $html ), (string) hash_file( 'sha256', $html ) );
g3_same( Submission_Zip_Item_Content_Verdict::REJECTED, $html_verdict->state(), 'HTML must never enter a ZIP item allowlist.' );

foreach ( array(
	'html'   => '<!doctype html><html><body>renamed</body></html>',
	'json'   => '{"answer":"renamed"}',
	'script' => "#!/bin/sh\necho renamed\n",
	'svg'    => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
) as $kind => $payload ) {
	$disguised = tempnam( sys_get_temp_dir(), 'oj-q35-text-' );
	if ( false === $disguised ) {
		throw new RuntimeException( 'Q35 disguised text fixture failed.' );
	}
	$q35_paths[] = $disguised;
	file_put_contents( $disguised, $payload );
	$disguised_verdict = $inspector->inspect( $disguised, 'answer.txt', (int) filesize( $disguised ), (string) hash_file( 'sha256', $disguised ) );
	g3_same( Submission_Zip_Item_Content_Verdict::REJECTED, $disguised_verdict->state(), 'Disguised ' . $kind . ' must fail text content sniffing.' );
}

$broken_office = tempnam( sys_get_temp_dir(), 'oj-q35-docx-' );
if ( false === $broken_office ) {
	throw new RuntimeException( 'Q35 broken office fixture failed.' );
}
$q35_paths[] = $broken_office;
file_put_contents( $broken_office, 'not-an-office-container' );
$broken_office_verdict = $inspector->inspect(
	$broken_office,
	'answer.docx',
	(int) filesize( $broken_office ),
	(string) hash_file( 'sha256', $broken_office )
);
g3_same( Submission_Zip_Item_Content_Verdict::REJECTED, $broken_office_verdict->state(), 'Malformed OOXML must be rejected instead of entering an endless inspector retry.' );

/**
 * G3-IMP-07-S02 asynchronous ZIP scan and Q35 safe projection contract.
 */
final class Q35_Preview_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Initialize q35 preview database dependencies and state.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	public function __construct( public array $root, public array $items ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'Q35:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}
	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		[ $sql, $arguments ] = $this->prepared( $prepared );
		if ( str_starts_with( $sql, 'SELECT a.public_id' ) ) {
			return $arguments[0] === $this->root['upload_id'] ? array(
				'assignment_id'    => $this->root['assignment_id'],
				'context_type'     => 'class',
				'context_id'       => 'class-21',
				'context_revision' => 'class-r4',
			) : null;
		}
		if ( str_contains( $sql, 'u.id AS upload_pk' ) ) {
			return $arguments === array( $this->root['upload_id'], 'class', 'class-21', 'class-r4' ) ? $this->root : null;
		}
		throw new RuntimeException( 'Unexpected Q35 preview row SQL.' );
	}
	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, mixed $output = null ): array {
		unset( $output );
		[ $sql ] = $this->prepared( $prepared );
		if ( ! str_contains( $sql, 'oj_ai_zip_upload_items' ) ) {
			throw new RuntimeException( 'Unexpected Q35 items SQL.' );
		} return $this->items;
	}
	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Bad Q35 SQL.' );
		} return $decoded;
	}
}

$canonical            = new Canonical_Json();
$preview_mapping_json = $canonical->encode( $bundle['repository']->mapping );
$preview_database     = new Q35_Preview_Database(
	array(
		'upload_pk'       => 11,
		'upload_id'       => $upload_id,
		'assignment_id'   => $assignment_id,
		'created_at_utc'  => '2026-08-22 11:00:00',
		'updated_at_utc'  => '2026-08-22 12:00:00',
		'state'           => 'ready',
		'mapping_schema'  => 'oj_ai.submission_zip_mapping',
		'mapping_version' => 1,
		'mapping_json'    => $preview_mapping_json,
		'mapping_hash'    => hash( 'sha256', $preview_mapping_json ),
		'item_total'      => 3,
		'success_total'   => 1,
		'failure_total'   => 2,
	),
	array_map( static fn ( array $item ): array => array_intersect_key( $item, array_flip( array( 'item_no', 'relative_path', 'state', 'byte_size', 'error_code' ) ) ), $bundle['repository']->items )
);
$preview_query        = new WordPress_Submission_Zip_Preview_Query( $preview_database, $canonical );
$authorization        = $preview_query->authorization_snapshot( $upload_id );
g3_same( $assignment_id, $authorization['assignment_id'] ?? null, 'Q35 authorization must resolve the parent assignment without changing Object Scope types.' );
$preview = $preview_query->find_preview( $upload_id, 'class', 'class-21', 'class-r4' );
g3_same( 1, $preview['mapping_version'] ?? null, 'Q35 safe projection must expose the optimistic mapping version.' );
g3_same( 3, count( $preview['items'] ?? array() ), 'Q35 safe projection must expose every item result.' );
$preview_json = $canonical->encode( $preview );
foreach ( array( 'storage_key', 'file_public_id', 'source_sha256', 'source_file_id', 'mapping_hash' ) as $forbidden ) {
	g3_assert( ! str_contains( $preview_json, $forbidden ), 'Q35 leaked private/internal field: ' . $forbidden );
}

$addon_root = dirname( __DIR__, 2 );
$sources    = array(
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Application/Run_Next_Submission_Zip_Scan.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Scan_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Preview_Query.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Presentation/Submission_Zip_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S02 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S02 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S02 must not consume OJ Platform internals.' );
}
foreach ( array( "'oj_ai_zip_uploads'", "'oj_ai_zip_upload_items'", "'oj_ai_private_files'", 'START TRANSACTION', 'FOR UPDATE', 'lock_version' ) as $marker ) {
	g3_assert( str_contains( $sources[1], $marker ), 'S02 scan repository marker is missing: ' . $marker );
}
foreach ( array( 'ROUTE_PREVIEW', "'GET'", 'MANAGE_SUBMISSIONS', "'view_submissions'", 'Context_Scope::OPERATION_MANAGE' ) as $marker ) {
	g3_assert( str_contains( $sources[3], $marker ), 'Q35 REST marker is missing: ' . $marker );
}
g3_assert( ! str_contains( $sources[0], 'ZipArchive' ), 'S02 extraction must not depend on the optional ZipArchive extension.' );
g3_assert( str_contains( $sources[4], 'Submission_Zip_Scan_Worker_Module' ), 'The single Composition Root must wire the S02 worker.' );

foreach ( $q35_paths as $path ) {
	@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G3-IMP-07-S02 passed: bounded extraction, fail-closed malware and MIME checks, partial item results, review-only suggestions, private staging, and Q35 safe projection.\n";
