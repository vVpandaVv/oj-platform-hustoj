<?php
/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Result;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Repository;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Presentation\Submission_Zip_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * Z19 paths.
 *
 * @var array<int,string> $z19_paths
 */
$z19_paths = array();

/**
 * Z19 zip.
 *
 * @param array<int,array<string,mixed>> $entries Entries.
 * @param array<string,int>              $options Options.
 * @return string
 * @throws \Throwable Propagates validation or dependency failures.
 */
function z19_zip( array $entries, array $options = array() ): string {
	global $z19_paths;
	$locals  = '';
	$central = '';
	foreach ( $entries as $entry ) {
		$name         = (string) $entry['name'];
		$data         = (string) ( $entry['data'] ?? 'x' );
		$flags        = (int) ( $entry['flags'] ?? 0 );
		$method       = (int) ( $entry['method'] ?? 0 );
		$made         = (int) ( $entry['made'] ?? ( ( 3 << 8 ) | 20 ) );
		$external     = (int) ( $entry['external'] ?? ( 0100644 << 16 ) );
		$compressed   = (int) ( $entry['compressed'] ?? strlen( $data ) );
		$uncompressed = (int) ( $entry['uncompressed'] ?? strlen( $data ) );
		$extra        = (string) ( $entry['extra'] ?? '' );
		$crc          = crc32( $data );
		$offset       = strlen( $locals );
		$locals      .= pack(
			'VvvvvvVVVvv',
			0x04034b50,
			20,
			$flags,
			$method,
			0,
			0,
			$crc,
			$compressed,
			$uncompressed,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra . $data;
		$central     .= pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			$made,
			20,
			$flags,
			$method,
			0,
			0,
			$crc,
			$compressed,
			$uncompressed,
			strlen( $name ),
			strlen( $extra ),
			0,
			(int) ( $entry['disk_start'] ?? 0 ),
			0,
			$external,
			$offset
		) . $name . $extra;
	}
	$central_offset = strlen( $locals );
	$count          = count( $entries );
	$eocd           = pack(
		'VvvvvVVv',
		0x06054b50,
		(int) ( $options['disk_number'] ?? 0 ),
		(int) ( $options['central_disk'] ?? 0 ),
		(int) ( $options['entries_on_disk'] ?? $count ),
		$count,
		strlen( $central ),
		$central_offset,
		0
	);
	$path           = tempnam( sys_get_temp_dir(), 'oj-z19-' );
	if ( false === $path || false === file_put_contents( $path, $locals . $central . $eocd ) ) {
		throw new RuntimeException( 'C19 ZIP fixture could not be written.' );
	}
	$z19_paths[] = $path;
	return $path;
}

/**
 * Z19 upload.
 *
 * @param string $path Path.
 * @param string $name Name.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function z19_upload( string $path, string $name = 'submissions.zip' ): array {
	$size = filesize( $path );
	if ( ! is_int( $size ) ) {
		throw new RuntimeException( 'C19 fixture size is unavailable.' );
	}
	return array(
		'name'     => $name,
		'type'     => 'text/html',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => $size,
	);
}

/**
 * Z19 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function z19_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation(); } catch ( Zip_Import_Failure $failure ) {
		g3_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ' No Zip_Import_Failure was thrown.' );
}

/**
 * Z19 profile failure.
 *
 * @param Submission_Zip_Upload_Profile  $profile Profile.
 * @param array<int,array<string,mixed>> $entries Entries.
 * @param string                         $reason Reason.
 * @param string                         $message Message.
 * @param array<string,int>              $options Options.
 * @return void
 */
function z19_profile_failure( Submission_Zip_Upload_Profile $profile, array $entries, string $reason, string $message, array $options = array() ): void {
	$path = z19_zip( $entries, $options );
	z19_failure( static fn () => $profile->inspect( z19_upload( $path ) ), $reason, $message );
}

/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Assignments implements Assignment_Repository_Port_Interface {
	/**
	 * Snapshot calls.
	 *
	 * @var int
	 */
	public int $snapshot_calls = 0;
	/**
	 * Initialize z19 assignments dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( public array $snapshot ) {
	}
	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Assignment_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Create_Assignment_Result {
		unset( $idempotency_storage_key, $request_digest );
		return null;
	}
	/**
	 * Create z19 assignments.
	 *
	 * @param array $record Record.
	 * @return Create_Assignment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( array $record ): Create_Assignment_Result {
		unset( $record );
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * List context.
	 *
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_context( string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): array {
		unset( $context_type, $context_id, $context_revision, $cursor, $limit );
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}
	/**
	 * Find context assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function find_context_assignment( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		unset( $assignment_id, $context_type, $context_id, $context_revision, $cursor, $limit );
		return null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id ): ?array {
		++$this->snapshot_calls;
		return $assignment_id === $this->snapshot['assignment_id'] ? $this->snapshot : null;
	}
}

/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Metadata implements Private_File_Staging_Port_Interface {
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_quarantined( array $record ): void {
		if ( isset( $this->rows[ $record['storage_key'] ] ) ) {
			throw new RuntimeException( 'Duplicate metadata.' );
		}
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
			if ( $row['public_id'] === $file_id && $row['actor_user_id'] === $actor_user_id ) {
				$row['state']         = 'purged';
				$row['purged_at_utc'] = $purged_at_utc;
				return;
			}
		}
	}
}

/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Files implements Private_File_Store_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,string>
	 */
	public array $rows = array();
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
		g3_same( hash_file( 'sha256', $source_path ), $sha256, 'C19 private storage must receive the byte-derived hash.' );
		if ( isset( $this->rows[ $storage_key ] ) ) {
			return false;
		}
		$this->rows[ $storage_key ] = $sha256;
		return true;
	}
	/**
	 * Delete z19 files.
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
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Next id.
	 *
	 * @var int
	 */
	private int $next_id = 10;
	/**
	 * Transaction snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $transaction_snapshot = null;

	/**
	 * Initialize z19 database dependencies and state.
	 *
	 * @param array<string,mixed> $assignment Assignment.
	 */
	public function __construct( array $assignment ) {
		$this->tables = array(
			'oj_ai_assignments'   => array( $assignment ),
			'oj_ai_zip_uploads'   => array(),
			'oj_ai_audit_events'  => array(),
			'oj_ai_outbox_events' => array(),
		);
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return 'Z19:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		[ $sql, $args ] = $this->prepared( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $row['idempotency_key'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments' ) ) {
			foreach ( $this->tables['oj_ai_assignments'] as $row ) {
				if ( $row['public_id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_zip_uploads' ) ) {
			foreach ( $this->tables['oj_ai_zip_uploads'] as $row ) {
				if ( $row['public_id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C19 get_row SQL: ' . $sql );
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'Unexpected C19 get_var SQL.' );
		if ( array() === $this->tables['oj_ai_audit_events'] ) {
			return null;
		}
		$last = $this->tables['oj_ai_audit_events'][ count( $this->tables['oj_ai_audit_events'] ) - 1 ];
		return $last['event_hash'];
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ): int|false {
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C19 insert table: ' . $table );
		}
		$data['id']               = $this->next_id++;
		$this->insert_id          = $data['id'];
		$this->tables[ $table ][] = $data;
		return 1;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction_snapshot = unserialize( serialize( $this->tables ) ); } elseif ( 'ROLLBACK' === $sql && is_array( $this->transaction_snapshot ) ) {
			$this->tables               = $this->transaction_snapshot;
			$this->transaction_snapshot = null;
			} elseif ( 'COMMIT' === $sql ) {
				$this->transaction_snapshot = null;
			}
			return 1;
	}
	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		if ( ! str_starts_with( $value, 'Z19:' ) ) {
			throw new RuntimeException( 'Unexpected C19 prepared SQL.' );
		}
		$bytes   = base64_decode( substr( $value, 4 ), true );
		$decoded = unserialize( $bytes ? $bytes : '' );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) ) {
			throw new RuntimeException( 'Invalid C19 prepared SQL.' );
		}
		return $decoded;
	}
}

/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Failing_Repository implements Submission_Zip_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Stage_Submission_Zip_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest ): ?Stage_Submission_Zip_Result {
		unset( $storage_key, $request_digest );
		return null;
	}
	/**
	 * Stage.
	 *
	 * @param array $record Record.
	 * @return Stage_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function stage( array $record ): Stage_Submission_Zip_Result {
		unset( $record );
		throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
	}
}

/**
 * G3-IMP-07-S01 C19 private submission ZIP staging contract.
 */
final class Z19_Request {
	/**
	 * Initialize z19 request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param array<string,mixed> $files Files.
	 */
	public function __construct( private array $body, private array $files ) {
	}
	/**
	 * Get body params.
	 *
	 * @return array
	 */
	public function get_body_params(): array {
		return $this->body;
	}
	/**
	 * Get file params.
	 *
	 * @return array
	 */
	public function get_file_params(): array {
		return $this->files;
	}
}

$profile         = new Submission_Zip_Upload_Profile();
$oversize_path   = tempnam( sys_get_temp_dir(), 'oj-z19-large-' );
$oversize_handle = false === $oversize_path ? false : fopen( $oversize_path, 'c+b' );
if (
	false === $oversize_path || false === $oversize_handle
	|| 0 !== fseek( $oversize_handle, Submission_Zip_Upload_Profile::MAX_ARCHIVE_BYTES )
	|| 1 !== fwrite( $oversize_handle, "\0" )
) {
	throw new RuntimeException( 'C19 oversized fixture could not be created.' );
}
fclose( $oversize_handle );
$z19_paths[] = $oversize_path;
z19_failure( static fn () => $profile->inspect( z19_upload( $oversize_path ) ), Zip_Import_Failure::LIMIT_EXCEEDED, 'C19 must enforce the 100 MiB compressed source limit.' );
$valid_path   = z19_zip(
	array(
		array(
			'name' => 'student-101/answer.pdf',
			'data' => '%PDF-1.7 answer',
		),
		array(
			'name' => 'student-102/photo.png',
			'data' => "\x89PNG\r\n\x1a\nanswer",
		),
		array(
			'name' => 'student-103/essay.docx',
			'data' => 'office-package-placeholder',
		),
	)
);
$valid_upload = z19_upload( $valid_path );
$inspected    = $profile->inspect( $valid_upload );
g3_same( 'application/zip', $inspected['mime_type'], 'C19 must ignore the browser MIME and identify ZIP bytes.' );
g3_same( 3, $inspected['entry_count'], 'C19 must count regular entries without extracting them.' );
g3_same( strlen( '%PDF-1.7 answer' ) + strlen( "\x89PNG\r\n\x1a\nanswer" ) + strlen( 'office-package-placeholder' ), $inspected['uncompressed_bytes'], 'C19 central-directory totals drifted.' );

z19_profile_failure( $profile, array( array( 'name' => '../escape.pdf' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject traversal paths.' );
z19_profile_failure( $profile, array( array( 'name' => '/absolute.pdf' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject absolute paths.' );
z19_profile_failure( $profile, array( array( 'name' => 'folder\\answer.pdf' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject Windows separator ambiguity.' );
z19_profile_failure( $profile, array( array( 'name' => str_repeat( 'a', 501 ) . '.pdf' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject paths longer than 500 bytes.' );
z19_profile_failure( $profile, array( array( 'name' => 'nested.zip' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject nested archives.' );
z19_profile_failure(
	$profile,
	array(
		array(
			'name'  => 'secret.pdf',
			'flags' => 1,
		),
	),
	Zip_Import_Failure::UNSAFE_ARCHIVE,
	'C19 must reject encrypted entries.'
);
z19_profile_failure(
	$profile,
	array(
		array(
			'name'     => 'link.pdf',
			'external' => ( 0120777 << 16 ),
		),
	),
	Zip_Import_Failure::UNSAFE_ARCHIVE,
	'C19 must reject symlinks.'
);
z19_profile_failure( $profile, array( array( 'name' => 'answer.pdf' ) ), Zip_Import_Failure::UNSAFE_ARCHIVE, 'C19 must reject multi-disk archives.', array( 'disk_number' => 1 ) );
z19_profile_failure(
	$profile,
	array(
		array(
			'name'         => 'ratio.pdf',
			'compressed'   => 1,
			'uncompressed' => 21,
		),
	),
	Zip_Import_Failure::LIMIT_EXCEEDED,
	'C19 must enforce the per-item 20:1 compression ratio.'
);
z19_profile_failure(
	$profile,
	array(
		array(
			'name'         => 'large.pdf',
			'compressed'   => 1310721,
			'uncompressed' => 26214401,
		),
	),
	Zip_Import_Failure::LIMIT_EXCEEDED,
	'C19 must enforce the 25 MiB item limit.'
);

$many = array();
for ( $index = 0; $index < 501; ++$index ) {
	$many[] = array(
		'name' => 'items/' . $index . '.pdf',
		'data' => 'x',
	);
}
z19_profile_failure( $profile, $many, Zip_Import_Failure::LIMIT_EXCEEDED, 'C19 must enforce the 500 regular-entry limit.' );
$huge_total = array();
for ( $index = 0; $index < 21; ++$index ) {
	$huge_total[] = array(
		'name'         => 'large/' . $index . '.pdf',
		'data'         => 'x',
		'compressed'   => 1310720,
		'uncompressed' => 26214400,
	);
}
z19_profile_failure( $profile, $huge_total, Zip_Import_Failure::LIMIT_EXCEEDED, 'C19 must enforce the 500 MiB total uncompressed limit.' );

$canonical     = new Canonical_Json();
$assignment_id = str_pad( '701', 26, '0', STR_PAD_LEFT );
$snapshot      = array(
	'assignment_id'    => $assignment_id,
	'context_type'     => 'class',
	'context_id'       => 'class-21',
	'context_revision' => 'class-r4',
	'current_revision' => '3',
	'state'            => 'open',
);
$assignments   = new Z19_Assignments( $snapshot );
$database      = new Z19_Database(
	array(
		'id'               => 71,
		'public_id'        => $assignment_id,
		'context_type'     => 'class',
		'context_id'       => 'class-21',
		'context_revision' => 'class-r4',
		'state'            => 'open',
		'schedule_version' => 3,
	)
);
$metadata      = new Z19_Metadata();
$files         = new Z19_Files();
$seed          = 180;
$ids           = new Public_Id_Generator(
	static function ( int $length ) use ( &$seed ): string {
		++$seed;
		return str_repeat( chr( $seed ), $length );
	}
);
$use_case      = new Stage_Submission_Zip(
	$assignments,
	new WordPress_Submission_Zip_Repository( $database, $canonical ),
	$metadata,
	$files,
	$profile,
	$ids,
	$canonical,
	static fn (): string => '2026-08-22 11:00:00'
);
$command       = new Stage_Submission_Zip_Command(
	42,
	$assignment_id,
	'zip-command-0001',
	'open',
	3,
	'class',
	'class-21',
	'class-r4',
	$valid_upload,
	hash( 'sha256', 'zip-key-1' ),
	'zip-correlation-0001'
);
$created       = $use_case->execute( $command );
g3_same( false, $created->replayed(), 'The first C19 request must create one receipt.' );
g3_same( 'scanning', $created->upload()['state'], 'C19 must queue asynchronous inspection.' );
g3_same( '2026-08-23 11:00:00', $created->expires_at_utc(), 'C19 private staging must expire after 24 hours.' );
g3_same( 1, count( $database->tables['oj_ai_zip_uploads'] ), 'C19 must create one ZIP root.' );
g3_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C19 must create one audit event.' );
g3_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C19 must create one durable outbox receipt.' );
g3_same( 0, $database->tables['oj_ai_zip_uploads'][0]['item_total'], 'C19 must not create item mappings before asynchronous inspection.' );
$private = reset( $metadata->rows );
g3_same( 'assignment', $private['owner_type'] ?? null, 'C19 original ZIP must be owned by the assignment boundary.' );
g3_same( 'submission_zip_source', $private['purpose'] ?? null, 'C19 private source purpose drifted.' );
g3_same( 'pending', $private['scan_state'] ?? null, 'C19 source must remain pending until the scanner runs.' );
g3_assert( ! array_key_exists( 'storage_key', $created->upload() ), 'C19 response must not expose a private storage key.' );

$assignments->snapshot['state'] = 'closed';
$replayed                       = $use_case->execute( $command );
g3_same( true, $replayed->replayed(), 'An exact C19 retry must replay after mutable assignment state changes.' );
g3_same( $created->upload()['id'], $replayed->upload()['id'], 'C19 replay must preserve the original upload ID.' );
g3_same( 1, count( $database->tables['oj_ai_zip_uploads'] ), 'C19 replay must not duplicate the ZIP root.' );

$changed_path    = z19_zip(
	array(
		array(
			'name' => 'student-101/changed.pdf',
			'data' => 'changed',
		),
	)
);
$changed_command = new Stage_Submission_Zip_Command(
	42,
	$assignment_id,
	'zip-command-0001',
	'open',
	3,
	'class',
	'class-21',
	'class-r4',
	z19_upload( $changed_path ),
	hash( 'sha256', 'zip-key-1' ),
	'zip-correlation-0002'
);
z19_failure( static fn () => $use_case->execute( $changed_command ), Zip_Import_Failure::IDEMPOTENCY_CONFLICT, 'One C19 key must not accept changed ZIP bytes.' );

$failure_metadata = new Z19_Metadata();
$failure_files    = new Z19_Files();
$failure_use_case = new Stage_Submission_Zip(
	new Z19_Assignments( $snapshot ),
	new Z19_Failing_Repository(),
	$failure_metadata,
	$failure_files,
	$profile,
	$ids,
	$canonical,
	static fn (): string => '2026-08-22 11:05:00'
);
$failure_command  = new Stage_Submission_Zip_Command(
	42,
	$assignment_id,
	'zip-command-failure',
	'open',
	3,
	'class',
	'class-21',
	'class-r4',
	$valid_upload,
	hash( 'sha256', 'zip-key-failure' ),
	'zip-correlation-failure'
);
z19_failure( static fn () => $failure_use_case->execute( $failure_command ), Zip_Import_Failure::STORAGE_UNAVAILABLE, 'C19 must surface root persistence failure.' );
$failed_private = reset( $failure_metadata->rows );
g3_same( 'purged', $failed_private['state'] ?? null, 'C19 must mark newly-created private metadata purged after DB failure.' );
g3_same( 0, count( $failure_files->rows ), 'C19 compensation must remove an unreferenced private binary.' );

$controller   = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->getMethod( 'zip_input' );
$body         = array(
	'command_id'                => 'zip-rest-0001',
	'expected_schedule_version' => '3',
	'expected_state'            => 'open',
);
$parsed       = $input_method->invoke( $controller, new Z19_Request( $body, array( 'file' => $valid_upload ) ) );
g3_same( 3, $parsed['expected_schedule_version'], 'C19 multipart version must be converted strictly.' );
$body['unknown'] = 'no';
g3_invalid( static fn () => $input_method->invoke( $controller, new Z19_Request( $body, array( 'file' => $valid_upload ) ) ), 'C19 REST must reject unknown multipart fields.' );

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string $namespace Namespace.
	 * @param string $route Route.
	 * @param array  $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['z19_registered_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}
$GLOBALS['z19_registered_routes'] = array();
$controller->register_routes();
g3_same( 5, count( $GLOBALS['z19_registered_routes'] ), 'C19, Q35, C68, C20, and C21 must register exactly five routes.' );
g3_same( 'oj-ai/v1', $GLOBALS['z19_registered_routes'][0][0], 'C19 REST namespace drifted.' );
g3_same( Submission_Zip_Rest_Controller::ROUTE_STAGE, $GLOBALS['z19_registered_routes'][0][1], 'C19 route registration drifted.' );
g3_same( 'POST', $GLOBALS['z19_registered_routes'][0][2]['methods'], 'C19 must register a POST command.' );
g3_same( Submission_Zip_Rest_Controller::ROUTE_PREVIEW, $GLOBALS['z19_registered_routes'][1][1], 'Q35 route registration drifted.' );
g3_same( 'GET', $GLOBALS['z19_registered_routes'][1][2]['methods'], 'Q35 must register a GET projection.' );
g3_same( array( $controller, 'permissions_preview' ), $GLOBALS['z19_registered_routes'][1][2]['permission_callback'], 'Q35 permission callback drifted.' );
g3_same( Submission_Zip_Rest_Controller::ROUTE_MAP, $GLOBALS['z19_registered_routes'][2][1], 'C68 route registration drifted.' );
g3_same( 'POST', $GLOBALS['z19_registered_routes'][2][2]['methods'], 'C68 must register a POST command.' );
g3_same( array( $controller, 'permissions_map' ), $GLOBALS['z19_registered_routes'][2][2]['permission_callback'], 'C68 permission callback drifted.' );
g3_same( Submission_Zip_Rest_Controller::ROUTE_CONFIRM, $GLOBALS['z19_registered_routes'][3][1], 'C20 route registration drifted.' );
g3_same( 'POST', $GLOBALS['z19_registered_routes'][3][2]['methods'], 'C20 must register a POST command.' );
g3_same( array( $controller, 'permissions_confirm' ), $GLOBALS['z19_registered_routes'][3][2]['permission_callback'], 'C20 permission callback drifted.' );
g3_same( Submission_Zip_Rest_Controller::ROUTE_CANCEL, $GLOBALS['z19_registered_routes'][4][1], 'C21 route registration drifted.' );
g3_same( 'POST', $GLOBALS['z19_registered_routes'][4][2]['methods'], 'C21 must register a POST command.' );
g3_same( array( $controller, 'permissions_cancel' ), $GLOBALS['z19_registered_routes'][4][2]['permission_callback'], 'C21 permission callback drifted.' );

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Domain/Submission_Zip_Upload_Profile.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Application/Stage_Submission_Zip.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Presentation/Submission_Zip_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/Assignment_Object_Authorization.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C19 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C19 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C19 must not consume OJ Platform internals.' );
}
foreach ( array( "'oj_ai_assignments'", "'oj_ai_zip_uploads'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", 'START TRANSACTION', 'FOR UPDATE' ) as $marker ) {
	g3_assert( str_contains( $sources[2], $marker ), 'C19 repository marker is missing: ' . $marker );
}
foreach ( array( 'commands/import-zip', 'MANAGE_SUBMISSIONS', "'import_zip'", 'get_file_params', '? 200 : 202' ) as $marker ) {
	g3_assert( str_contains( $sources[3], $marker ), 'C19 REST marker is missing: ' . $marker );
}
g3_assert( ! str_contains( $sources[0], 'ZipArchive' ), 'C19 safety must not depend on an optional ZipArchive extension.' );
g3_assert( ! str_contains( implode( "\n", $sources ), 'Zip_Safety_Validator' ), 'C19 must not reuse the unsafe legacy ZIP validator.' );
g3_assert( ! str_contains( implode( "\n", $sources ), 'Zip_Upload_Service' ), 'C19 must not reuse the public legacy ZIP upload service.' );
g3_assert( str_contains( $sources[4], "'import_zip'" ), 'Assignment object policy must authorize C19 explicitly.' );
g3_assert( str_contains( $sources[5], 'Submission_Zip_Rest_Module' ), 'The single Composition Root must wire C19.' );

foreach ( $z19_paths as $path ) {
	@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G3-IMP-07-S01 passed: C19 central-directory safety, private-only staging, durable replay, compensation, exact REST, and literal-table boundaries.\n";
