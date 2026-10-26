<?php
/**
 * G3-IMP-05-S01 C24 private attachment staging contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Stage_Own_Attachment;
use OJAI\Dashboard\Submission\Student\Application\Stage_Own_Attachment_Command;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C24 id.
 *
 * @param int $number Number.
 * @return string
 */
function c24_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Access implements Student_Assignment_Access_Port_Interface {
	/**
	 * Initialize c24 access dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( public array $snapshot ) {
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		unset( $assignment_id, $actor_user_id );
		return null;
	}
	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		return 7 === $actor_user_id && $submission_id === $this->snapshot['submission_id'] ? $this->snapshot : null;
	}
}

/**
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Contexts implements Learning_Context_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Active.
	 *
	 * @var bool
	 */
	public bool $active = true;
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		g3_same( 7, $actor_user_id, 'C24 Context lookup must use the authenticated learner.' );
		if ( ! $this->active ) {
			return array();
		}
		return array(
			'class:class-21' => array(
				'type'            => 'class',
				'id'              => 'class-21',
				'label'           => '2학년 1반',
				'lifecycle_state' => 'active',
				'revision'        => 'member-r4',
				'attributes'      => array(),
			),
		);
	}
}

/**
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Attachments implements Student_Attachment_Repository_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Stage calls.
	 *
	 * @var int
	 */
	public int $stage_calls = 0;
	/**
	 * Fail stage.
	 *
	 * @var bool
	 */
	public bool $fail_stage = false;
	/**
	 * Initialize c24 attachments dependencies and state.
	 *
	 * @param array<string,mixed> $profile Profile.
	 */
	public function __construct( public array $profile ) {
	}
	/**
	 * Staging profile.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function staging_profile( string $submission_id, int $actor_user_id ): ?array {
		return 7 === $actor_user_id && $submission_id === $this->profile['submission_id'] ? $this->profile : null;
	}
	/**
	 * Find by file public id.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $file_id File id.
	 * @return array|null
	 */
	public function find_by_file_public_id( string $submission_id, int $actor_user_id, string $file_id ): ?array {
		if ( 7 !== $actor_user_id || $submission_id !== $this->profile['submission_id'] ) {
			return null;
		}
		return $this->rows[ $file_id ] ?? null;
	}
	/**
	 * Stage.
	 *
	 * @param array $record Record.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function stage( array $record ): array {
		++$this->stage_calls;
		if ( $this->fail_stage ) {
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$file_id = (string) $record['file_public_id'];
		if ( isset( $this->rows[ $file_id ] ) ) {
			$row = $this->rows[ $file_id ];
			if ( $row['response_key'] !== $record['response_id'] ) {
				throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
			}
			return array(
				'attachment_id' => $row['public_id'],
				'file_id'       => $file_id,
				'response_id'   => $row['response_key'],
				'original_name' => $row['original_name'],
				'mime_type'     => $row['mime_type'],
				'byte_size'     => $row['byte_size'],
				'sha256'        => $row['sha256'],
				'state'         => $row['state'],
				'replayed'      => true,
			);
		}
		$row                    = array(
			'public_id'              => $record['attachment_public_id'],
			'file_public_id'         => $file_id,
			'response_key'           => $record['response_id'],
			'original_name'          => $record['original_name'],
			'mime_type'              => $record['mime_type'],
			'byte_size'              => $record['byte_size'],
			'sha256'                 => $record['sha256'],
			'state'                  => 'scanning',
			'submission_revision_id' => null,
			'bound_at_utc'           => null,
		);
		$this->rows[ $file_id ] = $row;
		return array(
			'attachment_id' => $row['public_id'],
			'file_id'       => $file_id,
			'response_id'   => $row['response_key'],
			'original_name' => $row['original_name'],
			'mime_type'     => $row['mime_type'],
			'byte_size'     => $row['byte_size'],
			'sha256'        => $row['sha256'],
			'state'         => $row['state'],
			'replayed'      => false,
		);
	}
}

/**
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Metadata implements Private_File_Staging_Port_Interface {
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Files implements Private_File_Store_Port_Interface {
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
		g3_same( hash_file( 'sha256', $source_path ), $sha256, 'C24 must pass the byte-derived hash to private storage.' );
		if ( isset( $this->rows[ $storage_key ] ) ) {
			return false;
		}
		$this->rows[ $storage_key ] = $sha256;
		return true;
	}
	/**
	 * Delete c24 files.
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
 * G3-IMP-05-S01 C24 private attachment staging contract.
 */
final class C24_Request {
	/**
	 * Initialize c24 request dependencies and state.
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

$submission_id     = c24_id( 1 );
$assignment_id     = c24_id( 2 );
$response_id       = c24_id( 3 );
$other_response_id = c24_id( 4 );
$publication_hash  = hash( 'sha256', 'c24-publication' );
$schedule_hash     = hash( 'sha256', 'c24-schedule' );
$save_token        = hash( 'sha256', 'c24-save-token' );
$snapshot          = array(
	'submission_id'    => $submission_id,
	'assignment_id'    => $assignment_id,
	'context_type'     => 'class',
	'context_id'       => 'class-21',
	'context_revision' => 'target-r1',
	'current_revision' => '0',
	'state'            => 'draft',
	'assignment_state' => 'open',
	'target_state'     => 'active',
);
$profile           = array_merge(
	$snapshot,
	array(
		'lock_version'        => 0,
		'current_revision_id' => null,
		'publication_id'      => c24_id( 5 ),
		'publication_hash'    => $publication_hash,
		'schedule_version'    => 1,
		'schedule_hash'       => $schedule_hash,
		'save_token'          => $save_token,
		'payload'             => array(
			'items' => array(
				array(
					'response_id' => $response_id,
					'kind'        => 'image_upload',
					'constraints' => array(
						'mime_types' => array( 'image/png' ),
						'max_files'  => 2,
						'max_bytes'  => 1048576,
					),
				),
				array(
					'response_id' => $other_response_id,
					'kind'        => 'image_upload',
					'constraints' => array(
						'mime_types' => array( 'image/png' ),
						'max_files'  => 2,
						'max_bytes'  => 1048576,
					),
				),
			),
		),
	)
);
$png_path          = tempnam( sys_get_temp_dir(), 'oj-c24-' );
if ( false === $png_path ) {
	throw new RuntimeException( 'C24 PNG fixture could not be created.' );
}
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
if ( ! is_string( $png ) || false === file_put_contents( $png_path, $png ) ) {
	throw new RuntimeException( 'C24 PNG fixture could not be written.' );
}
$upload      = array(
	'name'     => 'answer.png',
	'type'     => 'text/html',
	'tmp_name' => $png_path,
	'error'    => UPLOAD_ERR_OK,
	'size'     => strlen( $png ),
);
$contexts    = new C24_Contexts();
$attachments = new C24_Attachments( $profile );
$metadata    = new C24_Metadata();
$files       = new C24_Files();
$seed        = 120;
$ids         = new Public_Id_Generator(
	static function ( int $length ) use ( &$seed ): string {
		++$seed;
		return str_repeat( chr( $seed ), $length );
	}
);
$use_case    = new Stage_Own_Attachment(
	new C24_Access( $snapshot ),
	$contexts,
	$attachments,
	$metadata,
	$files,
	new Submission_Attachment_Upload_Profile(),
	$ids,
	static fn (): string => '2026-08-22 10:00:00'
);
$command     = new Stage_Own_Attachment_Command(
	7,
	$submission_id,
	'attachment-command-0001',
	$response_id,
	'file',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	$upload,
	hash( 'sha256', 'c24-key-1' ),
	'attachment-correlation-0001'
);
$created     = $use_case->execute( $command );
g3_same( 'image/png', $created->attachment()['mime_type'], 'C24 must ignore the browser MIME and detect PNG bytes.' );
g3_same( 'scanning', $created->attachment()['state'], 'C24 must enqueue a new attachment for scanning.' );
g3_same( 'pending', $created->attachment()['scan_state'], 'C24 private metadata must begin with pending scan state.' );
g3_same( '2026-08-23 10:00:00', $created->expires_at_utc(), 'Unbound C24 staging must expire after 24 hours.' );
g3_same( false, $created->replayed(), 'The first C24 request must not be a replay.' );
g3_same( 2, $contexts->calls, 'C24 must recheck current Context immediately before writing.' );
g3_same( 1, $attachments->stage_calls, 'The first C24 request must stage one attachment row.' );

$replayed = $use_case->execute( $command );
g3_same( true, $replayed->replayed(), 'An exact C24 retry must replay the same receipt.' );
g3_same( $created->attachment()['id'], $replayed->attachment()['id'], 'C24 replay must return the original attachment ID.' );
g3_same( 1, $attachments->stage_calls, 'C24 replay must not add another attachment row.' );
g3_same( 1, count( $files->rows ), 'C24 replay must not duplicate private binary storage.' );

$changed_response = new Stage_Own_Attachment_Command(
	7,
	$submission_id,
	'attachment-command-0002',
	$other_response_id,
	'file',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	$upload,
	hash( 'sha256', 'c24-key-1' ),
	'attachment-correlation-0002'
);
g3_failure( static fn () => $use_case->execute( $changed_response ), Assignment_Failure::IDEMPOTENCY_CONFLICT, 'One C24 idempotency key must not be reused for another response.' );

$spoof_path = tempnam( sys_get_temp_dir(), 'oj-c24-spoof-' );
if ( false === $spoof_path || false === file_put_contents( $spoof_path, '<script>alert(1)</script>' ) ) {
	throw new RuntimeException( 'C24 spoof fixture failed.' );
}
$spoof         = array(
	'name'     => 'spoof.png',
	'type'     => 'image/png',
	'tmp_name' => $spoof_path,
	'error'    => UPLOAD_ERR_OK,
	'size'     => filesize( $spoof_path ),
);
$spoof_command = new Stage_Own_Attachment_Command(
	7,
	$submission_id,
	'attachment-command-spoof',
	$response_id,
	'file',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	$spoof,
	hash( 'sha256', 'c24-key-spoof' ),
	'attachment-correlation-spoof'
);
g3_failure( static fn () => $use_case->execute( $spoof_command ), Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA, 'C24 must reject an extension/MIME spoof by magic bytes.' );

$unknown_command = new Stage_Own_Attachment_Command(
	7,
	$submission_id,
	'attachment-command-unknown',
	c24_id( 99 ),
	'file',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	$upload,
	hash( 'sha256', 'c24-key-unknown' ),
	'attachment-correlation-unknown'
);
g3_failure( static fn () => $use_case->execute( $unknown_command ), Assignment_Failure::SUBMISSION_VALIDATION_FAILED, 'C24 must reject an unknown P02 response ID.' );

$contexts->active = false;
g3_failure(
	static fn () => $use_case->execute(
		new Stage_Own_Attachment_Command(
			7,
			$submission_id,
			'attachment-command-context',
			$response_id,
			'file',
			'draft',
			0,
			null,
			1,
			$schedule_hash,
			$publication_hash,
			$save_token,
			$upload,
			hash( 'sha256', 'c24-key-context' ),
			'attachment-correlation-context'
		)
	),
	Assignment_Failure::NOT_FOUND,
	'C24 must fail closed after current Context participation is lost.'
);
$contexts->active = true;

$failure_attachments             = new C24_Attachments( $profile );
$failure_attachments->fail_stage = true;
$failure_metadata                = new C24_Metadata();
$failure_files                   = new C24_Files();
$failure_use_case                = new Stage_Own_Attachment(
	new C24_Access( $snapshot ),
	new C24_Contexts(),
	$failure_attachments,
	$failure_metadata,
	$failure_files,
	new Submission_Attachment_Upload_Profile(),
	$ids,
	static fn (): string => '2026-08-22 10:05:00'
);
$failure_command                 = new Stage_Own_Attachment_Command(
	7,
	$submission_id,
	'attachment-command-failure',
	$response_id,
	'file',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	$upload,
	hash( 'sha256', 'c24-key-failure' ),
	'attachment-correlation-failure'
);
g3_failure( static fn () => $failure_use_case->execute( $failure_command ), Assignment_Failure::STORAGE_UNAVAILABLE, 'C24 must surface attachment-row persistence failure.' );
$failed_file = reset( $failure_metadata->rows );
g3_same( 'purged', $failed_file['state'] ?? null, 'C24 must mark metadata purged when attachment staging fails.' );
g3_same( 0, count( $failure_files->rows ), 'C24 compensation must remove an unreferenced private binary.' );

$controller   = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->getMethod( 'attachment_input' );
$multipart    = array(
	'command_id'                   => 'attachment-rest-0001',
	'response_id'                  => $response_id,
	'role'                         => 'file',
	'expected_state'               => 'draft',
	'expected_lock_version'        => '0',
	'expected_current_revision_id' => '',
	'expected_schedule_version'    => '1',
	'expected_schedule_hash'       => $schedule_hash,
	'expected_publication_hash'    => $publication_hash,
	'expected_save_token'          => $save_token,
);
$parsed       = $input_method->invoke( $controller, new C24_Request( $multipart, array( 'file' => $upload ) ) );
g3_same( 0, $parsed['expected_lock_version'], 'C24 multipart parsing must strictly convert the lock version.' );
g3_same( null, $parsed['expected_current_revision_id'], 'C24 multipart empty revision must map to null.' );
$modern_upload = $upload + array( 'full_path' => '../../untrusted/' . $upload['name'] );
$modern_parsed = $input_method->invoke( $controller, new C24_Request( $multipart, array( 'file' => $modern_upload ) ) );
g3_same( $upload, $modern_parsed['file'], 'C24 must ignore PHP 8.1 client full_path without changing the trusted temporary file.' );
$invalid_upload = $upload + array( 'full_path' => array( 'directory/file' ) );
g3_invalid( static fn () => $input_method->invoke( $controller, new C24_Request( $multipart, array( 'file' => $invalid_upload ) ) ), 'C24 must reject nested full_path metadata.' );
$unknown_upload = $modern_upload + array( 'unexpected' => 'value' );
g3_invalid( static fn () => $input_method->invoke( $controller, new C24_Request( $multipart, array( 'file' => $unknown_upload ) ) ), 'C24 must still reject unknown file metadata.' );
$multipart['unknown'] = 'no';
g3_invalid( static fn () => $input_method->invoke( $controller, new C24_Request( $multipart, array( 'file' => $upload ) ) ), 'C24 REST must reject unknown multipart fields.' );

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Storage/Infrastructure/WordPress_Private_File_Staging_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Attachment_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C24 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C24 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C24 must not consume OJ Platform internals.' );
}
g3_assert( str_contains( $sources[0], "'oj_ai_private_files'" ), 'C24 storage owner must use the literal private file table.' );
g3_assert( str_contains( $sources[1], "'oj_ai_submission_attachments'" ), 'C24 submission owner must use the literal attachment table.' );
foreach ( array( '/attachments', 'get_file_params', 'student_submission.attachment.stage', '? 200 : 202' ) as $marker ) {
	g3_assert( str_contains( $sources[2], $marker ), 'C24 REST marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[3], 'Stage_Own_Attachment' ), 'The single Composition Root must wire C24.' );

@unlink( $png_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@unlink( $spoof_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G3-IMP-05-S01 passed: C24 private staging, byte-derived media validation, self Context recheck, replay hardening, compensation, and literal-table boundaries.\n";
