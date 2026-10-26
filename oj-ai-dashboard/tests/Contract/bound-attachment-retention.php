<?php
/**
 * Real SQL/file regression for retained attachments and image-bearing submission PDFs.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
require __DIR__ . '/teacher-attachment-content.php';

use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Bound_Attachment_Retention_Repair;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Submission_Autosave;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Attachment;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Attachment_Query;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer;

// SQLite executes the joins/updates/transactions; InnoDB row locking is a separate server test.
$sql       = new class( $db ) {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail update.
	 *
	 * @var bool
	 */
	public bool $fail_update = false;
	/**
	 * Fail commit.
	 *
	 * @var bool
	 */
	public bool $fail_commit = false;
	/**
	 * Initialize bound-attachment-retention dependencies and state.
	 *
	 * @param Student_Result_Navigation_Database $source Source.
	 */
	public function __construct( public Student_Result_Navigation_Database $source ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return $this->source->prepare( $query, ...$args );
	}
	/**
	 * Get row.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array|null
	 */
	public function get_row( string $query, mixed $mode = null ): ?array {
		return $this->source->get_row( str_replace( ' FOR UPDATE', '', $query ), $mode );
	}
	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array
	 */
	public function get_results( string $query, mixed $mode = null ): array {
		return $this->source->get_results( str_replace( ' FOR UPDATE', '', $query ), $mode );
	}
	/**
	 * Get var.
	 *
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( string $query ): mixed {
		return $this->source->get_var( $query );
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return int
	 */
	public function insert( string $table, array $data ): int {
		return $this->source->insert( $table, $data );
	}
	/**
	 * Query.
	 *
	 * @param string $query Query.
	 * @return int|false
	 */
	public function query( string $query ): int|false {
		if ( $this->fail_update && str_starts_with( $query, 'UPDATE oj_ai_private_files' ) ) {
			return 0;
		}
		if ( $this->fail_commit && 'COMMIT' === $query ) {
			return false;
		}
		return $this->source->query( 'START TRANSACTION' === $query ? 'BEGIN' : $query );
	}
	/**
	 * Update bound-attachment-retention.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @param array  $where Where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		$set        = array_map( static fn ( string $key ): string => '`' . $key . '` = ?', array_keys( $data ) );
		$conditions = array_map( static fn ( string $key ): string => '`' . $key . '` IS ?', array_keys( $where ) );
		$statement  = $this->source->pdo->prepare( 'UPDATE `' . $table . '` SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
		$statement->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $statement->rowCount();
	}
};
$temporary = sys_get_temp_dir() . '/ojai-retention-' . bin2hex( random_bytes( 8 ) );
mkdir( $temporary, 0700 );
$drawing = imagecreatetruecolor( 360, 140 );
imagefill( $drawing, 0, 0, imagecolorallocate( $drawing, 255, 255, 255 ) );
imagesetthickness( $drawing, 4 );
$ink = imagecolorallocate( $drawing, 0, 120, 100 );
imageline( $drawing, 20, 110, 100, 65, $ink );
imageline( $drawing, 100, 65, 180, 90, $ink );
imageline( $drawing, 180, 90, 280, 25, $ink );
imageellipse( $drawing, 280, 25, 20, 20, imagecolorallocate( $drawing, 190, 50, 60 ) );
ob_start();
imagepng( $drawing );
$png = ob_get_clean();
file_put_contents( $temporary . '/image.png', $png );
$store = new Local_Private_File_Store( $temporary . '/private' );
$store->put_quarantined( $temporary . '/image.png', 'answer.png', hash( 'sha256', $png ) );
$expires     = '2026-08-23 00:00:00';
$file_fields = array(
	'state'           => 'ready',
	'scan_state'      => 'clean',
	'storage_key'     => 'answer.png',
	'original_name'   => 'drawing.png',
	'mime_type'       => 'image/png',
	'byte_size'       => strlen( $png ),
	'sha256'          => hash( 'sha256', $png ),
	'lock_version'    => 0,
	'retention_class' => 'submission_staging',
	'expires_at_utc'  => $expires,
	'purged_at_utc'   => null,
);
foreach ( $file_fields as $key => $value ) {
	$db->change( 'oj_ai_private_files', $key, $value, 503 );
}
foreach ( array_intersect_key( $file_fields, array_flip( array( 'original_name', 'mime_type', 'byte_size', 'sha256', 'lock_version' ) ) ) as $key => $value ) {
	$db->change( 'oj_ai_submission_attachments', $key, $value, 502 );
}
$db->change( 'oj_ai_submission_attachments', 'state', 'ready', 502 );
$db->change( 'oj_ai_submission_attachments', 'submission_revision_id', null, 502 );
$attachment                            = $db->get_row( 'SELECT * FROM oj_ai_submission_attachments WHERE id = 502' );
$listed                                = array_replace(
	$listed,
	array(
		'name'      => 'drawing.png',
		'mime_type' => 'image/png',
		'byte_size' => strlen( $png ),
		'sha256'    => hash( 'sha256', $png ),
	)
);
$listed['revision_scope']              = array( 'official' );
$listed['state']                       = 'bound';
$projection->submission['attachments'] = array( $listed );
$official_id                           = $id( 201 );
$db->query( $db->prepare( 'UPDATE oj_ai_submissions SET official_revision_public_id = %s WHERE public_id = %s', $official_id, $submission_id ) );
$official_source = new class( $projection->submission, $official_id ) implements Submission_Print_Source_Query_Port_Interface {
	/**
	 * Initialize bound-attachment-retention dependencies and state.
	 *
	 * @param array  $value Value.
	 * @param string $id Id.
	 */
	public function __construct( public array $value, public string $id ) {
	}
	/**
	 * Find official revision.
	 *
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_official_revision( string $revision_id ): ?array {
		return $revision_id === $this->id ? $this->value : null;
	}
};
$reader          = new Read_Teacher_Attachment( new Read_Teacher_Submissions( $projection ), new WordPress_Teacher_Attachment_Query( $db ), $store, static fn (): string => '2026-08-24 10:00:00' );
$repair          = new WordPress_Bound_Attachment_Retention_Repair( $sql, $official_source, $store );
$payload         = array(
	'profile'     => 'teacher_submission',
	'document'    => array(
		'locale' => 'ko-KR',
		'nodes'  => array(
			array(
				'type'        => 'oj_ai_response',
				'response_id' => $response_id,
				'kind'        => 'image_upload',
				'label'       => '그림 답변',
				'children'    => array(),
			),
		),
	),
	'answers'     => array( $response_id => array( $attachment_id ) ),
	'attachments' => array( $listed ),
	'assignment'  => array(
		'title'   => '첨부 보존 PDF 검사',
		'context' => array(
			'type'     => 'class',
			'id'       => 'class:3',
			'revision' => 'revision-1',
		),
	),
	'learner'     => array( 'display_name' => '시험 학생' ),
	'submission'  => array(
		'id'               => $submission_id,
		'attempt_no'       => 1,
		'submitted_at_utc' => '2026-08-22 09:00:00',
	),
	'provenance'  => array( 'submission_revision_id' => $official_id ),
);
$print           = array(
	'kind'                        => 'submission_print',
	'profile'                     => 'teacher_submission',
	'submission_print_projection' => array(
		'schema_id'         => 'oj_ai.submission_print',
		'schema_version'    => '1.0.0',
		'projector_version' => '1.0.0',
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
	),
);
$serializer      = new Submission_Print_Html_Serializer( $canonical, $reader );
try {
	$binding = new WordPress_Student_Submission_Autosave( $sql, $canonical );
	( new ReflectionMethod( $binding, 'bind_attachment' ) )->invoke(
		$binding,
		$attachment,
		42,
		array(
			'actor_user_id'        => 7,
			'submission_public_id' => $submission_id,
			'occurred_at_utc'      => '2026-08-22 09:00:00',
		)
	);
	$assert( null === $db->get_var( 'SELECT expires_at_utc FROM oj_ai_private_files WHERE id = 503' ), 'Real binding clears only staging expiry.' );
	$assert( $png === $read_bytes( $reader->open( ...$args ) ), 'Bound bytes readable after original expiry.' );
	$db->change( 'oj_ai_private_files', 'expires_at_utc', $expires, 503 );
	$denied( static fn () => $reader->open( ...$args ), 'Legacy bound-file expiry reproduces read failure.' );
	$denied( static fn () => $serializer->serialize( $print, $official_id ), 'Legacy expiry reproduces image PDF failure.' );
	$before  = $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' );
	$preview = $repair->repair( $attachment_id, 19, '2026-08-24 10:00:00' );
	$assert( 'repairable' === $preview['status'], 'Explicit dry-run identifies valid official attachment.' );
	$assert( $before === $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' ), 'Dry-run is read-only.' );
	foreach ( array( 'fail_update', 'fail_commit' ) as $failure ) {
		$sql->{$failure} = true;
		$blocked         = false;
		try {
			$repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true, $preview ); } catch ( Throwable ) {
			$blocked = true;
			}
			$sql->{$failure} = false;
			$assert( $blocked && $before === $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' ), 'Repair rollback after ' . $failure );
	}
	$changed_preview = $preview;
	++$changed_preview['before']['lock_version'];
	$blocked = false;
	try {
		$repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true, $changed_preview ); } catch ( Throwable ) {
		$blocked = true;
		}
		$assert( $blocked && $before === $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' ), 'Outdated preview cannot authorize a different file state.' );
		$assert( 'repaired' === $repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true, $preview )['status'], 'Repair clears stale upload expiry.' );
		$assert( 'unchanged' === $repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true )['status'], 'Repair is idempotent.' );
		$assert( $png === $read_bytes( $reader->open( ...$args ) ), 'Original bytes readable after repair.' );
		$html = $serializer->serialize( $print, $official_id );
		$assert( str_contains( $html, 'data:image/png;base64,' ), 'PDF serializer embeds integrity-checked image.' );
		require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$pdf = ( new Dompdf_Html_To_Pdf_Renderer() )->render( $html );
		$assert( str_starts_with( $pdf->bytes(), '%PDF-' ) && $pdf->page_count() > 0, 'Actual PDF renderer completes with bound image.' );
		$output = dirname( __DIR__, 2 ) . '/_notes/core-defects-1.0';
		if ( ! is_dir( $output ) ) {
			mkdir( $output, 0700, true );
		}
		file_put_contents( $output . '/retained-image-submission.pdf', $pdf->bytes() );
		file_put_contents( $output . '/retained-image-submission.html', $html );
		$valid = $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' );
		foreach ( array(
			'owner_public_id'    => $id( 999 ),
			'retention_class'    => 'worksheet_asset',
			'scan_state'         => 'infected',
			'state'              => 'expired',
			'purged_at_utc'      => '2026-08-23 01:00:00',
			'sha256'             => hash( 'sha256', 'wrong' ),
			'byte_size'          => 999,
			'created_by_user_id' => 99,
			'expires_at_utc'     => '2026-08-22 08:00:00',
			'storage_key'        => 'missing.png',
		) as $key => $value ) {
			$db->change( 'oj_ai_private_files', $key, $value, 503 );
			$before  = $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' );
			$blocked = false;
			try {
				$repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true ); } catch ( Throwable ) {
						$blocked = true;
				}
				$assert( $blocked && $before === $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = 503' ), 'Unsafe/missing file is not revived: ' . $key );
				$db->change( 'oj_ai_private_files', $key, $valid[ $key ], 503 );
		}
		$official_source->value['attachments'][0]['revision_scope'] = array( 'current' );
		$blocked = false;
		try {
			$repair->repair( $attachment_id, 19, '2026-08-24 10:00:00', true ); } catch ( Throwable ) {
				$blocked = true;
			}
			$assert( $blocked, 'Draft-only attachment cannot be repaired as an official submission.' );
			echo "Bound attachment retention passed: production binding SQL, dry-run, atomic repair, idempotency, unsafe/missing rejection, original download and real image PDF.\n";
} finally {
	foreach ( array( '/private/answer.png', '/image.png' ) as $file ) {
		if ( is_file( $temporary . $file ) ) {
			unlink( $temporary . $file );
		}
	}
	rmdir( $temporary . '/private' );
	rmdir( $temporary );
}
