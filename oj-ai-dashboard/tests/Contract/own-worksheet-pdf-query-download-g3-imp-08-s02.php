<?php
/**
 * G3-IMP-08-S02 learner Q23/Q24 worksheet PDF authorization contract.
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
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;

require __DIR__ . '/worksheet-print-projection-g2-imp-07-s01.php';

/**
 * G3s02b assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02b_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s02b same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02b_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3s02b failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02b_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Export_Failure $failure ) {
		g3s02b_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G3-IMP-08-S02 learner Q23/Q24 worksheet PDF authorization contract.
 */
final class G3S02B_Database {
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
	 * Initialize g3 s02 b database dependencies and state.
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
 * G3-IMP-08-S02 learner Q23/Q24 worksheet PDF authorization contract.
 */
final class G3S02B_Policy implements Access_Policy_Port_Interface {
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
	public string $relation = 'self_target';
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
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( (string) $context?->type(), (string) $context?->id(), 'active', $this->context_revision, 'learner' ),
			Object_Authorization_Result::allowed( '4', 'open', $this->relation )
		);
	}
}

/**
 * G3-IMP-08-S02 learner Q23/Q24 worksheet PDF authorization contract.
 */
final class G3S02B_Private_Read implements Private_File_Read_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Initialize g3 s02 b private read dependencies and state.
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
			throw new RuntimeException( 'C51 private PDF fixture mismatch.' );
		}
		return $this->path;
	}
}

/**
 * G3-IMP-08-S02 learner Q23/Q24 worksheet PDF authorization contract.
 */
final class G3S02B_Missing_Authorization_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g3 s02 b missing authorization query dependencies and state.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public function __construct( private array $row ) {
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
		$row = $this->row;
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

$assignment_id = g2s01_id( 93 );
$target_id     = g2s01_id( 94 );
$projection    = $builder->build_exact_publication( $publication_id, $publication_hash, 'student_blank' );
$snapshot      = Worksheet_Pdf_Request_Snapshot::freeze_own(
	$projection,
	array(
		'assignment_id'            => $assignment_id,
		'assignment_lock_version'  => 4,
		'assignment_state'         => 'open',
		'binding_context_revision' => 'class-r3',
		'context_id'               => 'class-21',
		'context_type'             => 'class',
		'publication_hash'         => $publication_hash,
		'publication_id'           => $publication_id,
		'target_id'                => $target_id,
		'target_state'             => 'active',
	),
	array(
		'id'       => 'class-21',
		'revision' => 'class-r3',
		'type'     => 'class',
	),
	'Asia/Seoul',
	$canonical
);
$job_id        = g2s01_id( 95 );
$artifact_id   = g2s01_id( 96 );
$file_id       = g2s01_id( 97 );
$expires       = '2026-08-24 10:00:00';
$storage_key   = 'generated/pdf/' . strtolower( $artifact_id ) . '.pdf';
$pdf_path      = tempnam( sys_get_temp_dir(), 'oj-ai-g3-s02-b-' );
if ( false === $pdf_path || false === file_put_contents( $pdf_path, "%PDF-1.7\nC51 Q24 fixture\n%%EOF", LOCK_EX ) ) {
	throw new RuntimeException( 'C51 Q24 fixture could not be created.' );
}

try {
	$bytes = filesize( $pdf_path );
	$sha   = hash_file( 'sha256', $pdf_path );
	g3s02b_assert( is_int( $bytes ) && is_string( $sha ), 'C51 Q24 fixture metadata is unavailable.' );
	$common       = array(
		'artifact_kind'            => 'worksheet_pdf',
		'source_type'              => 'worksheet_publication',
		'source_public_id'         => $publication_id,
		'source_hash'              => $publication_hash,
		'print_profile_version'    => $snapshot->profile_version(),
		'context_type'             => 'class',
		'context_id'               => 'class-21',
		'context_revision'         => 'class-r3',
		'request_contract_version' => Worksheet_Pdf_Request_Snapshot::OWN_VERSION,
		'request_snapshot_schema'  => Worksheet_Pdf_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => (string) Worksheet_Pdf_Request_Snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $snapshot->json(),
		'request_snapshot_hash'    => $snapshot->hash(),
		'request_hash'             => $snapshot->hash(),
	);
	$job_database = new G3S02B_Database(
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
	g3s02b_assert( is_array( $job ), 'Q23 did not return the learner worksheet PDF job.' );
	g3s02b_same(
		array(
			'id'       => 'class-21',
			'revision' => 'class-r3',
			'type'     => 'class',
		),
		$job['authorization']['context'],
		'Q23 learner Context descriptor drifted.'
	);
	g3s02b_same( $assignment_id, $job['authorization']['object']['id'], 'Q23 did not restore the assignment from C51 snapshot.' );
	g3s02b_same( 'self_target', $job['authorization']['object']['relation'], 'Q23 learner relation drifted.' );
	g3s02b_same( 'export_pdf', $job['authorization']['object']['action'], 'Q23 learner object action drifted.' );
	g3s02b_assert( ! isset( $job['request_snapshot_json'] ), 'Q23 leaked the private C51 request snapshot.' );
	g3s02b_assert( in_array( 'worksheet_publication', $job_database->last_values, true ), 'Q23 SQL omitted publication worksheet PDFs.' );

	$policy     = new G3S02B_Policy();
	$public_job = ( new Read_Export_Job( $job_query, $policy, static fn (): string => '2026-08-23 11:00:00' ) )->execute( 42, $job_id, 'corr-g3-s02-b-job' );
	g3s02b_same( 'ready', $public_job['state'], 'C51 Q23 ready state drifted.' );
	g3s02b_assert( ! isset( $public_job['authorization'] ), 'C51 Q23 exposed internal authorization evidence.' );
	g3s02b_same( Access_Policy_Request::SHELL_PUBLIC, $policy->last_request?->shell(), 'C51 Q23 must use the public shell.' );
	g3s02b_same( Context_Scope::OPERATION_PARTICIPATE, $policy->last_request?->context()?->operation(), 'C51 Q23 must use participate Context.' );
	g3s02b_same( 42, $policy->last_request?->context()?->learner_user_id(), 'C51 Q23 must authorize the current learner.' );
	g3s02b_same( 'export_pdf', $policy->last_request?->object()?->action(), 'C51 Q23 object action drifted.' );
	g3s02b_assert( in_array( Addon_Capabilities::PARTICIPATE, $policy->last_request?->required_capabilities() ?? array(), true ), 'C51 Q23 lost participate capability.' );

	$artifact_database = new G3S02B_Database(
		array_merge(
			$common,
			array(
				'job_state'                 => 'ready',
				'result_file_public_id'     => $file_id,
				'artifact_public_id'        => $artifact_id,
				'artifact_state'            => 'ready',
				'artifact_source_type'      => 'worksheet_publication',
				'artifact_source_public_id' => $publication_id,
				'artifact_source_hash'      => $publication_hash,
				'expires_at_utc'            => $expires,
				'page_count'                => '1',
				'job_public_id'             => $job_id,
				'file_public_id'            => $file_id,
				'owner_type'                => 'pdf_artifact',
				'owner_public_id'           => $artifact_id,
				'purpose'                   => 'generated_pdf',
				'storage_key'               => $storage_key,
				'original_name'             => 'worksheet-' . strtolower( $assignment_id ) . '.pdf',
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
	$files             = new G3S02B_Private_Read( $pdf_path, $storage_key );
	$download          = ( new Read_Export_Artifact( $artifact_query, $files, $policy, static fn (): string => '2026-08-23 11:00:00' ) )->execute( 42, $artifact_id, 'corr-g3-s02-b-download' );
	g3s02b_assert( $download instanceof Export_Download, 'C51 Q24 did not return a download.' );
	g3s02b_same( 'worksheet_pdf', $download->artifact_kind(), 'C51 Q24 artifact kind drifted.' );
	g3s02b_same( 'application/pdf', $download->mime_type(), 'C51 Q24 MIME drifted.' );
	g3s02b_same( 1, $files->reads, 'C51 Q24 must read private bytes once after authorization.' );
	g3s02b_assert( in_array( 'worksheet_publication', $artifact_database->last_values, true ), 'Q24 SQL omitted publication worksheet PDFs.' );

	$policy->allowed = false;
	g3s02b_failure(
		static fn () => ( new Read_Export_Artifact( $artifact_query, $files, $policy ) )->execute( 42, $artifact_id, 'corr-g3-s02-b-denied' ),
		Export_Failure::ACCESS_DENIED,
		'C51 Q24 must deny revoked access before reading bytes.'
	);
	g3s02b_same( 1, $files->reads, 'Revoked C51 Q24 read private bytes.' );

	$policy->allowed          = true;
	$policy->context_revision = 'class-r4';
	g3s02b_failure(
		static fn () => ( new Read_Export_Artifact( $artifact_query, $files, $policy ) )->execute( 42, $artifact_id, 'corr-g3-s02-b-context' ),
		Export_Failure::ACCESS_DENIED,
		'C51 Q24 must reject current Context revision drift.'
	);
	g3s02b_same( 1, $files->reads, 'Context-drifted C51 Q24 read private bytes.' );

	$policy->context_revision = 'class-r3';
	$policy->relation         = 'context_staff';
	g3s02b_failure(
		static fn () => ( new Read_Export_Job( $job_query, $policy ) )->execute( 42, $job_id, 'corr-g3-s02-b-relation' ),
		Export_Failure::ACCESS_DENIED,
		'C51 Q23 must reject a non-self object relation.'
	);

	$policy->relation = 'self_target';
	g3s02b_failure(
		static fn () => ( new Read_Export_Job( new G3S02B_Missing_Authorization_Query( $job ), $policy ) )->execute( 42, $job_id, 'corr-g3-s02-b-missing' ),
		Export_Failure::ACCESS_DENIED,
		'C51 Q23 must fail closed when the restored authorization descriptor is missing.'
	);

	$invalid_row = array_merge(
		$common,
		array(
			'job_state'                 => 'ready',
			'result_file_public_id'     => $file_id,
			'artifact_public_id'        => $artifact_id,
			'artifact_state'            => 'ready',
			'artifact_source_type'      => 'worksheet_publication',
			'artifact_source_public_id' => $publication_id,
			'artifact_source_hash'      => $publication_hash,
			'expires_at_utc'            => $expires,
			'page_count'                => '1',
			'job_public_id'             => $job_id,
			'file_public_id'            => $file_id,
			'owner_type'                => 'pdf_artifact',
			'owner_public_id'           => $artifact_id,
			'purpose'                   => 'generated_pdf',
			'storage_key'               => $storage_key,
			'original_name'             => 'worksheet.pdf',
			'mime_type'                 => 'application/pdf',
			'byte_size'                 => $bytes,
			'sha256'                    => $sha,
			'file_state'                => 'ready',
			'scan_state'                => 'clean',
			'file_expires_at_utc'       => $expires,
			'context_revision'          => 'class-r4',
		)
	);
	g3s02b_failure(
		static fn () => ( new WordPress_Export_Query( new G3S02B_Database( $invalid_row ), $canonical ) )->find_artifact_for_actor( 42, $artifact_id ),
		Export_Failure::STORED_DATA_INVALID,
		'C51 Q24 must reject database Context drift from the immutable snapshot.'
	);

	$runtime_files = array(
		$addon_root . '/src/Reporting/Export/Application/Export_Access_Guard.php',
		$addon_root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$addon_root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
		$addon_root . '/resources/js/student-assignments.js',
		$addon_root . '/src/Core/Composition_Root.php',
	);
	foreach ( $runtime_files as $runtime_file ) {
		$source_code = (string) file_get_contents( $runtime_file );
		g3s02b_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'C51 Q23/Q24 used a forbidden table prefix: ' . $runtime_file );
		g3s02b_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C51 Q23/Q24 depends on OJ Platform internals: ' . $runtime_file );
	}
	$query_source = (string) file_get_contents( $runtime_files[1] );
	foreach ( array( "'worksheet_publication'", 'Worksheet_Pdf_Request_Snapshot::restore', "'self_target'", "'export_pdf'" ) as $marker ) {
		g3s02b_assert( str_contains( $query_source, $marker ), 'C51 export query marker is missing: ' . $marker );
	}
	$guard_source = (string) file_get_contents( $runtime_files[0] );
	g3s02b_assert( str_contains( $guard_source, "'worksheet_revision' !== \$source_type" ), 'Owner fallback must reject a descriptor-free publication job.' );
	$ui_source = (string) file_get_contents( $runtime_files[3] );
	foreach ( array( '/commands/export-pdf', '/export-jobs/', '/artifacts/', "profile: 'student_blank'", "'PDF 만들기'", "'PDF 다운로드'", 'X-WP-Nonce' ) as $marker ) {
		g3s02b_assert( str_contains( $ui_source, $marker ), 'C51 student UI marker is missing: ' . $marker );
	}
} finally {
	if ( is_string( $pdf_path ) && is_file( $pdf_path ) ) {
		@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
}

echo "G3-IMP-08-S02-B passed: self-authorized Q23/Q24, publication snapshot restoration, private download, revocation, Context drift, fail-closed descriptor, and learner UI polling.\n";
