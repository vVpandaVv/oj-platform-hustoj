<?php
/**
 * G3-IMP-08-S01-F Context-aware Q23/Q24 and teacher UI contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;

require __DIR__ . '/submission-print-projection-g3-imp-08-s01.php';

/**
 * G3s01f assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01f_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s01f same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01f_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3s01f failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01f_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Export_Failure $failure ) {
		g3s01f_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G3-IMP-08-S01-F Context-aware Q23/Q24 and teacher UI contract.
 */
final class G3S01F_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Last sql.
	 *
	 * @var string
	 */
	public string $last_sql = '';
	/**
	 * Last values.
	 *
	 * @var array<int,mixed>
	 */
	public array $last_values = array();
	/**
	 * Initialize g3 s01 f database dependencies and state.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public function __construct( private array $row ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		$this->last_sql    = $sql;
		$this->last_values = $values;
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $mode Mode.
	 * @return array<string,mixed>
	 */
	public function get_row( string $sql, string $mode ): array {
		unset( $sql, $mode );
		return $this->row;
	}
}

/**
 * G3-IMP-08-S01-F Context-aware Q23/Q24 and teacher UI contract.
 */
final class G3S01F_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Context revision.
	 *
	 * @var string
	 */
	public string $context_revision = 'class-r3';
	/**
	 * Relation.
	 *
	 * @var string
	 */
	public string $relation = 'context_staff';
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
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
		++$this->calls;
		$this->last_request = $request;
		if ( ! $this->allowed ) {
			return Access_Policy_Decision::denied( 403, 'test_access_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null === $context ? null : Context_Authorization_Result::allowed( $context->type(), $context->id(), 'active', $this->context_revision, 'educator' ),
			null === $object ? null : Object_Authorization_Result::allowed( (string) $object->expected_revision(), (string) $object->expected_state(), $this->relation )
		);
	}
}

/**
 * G3-IMP-08-S01-F Context-aware Q23/Q24 and teacher UI contract.
 */
final class G3S01F_Private_Read implements Private_File_Read_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Initialize g3 s01 f private read dependencies and state.
	 *
	 * @param string $path Path.
	 * @param string $key Key.
	 */
	public function __construct( private string $path, private string $key ) {
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
		++$this->reads;
		if ( $storage_key !== $this->key || ! is_file( $this->path ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $this->path ) ) ) {
			throw new RuntimeException( 'Private PDF fixture mismatch.' );
		}
		return $this->path;
	}
}

/**
 * G3-IMP-08-S01-F Context-aware Q23/Q24 and teacher UI contract.
 */
final class G3S01F_Missing_Authorization_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g3 s01 f missing authorization query dependencies and state.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public function __construct( private array $job ) {
	}
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		unset( $actor_user_id, $job_id );
		$row = $this->job;
		unset( $row['authorization'] );
		return $row;
	}
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		unset( $actor_user_id, $artifact_id );
		return null;
	}
}

$snapshot    = Submission_Pdf_Request_Snapshot::freeze( $result, 'Asia/Seoul', $canonical );
$job_id      = g3s01_id( 70 );
$artifact_id = g3s01_id( 71 );
$file_id     = g3s01_id( 72 );
$expires     = '2026-08-24 10:00:00';
$storage_key = 'generated/pdf/submission-' . strtolower( $artifact_id ) . '.pdf';
$pdf_path    = tempnam( sys_get_temp_dir(), 'oj-ai-g3-s01-f-' );
if ( false === $pdf_path || false === file_put_contents( $pdf_path, "%PDF-1.7\nG3 S01-F fixture\n%%EOF", LOCK_EX ) ) {
	throw new RuntimeException( 'Submission PDF fixture could not be created.' );
}

try {
	$bytes = filesize( $pdf_path );
	$sha   = hash_file( 'sha256', $pdf_path );
	g3s01f_assert( is_int( $bytes ) && is_string( $sha ), 'Submission PDF fixture metadata is unavailable.' );
	$common       = array(
		'artifact_kind'            => 'submission_pdf',
		'source_type'              => 'submission_revision',
		'source_public_id'         => $submission_revision_id,
		'source_hash'              => $revision_hash,
		'print_profile_version'    => Build_Submission_Print_Projection::ARTIFACT_PROFILE_VERSION,
		'context_type'             => 'class',
		'context_id'               => 'class-21',
		'context_revision'         => 'class-r3',
		'request_contract_version' => Submission_Pdf_Request_Snapshot::VERSION,
		'request_snapshot_schema'  => Submission_Pdf_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => (string) Submission_Pdf_Request_Snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $snapshot->json(),
		'request_snapshot_hash'    => $snapshot->hash(),
		'request_hash'             => $snapshot->hash(),
	);
	$job_database = new G3S01F_Database(
		array_merge(
			$common,
			array(
				'public_id'              => $job_id,
				'state'                  => 'ready',
				'queued_at_utc'          => '2026-08-23 10:00:00',
				'started_at_utc'         => '2026-08-23 10:00:01',
				'finished_at_utc'        => '2026-08-23 10:00:02',
				'error_code'             => null,
				'result_file_public_id'  => $file_id,
				'pdf_artifact_public_id' => $artifact_id,
				'pdf_file_public_id'     => $file_id,
				'pdf_expires_at_utc'     => $expires,
				'file_public_id'         => $file_id,
				'owner_type'             => 'pdf_artifact',
				'owner_public_id'        => $artifact_id,
				'purpose'                => 'generated_pdf',
				'mime_type'              => 'application/pdf',
				'file_state'             => 'ready',
				'scan_state'             => 'clean',
				'file_expires_at_utc'    => $expires,
			)
		)
	);
	$job_query    = new WordPress_Export_Query( $job_database, $canonical );
	$job          = $job_query->find_job_for_actor( 42, $job_id );
	g3s01f_assert( is_array( $job ), 'Q23 did not return the submission PDF job.' );
	g3s01f_same(
		array(
			'id'       => 'class-21',
			'revision' => 'class-r3',
			'type'     => 'class',
		),
		$job['authorization']['context'],
		'Q23 Context authorization descriptor drifted.'
	);
	g3s01f_same( $submission_id, $job['authorization']['object']['id'], 'Q23 did not restore the submission root from the snapshot.' );
	g3s01f_same( 'context_staff', $job['authorization']['object']['relation'], 'Q23 relation must remain context_staff.' );
	g3s01f_assert( ! isset( $job['request_snapshot_json'] ), 'Q23 leaked the private request snapshot.' );
	g3s01f_assert(
		str_contains( $job_database->last_sql, 'oj_ai_export_jobs' )
			&& in_array( 'submission_revision', $job_database->last_values, true )
			&& in_array( 'submission_pdf', $job_database->last_values, true ),
		'Q23 SQL did not include submission PDF jobs.'
	);

	$policy     = new G3S01F_Policy();
	$public_job = ( new Read_Export_Job( $job_query, $policy, static fn (): string => '2026-08-23 11:00:00' ) )->execute( 42, $job_id, 'corr-g3-s01-f-job' );
	g3s01f_same( 'ready', $public_job['state'], 'Q23 ready state drifted.' );
	g3s01f_assert( ! isset( $public_job['authorization'] ), 'Q23 exposed its internal authorization descriptor.' );
	g3s01f_same( 'teacher_view', $policy->last_request?->object()?->action(), 'Q23 did not reauthorize teacher_view.' );

	$artifact_database = new G3S01F_Database(
		array_merge(
			$common,
			array(
				'job_state'                 => 'ready',
				'result_file_public_id'     => $file_id,
				'artifact_public_id'        => $artifact_id,
				'artifact_state'            => 'ready',
				'artifact_source_type'      => 'submission_revision',
				'artifact_source_public_id' => $submission_revision_id,
				'artifact_source_hash'      => $revision_hash,
				'expires_at_utc'            => $expires,
				'page_count'                => '2',
				'job_public_id'             => $job_id,
				'file_public_id'            => $file_id,
				'owner_type'                => 'pdf_artifact',
				'owner_public_id'           => $artifact_id,
				'purpose'                   => 'generated_pdf',
				'storage_key'               => $storage_key,
				'original_name'             => 'submission-' . strtolower( $submission_id ) . '.pdf',
				'mime_type'                 => 'application/pdf',
				'byte_size'                 => $bytes,
				'sha256'                    => $sha,
				'file_state'                => 'ready',
				'scan_state'                => 'clean',
				'file_expires_at_utc'       => $expires,
			)
		)
	);
	$artifact_query    = new WordPress_Export_Query( $artifact_database, $canonical );
	$files             = new G3S01F_Private_Read( $pdf_path, $storage_key );
	$download          = ( new Read_Export_Artifact( $artifact_query, $files, $policy, static fn (): string => '2026-08-23 11:00:00' ) )->execute( 42, $artifact_id, 'corr-g3-s01-f-download' );
	g3s01f_assert( $download instanceof Export_Download, 'Q24 did not return an export download.' );
	g3s01f_same( 'submission_pdf', $download->artifact_kind(), 'Q24 submission PDF profile drifted.' );
	g3s01f_same( 'application/pdf', $download->mime_type(), 'Q24 submission PDF MIME drifted.' );
	g3s01f_same( 1, $files->reads, 'Q24 must read private bytes once after authorization.' );
	g3s01f_assert( str_contains( $artifact_database->last_sql, 'a.source_type = j.source_type' ), 'Q24 SQL did not bind artifact and job sources.' );

	$policy->allowed = false;
	g3s01f_failure(
		static fn () => ( new Read_Export_Artifact( $artifact_query, $files, $policy ) )->execute( 42, $artifact_id, 'corr-g3-s01-f-denied' ),
		Export_Failure::ACCESS_DENIED,
		'Q24 must deny a revoked teacher before reading private bytes.'
	);
	g3s01f_same( 1, $files->reads, 'Revoked Q24 access read private bytes.' );

	$policy->allowed          = true;
	$policy->context_revision = 'class-r4';
	g3s01f_failure(
		static fn () => ( new Read_Export_Artifact( $artifact_query, $files, $policy ) )->execute( 42, $artifact_id, 'corr-g3-s01-f-context' ),
		Export_Failure::ACCESS_DENIED,
		'Q24 must reject current Context revision drift.'
	);
	g3s01f_same( 1, $files->reads, 'Context-drifted Q24 access read private bytes.' );

	$policy->context_revision = 'class-r3';
	g3s01f_failure(
		static fn () => ( new Read_Export_Job( new G3S01F_Missing_Authorization_Query( $job ), $policy ) )->execute( 42, $job_id, 'corr-g3-s01-f-missing' ),
		Export_Failure::ACCESS_DENIED,
		'Submission Q23 must fail closed without a restored authorization descriptor.'
	);

	$drifted     = $artifact_database;
	$invalid_row = array_merge(
		$common,
		array(
			'job_state'                 => 'ready',
			'result_file_public_id'     => $file_id,
			'artifact_public_id'        => $artifact_id,
			'artifact_state'            => 'ready',
			'artifact_source_type'      => 'submission_revision',
			'artifact_source_public_id' => $submission_revision_id,
			'artifact_source_hash'      => $revision_hash,
			'expires_at_utc'            => $expires,
			'page_count'                => '2',
			'job_public_id'             => $job_id,
			'file_public_id'            => $file_id,
			'owner_type'                => 'pdf_artifact',
			'owner_public_id'           => $artifact_id,
			'purpose'                   => 'generated_pdf',
			'storage_key'               => $storage_key,
			'original_name'             => 'submission.pdf',
			'mime_type'                 => 'application/pdf',
			'byte_size'                 => $bytes,
			'sha256'                    => $sha,
			'file_state'                => 'ready',
			'scan_state'                => 'clean',
			'file_expires_at_utc'       => $expires,
			'context_revision'          => 'class-r4',
		)
	);
	g3s01f_failure(
		static fn () => ( new WordPress_Export_Query( new G3S01F_Database( $invalid_row ), $canonical ) )->find_artifact_for_actor( 42, $artifact_id ),
		Export_Failure::STORED_DATA_INVALID,
		'Q24 must reject database Context evidence that drifts from the immutable snapshot.'
	);

	$runtime_files = array(
		$addon_root . '/src/Reporting/Export/Application/Export_Access_Guard.php',
		$addon_root . '/src/Reporting/Export/Application/Read_Export_Job.php',
		$addon_root . '/src/Reporting/Export/Application/Read_Export_Artifact.php',
		$addon_root . '/src/Reporting/Export/Application/Export_Download.php',
		$addon_root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$addon_root . '/resources/js/assignment-workbench.js',
		$addon_root . '/src/Core/Composition_Root.php',
	);
	foreach ( $runtime_files as $runtime_file ) {
		$source_code = (string) file_get_contents( $runtime_file );
		g3s01f_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'S01-F used a forbidden table prefix: ' . $runtime_file );
		g3s01f_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S01-F depends on OJ Platform internals: ' . $runtime_file );
	}
	$query_source = (string) file_get_contents( $runtime_files[4] );
	foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_pdf_artifacts'", 'Submission_Pdf_Request_Snapshot::restore', "'submission_pdf'", "'context_staff'" ) as $marker ) {
		g3s01f_assert( str_contains( $query_source, $marker ), 'S01-F query marker is missing: ' . $marker );
	}
	$ui_source = (string) file_get_contents( $runtime_files[5] );
	foreach ( array( 'expected_attachment_set_hash', 'expected_submission_lock_version', '/commands/export-pdf', '/export-jobs/', '/artifacts/', "profile: 'teacher_submission'", "'PDF 준비 중'", "'PDF 준비됨'", "'PDF 생성 실패'" ) as $marker ) {
		g3s01f_assert( str_contains( $ui_source, $marker ), 'S01-F teacher UI marker is missing: ' . $marker );
	}
	g3s01f_assert( str_contains( (string) file_get_contents( $runtime_files[6] ), 'new WordPress_Export_Query( null, $canonical )' ), 'S01-F query is not wired with canonical JSON.' );
} finally {
	if ( is_string( $pdf_path ) && is_file( $pdf_path ) ) {
		@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
}

echo "G3-IMP-08-S01-F passed: Context-aware Q23/Q24, snapshot-root authorization, private submission PDF download, revocation, UI polling, and no-prefix isolation.\n";
