<?php
/**
 * G3-IMP-08-S01-E submission PDF worker, lease, artifact, and compensation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

require __DIR__ . '/submission-print-projection-g3-imp-08-s01.php';

use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Run_Next_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Run_Submission_Pdf_Result;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G3s01e assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01e_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s01e same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01e_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3-IMP-08-S01-E submission PDF worker, lease, artifact, and compensation contract.
 */
final class G3S01E_Work_Repository implements Submission_Pdf_Work_Repository_Port_Interface {
	/**
	 * Ready.
	 *
	 * @var array|null
	 */
	public ?array $ready = null;
	/**
	 * Failure.
	 *
	 * @var array|null
	 */
	public ?array $failure = null;
	/**
	 * Ready commit.
	 *
	 * @var bool
	 */
	public bool $ready_commit = true;
	/**
	 * Throw on complete.
	 *
	 * @var bool
	 */
	public bool $throw_on_complete = false;
	/**
	 * Context id.
	 *
	 * @var string
	 */
	public string $context_id = 'class-21';
	/**
	 * Claimed.
	 *
	 * @var bool
	 */
	private bool $claimed = false;

	/**
	 * Initialize g3 s01 e work repository dependencies and state.
	 *
	 * @param Submission_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @param string                          $job_id Job id.
	 * @param int                             $actor_user_id Authenticated actor from the access context.
	 */
	public function __construct(
		private Submission_Pdf_Request_Snapshot $snapshot,
		private string $job_id,
		private int $actor_user_id = 42
	) {
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Pdf_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Pdf_Work_Item {
		unset( $now_utc, $lease_expires_at_utc );
		if ( $this->claimed ) {
			return null;
		}
		$this->claimed = true;
		$context       = $this->snapshot->context();
		return new Submission_Pdf_Work_Item(
			1,
			$this->job_id,
			1,
			$this->actor_user_id,
			(string) $context['type'],
			$this->context_id,
			(string) $context['revision'],
			$this->snapshot->source_id(),
			$this->snapshot->source_hash(),
			$this->snapshot->json(),
			$this->snapshot->hash(),
			$this->snapshot->hash(),
			$this->snapshot->profile_version(),
			'2026-08-23 10:00:00',
			$lease_token
		);
	}

	/**
	 * Complete ready.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param array                    $file File.
	 * @param array                    $artifact Artifact.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_ready( Submission_Pdf_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		if ( $this->throw_on_complete ) {
			throw new RuntimeException( 'Injected ready commit failure.' );
		}
		$this->ready = array(
			'item'            => $item,
			'file'            => $file,
			'artifact'        => $artifact,
			'finished_at_utc' => $finished_at_utc,
		);
		return $this->ready_commit;
	}

	/**
	 * Complete failure.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param string                   $error_code Error code.
	 * @param string                   $audit_public_id Audit public id.
	 * @param string                   $outbox_public_id Outbox public id.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure( Submission_Pdf_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
		$this->failure = compact( 'item', 'error_code', 'audit_public_id', 'outbox_public_id', 'correlation_id', 'finished_at_utc' );
		return true;
	}
}

/**
 * G3-IMP-08-S01-E submission PDF worker, lease, artifact, and compensation contract.
 */
final class G3S01E_Renderer implements Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Render g3 s01 e renderer.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result {
		++$this->calls;
		g3s01e_assert( str_contains( $html, '김하늘' ) && ! str_contains( $html, '<script>' ), 'Worker serializer emitted unsafe or incomplete HTML.' );
		return new Worksheet_Pdf_Render_Result( "%PDF-1.7\nG3 S01-E fixture\n%%EOF", 2, 'test-renderer-1' );
	}
}

/**
 * G3-IMP-08-S01-E submission PDF worker, lease, artifact, and compensation contract.
 */
final class G3S01E_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Puts.
	 *
	 * @var int
	 */
	public int $puts = 0;
	/**
	 * Stored.
	 *
	 * @var array
	 */
	public array $stored = array();
	/**
	 * Deleted.
	 *
	 * @var array
	 */
	public array $deleted = array();
	/**
	 * Put success.
	 *
	 * @var bool
	 */
	public bool $put_success = true;

	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		++$this->puts;
		if ( ! $this->put_success ) {
			return false;
		}
		$bytes = file_get_contents( $source_path );
		g3s01e_assert( is_string( $bytes ) && hash_equals( $sha256, hash( 'sha256', $bytes ) ), 'Private PDF hash verification failed.' );
		$this->stored[ $storage_key ] = $bytes;
		return true;
	}

	/**
	 * Delete g3 s01 e private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		$this->deleted[] = $storage_key;
		unset( $this->stored[ $storage_key ] );
	}
}

/**
 * G3-IMP-08-S01-E submission PDF worker, lease, artifact, and compensation contract.
 */
final class G3S01E_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Deny on call.
	 *
	 * @var int
	 */
	public int $deny_on_call = 0;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		if ( $this->deny_on_call === $this->calls ) {
			return Access_Policy_Decision::denied( 403, 'test_access_revoked', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'class-r3', 'educator', $request->correlation_id() ),
			Object_Authorization_Result::allowed( '2', 'submitted', 'context_staff' )
		);
	}
}

/**
 * G3s01e worker.
 *
 * @param Submission_Pdf_Request_Snapshot   $snapshot Snapshot.
 * @param Build_Submission_Print_Projection $builder Builder.
 * @return array{0:Run_Next_Submission_Pdf,1:G3S01E_Work_Repository,2:G3S01E_Renderer,3:G3S01E_Private_Files,4:G3S01E_Policy}
 */
function g3s01e_worker( Submission_Pdf_Request_Snapshot $snapshot, Build_Submission_Print_Projection $builder ): array {
	$repository = new G3S01E_Work_Repository( $snapshot, g3s01_id( 150 ) );
	$renderer   = new G3S01E_Renderer();
	$files      = new G3S01E_Private_Files();
	$policy     = new G3S01E_Policy();
	$worker     = new Run_Next_Submission_Pdf(
		$repository,
		$builder,
		new Submission_Print_Html_Serializer( new Canonical_Json() ),
		$renderer,
		$files,
		$policy,
		new Public_Id_Generator(),
		new Canonical_Json(),
		static fn (): string => '2026-08-23 10:00:05',
		static fn (): string => '12345678-1234-4234-8234-123456789abc'
	);
	return array( $worker, $repository, $renderer, $files, $policy );
}

$snapshot = Submission_Pdf_Request_Snapshot::freeze( $result, 'Asia/Seoul', $canonical );

[$worker, $work_repository, $worker_renderer, $worker_files, $worker_policy] = g3s01e_worker( $snapshot, $builder );
$ready = $worker->run_once();
g3s01e_same( Run_Submission_Pdf_Result::READY, $ready->status(), 'Valid C49 job must become ready.' );
g3s01e_same( 2, $worker_policy->calls, 'Worker must authorize twice.' );
g3s01e_same( 1, $worker_renderer->calls, 'Worker must render once.' );
g3s01e_same( 1, $worker_files->puts, 'Worker must persist one private file.' );
g3s01e_same( array(), $worker_files->deleted, 'Successful completion must retain its private file.' );
g3s01e_assert( is_array( $work_repository->ready ) && null === $work_repository->failure, 'Ready completion was not committed cleanly.' );
g3s01e_same( $submission_id, $work_repository->ready['artifact']['submission_public_id'], 'Artifact lost its submission root identity.' );
g3s01e_same( 'application/pdf', str_ends_with( $work_repository->ready['file']['original_name'], '.pdf' ) ? 'application/pdf' : '', 'Artifact filename is not a PDF.' );
g3s01e_same( Run_Submission_Pdf_Result::IDLE, $worker->run_once()->status(), 'Empty queue must return idle.' );

[$drift_worker, $drift_repository, $drift_renderer] = g3s01e_worker( $snapshot, $builder );
$submission_source->source['lock_version']          = 3;
$drift                                     = $drift_worker->run_once();
$submission_source->source['lock_version'] = 2;
g3s01e_same( Run_Submission_Pdf_Result::FAILED, $drift->status(), 'Changed official submission lock must fail.' );
g3s01e_same( Submission_Pdf_Failure::SOURCE_STALE, $drift_repository->failure['error_code'] ?? null, 'Source drift failure code changed.' );
g3s01e_same( 0, $drift_renderer->calls, 'Stale source must fail before rendering.' );

[$revoked_worker, $revoked_repository, $revoked_renderer, $revoked_files, $revoked_policy] = g3s01e_worker( $snapshot, $builder );
$revoked_policy->deny_on_call = 2;
$revoked                      = $revoked_worker->run_once();
g3s01e_same( Run_Submission_Pdf_Result::FAILED, $revoked->status(), 'Revoked access must fail.' );
g3s01e_same( Submission_Pdf_Failure::ACCESS_DENIED, $revoked_repository->failure['error_code'] ?? null, 'Revoked access failure code changed.' );
g3s01e_same( 1, $revoked_renderer->calls, 'Second authorization test must render before revocation.' );
g3s01e_same( 0, $revoked_files->puts, 'Revoked access persisted private bytes.' );

[$context_worker, $context_repository, , $context_files] = g3s01e_worker( $snapshot, $builder );
$context_repository->context_id                          = 'class-99';
$context_result = $context_worker->run_once();
g3s01e_same( Run_Submission_Pdf_Result::FAILED, $context_result->status(), 'Tampered job Context must fail.' );
g3s01e_same( Submission_Pdf_Failure::STORED_DATA_INVALID, $context_repository->failure['error_code'] ?? null, 'Context corruption failure code changed.' );
g3s01e_same( 0, $context_files->puts, 'Context-corrupt job persisted bytes.' );

[$cas_worker, $cas_repository, , $cas_files] = g3s01e_worker( $snapshot, $builder );
$cas_repository->ready_commit                = false;
$cas = $cas_worker->run_once();
g3s01e_same( Run_Submission_Pdf_Result::STALE, $cas->status(), 'Lost ready CAS must return stale.' );
g3s01e_same( 1, count( $cas_files->deleted ), 'Lost ready CAS must compensate the private file.' );
g3s01e_same( array(), $cas_files->stored, 'Compensation left private bytes behind.' );

// Verify ownership-sensitive cleanup behavior, independent of the condition's spelling.
[$put_worker, $put_repository, , $put_files] = g3s01e_worker( $snapshot, $builder );
$put_files->put_success                      = false;
$put_files->stored                           = array( 'existing-file' => 'must remain' );
g3s01e_same( Run_Submission_Pdf_Result::FAILED, $put_worker->run_once()->status(), 'Failed private storage must fail the job.' );
g3s01e_same( Submission_Pdf_Failure::STORAGE_UNAVAILABLE, $put_repository->failure['error_code'] ?? null, 'Failed private storage code changed.' );
g3s01e_same( array(), $put_files->deleted, 'A worker that did not create a file must not delete one.' );
g3s01e_same( array( 'existing-file' => 'must remain' ), $put_files->stored, 'Failed storage changed an existing file.' );

[$throw_worker, $throw_repository, , $throw_files] = g3s01e_worker( $snapshot, $builder );
$throw_repository->throw_on_complete               = true;
g3s01e_same( Run_Submission_Pdf_Result::FAILED, $throw_worker->run_once()->status(), 'Thrown commit failure must fail the job.' );
g3s01e_same( 1, count( $throw_files->deleted ), 'Thrown commit failure must compensate its new file exactly once.' );
g3s01e_same( array(), $throw_files->stored, 'Thrown commit failure leaked private bytes.' );

$runtime_files = array(
	$addon_root . '/src/Reporting/SubmissionPdf/Application/Run_Next_Submission_Pdf.php',
	$addon_root . '/src/Reporting/SubmissionPdf/Domain/Submission_Pdf_Work_Item.php',
	$addon_root . '/src/Reporting/SubmissionPdf/Infrastructure/WordPress_Submission_Pdf_Repository.php',
	$addon_root . '/src/Reporting/SubmissionPdf/Infrastructure/Submission_Pdf_Worker_Module.php',
	$addon_root . '/src/Core/Composition_Root.php',
	$addon_root . '/src/Core/Deactivation.php',
);
foreach ( $runtime_files as $file ) {
	$source_code = file_get_contents( $file );
	g3s01e_assert( is_string( $source_code ), 'S01-E runtime source is unreadable: ' . $file );
	g3s01e_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'Forbidden table prefix found: ' . $file );
	g3s01e_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'Platform internal dependency found: ' . $file );
}
$worker_source = (string) file_get_contents( $runtime_files[0] );
g3s01e_same( 2, substr_count( $worker_source, '$this->authorized(' ), 'Worker must reauthorize immediately before persistence.' );
g3s01e_same( 2, substr_count( $worker_source, '$this->assert_same_snapshot(' ), 'Worker must reread the official source twice.' );
foreach ( array( 'LEASE_SECONDS = 300', 'MAX_PDF_BYTES = 104_857_600', 'MAX_PDF_PAGES = 500' ) as $marker ) {
	g3s01e_assert( Ojai_Php_Source_Contract::contains( $worker_source, $marker ), 'Worker safety marker is missing: ' . $marker );
}
$repository_source = (string) file_get_contents( $runtime_files[2] );
foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_pdf_artifacts'", 'lease_expires_at_utc IS NOT NULL', 'LIMIT 1 FOR UPDATE', "'submission_pdf'", "'submission_revision'" ) as $marker ) {
	g3s01e_assert( str_contains( $repository_source, $marker ), 'Repository lease/artifact marker is missing: ' . $marker );
}
$composition_source = (string) file_get_contents( $runtime_files[4] );
g3s01e_assert( str_contains( $composition_source, 'new Submission_Pdf_Worker_Module(' ) && str_contains( $composition_source, '$this->modules->add( $submission_pdf_worker );' ), 'Submission PDF worker is not registered.' );
$deactivation_source = (string) file_get_contents( $runtime_files[5] );
g3s01e_assert( str_contains( $deactivation_source, 'Submission_Pdf_Worker_Module::clear();' ), 'Submission PDF hooks are not cleared on deactivation.' );

echo "G3-IMP-08-S01-E passed: exact Context/source rechecks, double authorization, Dompdf worker boundary, private artifact, lease CAS, and compensation cleanup.\n";
