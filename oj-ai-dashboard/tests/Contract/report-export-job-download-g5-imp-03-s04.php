<?php
/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Domain\Export_Work_Item;
use OJAI\Dashboard\Reporting\Export\Domain\Generated_Export_File;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Export\Port\Export_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Application\Run_Next_Report_Export;
use OJAI\Dashboard\Reporting\ReportExport\Application\Run_Report_Export_Result;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\ReportExport\Infrastructure\Report_Export_Artifact_Writer;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Writer_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Run_Next_Submission_Bundle;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Run_Submission_Bundle_Result;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Infrastructure\Deterministic_Submission_Bundle_Writer;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Attachment_Read_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

ob_start();
require __DIR__ . '/report-json-package-enqueue-g5-imp-03-s03.php';
ob_end_clean();
$g5s04_report_canonical     = $json_canonical;
$g5s04_report_snapshot      = $json_snapshot;
$g5s04_report_reader        = $json_reader;
$g5s04_report_manifest_json = $manifest->source_manifest_json();

ob_start();
require __DIR__ . '/submission-print-projection-g3-imp-08-s01.php';
ob_end_clean();
$g5s04_submission_canonical  = $canonical;
$g5s04_submission_projection = $builder;

/**
 * G5s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s04_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5s04 export failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s04_export_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( Export_Failure $failure ) {
		g5s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G5s04 report snapshot.
 *
 * @param Report_Export_Request_Snapshot $base Base.
 * @param string                         $kind Kind.
 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
 * @param bool|string                    $formula_title Formula title.
 * @return Report_Export_Request_Snapshot
 */
function g5s04_report_snapshot(
	Report_Export_Request_Snapshot $base,
	string $kind,
	Canonical_Json $canonical,
	bool|string $formula_title = false
): Report_Export_Request_Snapshot {
	$value      = $base->value();
	$projection = $value['export']['projection'];
	if ( false !== $formula_title && isset( $projection['items'][0]['worksheet'] ) ) {
		$projection['items'][0]['worksheet']['title'] = is_string( $formula_title ) ? $formula_title : '=SUM(1,1)';
	}
	$projection_hash = hash( 'sha256', $canonical->encode( $projection ) );
	$identity        = $canonical->encode(
		array(
			'schema_id'            => 'oj_ai.report_snapshot_identity',
			'schema_version'       => '1.0.0',
			'snapshot_id'          => $base->source_id(),
			'filter_hash'          => $base->filter_hash(),
			'source_manifest_hash' => $base->source_manifest_hash(),
			'projection_hash'      => $projection_hash,
		)
	);
	$report          = array(
		'id'                   => $base->source_id(),
		'snapshot_hash'        => hash( 'sha256', $identity ),
		'state'                => 'ready',
		'generated_at_utc'     => $value['source']['generated_at_utc'],
		'row_count'            => count( $projection['items'] ),
		'filter_hash'          => $base->filter_hash(),
		'source_manifest_hash' => $base->source_manifest_hash(),
		'projection_hash'      => $projection_hash,
		'filter'               => $value['export']['filter'],
		'projection'           => $projection,
	);
	return Report_Export_Request_Snapshot::freeze( $report, $base->context(), $kind, 'ko-KR', $canonical );
}

/**
 * G5s04 zip entries.
 *
 * @param string $path Path.
 * @return array<string,string>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s04_zip_entries( string $path ): array {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		throw new RuntimeException( 'ZIP fixture cannot be opened.' );
	}
	$result = array();
	try {
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			$name = $zip->getNameIndex( $index );
			if ( ! is_string( $name ) ) {
				throw new RuntimeException( 'ZIP entry name is invalid.' );
			}
			$bytes = $zip->getFromIndex( $index );
			if ( ! is_string( $bytes ) ) {
				throw new RuntimeException( 'ZIP entry cannot be read.' );
			}
			$result[ $name ] = $bytes;
		}
	} finally {
		$zip->close();
	}
	ksort( $result, SORT_STRING );
	return $result;
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Pdf_Renderer implements Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Html.
	 *
	 * @var array<int,string>
	 */
	public array $html = array();
	/**
	 * Render g5 s04 pdf renderer.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result {
		$this->html[] = $html;
		return new Worksheet_Pdf_Render_Result( "%PDF-1.7\nG5 S04 deterministic Korean PDF\n%%EOF", 2, 'g5-s04-pdf-1.0.0' );
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_No_Attachments implements Submission_Bundle_Attachment_Read_Port_Interface {
	/**
	 * Readable path.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param array  $attachment Attachment.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $submission_id, array $attachment ): string {
		unset( $submission_id, $attachment );
		throw new RuntimeException( 'The no-originals bundle read an attachment.' );
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Private_Files implements Private_File_Store_Port_Interface, Private_File_Read_Port_Interface {
	/**
	 * Paths.
	 *
	 * @var array<string,string>
	 */
	public array $paths = array();
	/**
	 * Deletes.
	 *
	 * @var int
	 */
	public int $deletes = 0;

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
		if ( isset( $this->paths[ $storage_key ] ) ) {
			return false;
		}
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $source_path ) ) ) {
			throw new RuntimeException( 'Private source hash drifted.' );
		}
		$target = tempnam( sys_get_temp_dir(), 'oj-ai-g5-s04-store-' );
		if ( false === $target || ! copy( $source_path, $target ) ) {
			throw new RuntimeException( 'Private fixture copy failed.' );
		}
		$this->paths[ $storage_key ] = $target;
		return true;
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
		$path = $this->paths[ $storage_key ] ?? null;
		if ( ! is_string( $path ) || ! is_file( $path ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Private fixture is unavailable.' );
		}
		return $path;
	}

	/**
	 * Delete g5 s04 private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		$path = $this->paths[ $storage_key ] ?? null;
		if ( is_string( $path ) && is_file( $path ) ) {
			unlink( $path );
		}
		unset( $this->paths[ $storage_key ] );
		++$this->deletes;
	}

	/**
	 * Cleanup.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		foreach ( array_keys( $this->paths ) as $key ) {
			$this->delete( $key );
		}
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Work_Repository implements Export_Work_Repository_Port_Interface {
	/**
	 * Ready.
	 *
	 * @var array|null
	 */
	public ?array $ready = null;
	/**
	 * Failure.
	 *
	 * @var string|null
	 */
	public ?string $failure = null;
	/**
	 * Accept ready.
	 *
	 * @var bool
	 */
	public bool $accept_ready = true;
	/**
	 * Claimed.
	 *
	 * @var bool
	 */
	private bool $claimed = false;
	/**
	 * Job id.
	 *
	 * @var string
	 */
	private string $job_id;

	/**
	 * Initialize g5 s04 work repository dependencies and state.
	 *
	 * @param object $snapshot Snapshot.
	 * @param int    $actor Actor.
	 */
	public function __construct( private object $snapshot, private int $actor = 77 ) {
		$this->job_id = ( new Public_Id_Generator() )->create()->value();
	}

	/**
	 * Claim due.
	 *
	 * @param array  $artifact_kinds Artifact kinds.
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Export_Work_Item|null
	 */
	public function claim_due( array $artifact_kinds, string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Export_Work_Item {
		unset( $lease_expires_at_utc );
		if ( $this->claimed ) {
			return null;
		}
		$this->claimed = true;
		$kind          = $this->snapshot->artifact_kind();
		g5s04_assert( in_array( $kind, $artifact_kinds, true ), 'Worker claimed the wrong artifact kind.' );
		$is_report = $this->snapshot instanceof Report_Export_Request_Snapshot;
		return new Export_Work_Item(
			1,
			$this->job_id,
			1,
			$this->actor,
			$kind,
			$is_report ? 'report_snapshot' : 'assignment',
			$this->snapshot->source_id(),
			$this->snapshot->source_hash(),
			$this->snapshot->context()['type'],
			$this->snapshot->context()['id'],
			$this->snapshot->context()['revision'],
			$is_report ? Report_Export_Request_Snapshot::VERSION : Submission_Bundle_Request_Snapshot::VERSION,
			$is_report ? Report_Export_Request_Snapshot::SCHEMA : Submission_Bundle_Request_Snapshot::SCHEMA,
			$is_report ? Report_Export_Request_Snapshot::DATABASE_VERSION : Submission_Bundle_Request_Snapshot::DATABASE_VERSION,
			$this->snapshot->json(),
			$this->snapshot->hash(),
			$this->snapshot->hash(),
			$this->snapshot->profile(),
			$now_utc,
			$lease_token
		);
	}

	/**
	 * Complete ready.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param array            $file File.
	 * @param array            $artifact Artifact.
	 * @param string           $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_ready( Export_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		$this->ready = compact( 'item', 'file', 'artifact', 'finished_at_utc' );
		return $this->accept_ready;
	}

	/**
	 * Complete failure.
	 *
	 * @param Export_Work_Item $item Item.
	 * @param string           $error_code Error code.
	 * @param string           $audit_public_id Audit public id.
	 * @param string           $outbox_public_id Outbox public id.
	 * @param string           $correlation_id Request correlation identifier for diagnostics.
	 * @param string           $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure( Export_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
		unset( $item, $audit_public_id, $outbox_public_id, $correlation_id, $finished_at_utc );
		$this->failure = $error_code;
		return true;
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();
	/**
	 * Report relations.
	 *
	 * @var array<int,string>
	 */
	public array $report_relations = array( 'educator' );
	/**
	 * Context relation.
	 *
	 * @var string
	 */
	public string $context_relation = 'educator';
	/**
	 * Assignment relation.
	 *
	 * @var string
	 */
	public string $assignment_relation = 'context_staff';
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if ( ! $this->allowed ) {
			return Access_Policy_Decision::denied( 403, 'g5_s04_denied', $request->correlation_id() );
		}
		$context  = $request->context();
		$object   = $request->object();
		$relation = null !== $object && Object_Scope::TYPE_REPORT === $object->type()
			? ( $this->report_relations[ min( count( $this->requests ) - 1, count( $this->report_relations ) - 1 ) ] ?? 'educator' )
			: $this->assignment_relation;
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null === $context ? null : Context_Authorization_Result::allowed(
				$context->type(),
				$context->id(),
				'active',
				(string) $context->expected_revision(),
				$this->context_relation
			),
			null === $object ? null : Object_Authorization_Result::allowed(
				(string) $object->expected_revision(),
				(string) $object->expected_state(),
				$relation
			)
		);
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Bundle_Source implements Submission_Bundle_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g5 s04 bundle source dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $values Values.
	 */
	public function __construct( private array $values ) {
	}
	/**
	 * Read g5 s04 bundle source.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param bool   $include_allowed_originals Include allowed originals.
	 * @return array|null
	 */
	public function read( string $assignment_id, string $context_type, string $context_id, string $context_revision, bool $include_allowed_originals ): ?array {
		unset( $assignment_id, $context_type, $context_id, $context_revision, $include_allowed_originals );
		$value = $this->values[ min( $this->calls, count( $this->values ) - 1 ) ] ?? null;
		++$this->calls;
		return $value;
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 */
final class G5S04_Query_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Initialize g5 s04 query database dependencies and state.
	 *
	 * @param string $mode Mode.
	 * @param array  $row Row.
	 */
	public function __construct( private string $mode, private array $row ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		$count = preg_match_all( '/%[dsf]/', $sql );
		if ( ! is_int( $count ) || $count !== count( $values ) ) {
			$this->last_error = 'placeholder mismatch';
			return '';
		}
		return 'G5S04:' . base64_encode( serialize( array( $sql, $values ) ) );
	}
	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $mode Mode.
	 * @return array|null
	 */
	public function get_row( string $prepared, string $mode ): ?array {
		unset( $mode );
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		$sql   = is_array( $value ) ? (string) $value[0] : '';
		if ( 'direct' === $this->mode && str_contains( $sql, 'FROM oj_ai_pdf_artifacts a' ) ) {
			return null;
		}
		return $this->row;
	}
}

/**
 * G5-IMP-03-S04 actual export bytes, worker, Q23, and Q24 contract.
 *
 * @param array<string,mixed> $authorization
 */
final class G5S04_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g5 s04 query dependencies and state.
	 *
	 * @param array $job Job.
	 * @param array $artifact Artifact.
	 */
	public function __construct( private array $job, private array $artifact ) {
	}
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		unset( $actor_user_id );
		return $job_id === $this->job['job_id'] ? $this->job : null;
	}
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		unset( $actor_user_id );
		return $artifact_id === $this->artifact['artifact_id'] ? $this->artifact : null;
	}
}

/**
 * G5s04 query rows.
 *
 * @param object                $snapshot Snapshot.
 * @param Generated_Export_File $file File.
 * @param string                $job_id Job id.
 * @param string                $file_id File id.
 * @param string                $storage_key Storage key.
 * @return array
 */
function g5s04_query_rows( object $snapshot, Generated_Export_File $file, string $job_id, string $file_id, string $storage_key ): array {
	$source_type = $snapshot instanceof Report_Export_Request_Snapshot ? 'report_snapshot' : 'assignment';
	$context     = $snapshot->context();
	$common      = array(
		'artifact_kind'            => $snapshot->artifact_kind(),
		'source_type'              => $source_type,
		'source_public_id'         => $snapshot->source_id(),
		'source_hash'              => $snapshot->source_hash(),
		'print_profile_version'    => $snapshot->profile(),
		'context_type'             => $context['type'],
		'context_id'               => $context['id'],
		'context_revision'         => $context['revision'],
		'request_contract_version' => $snapshot::VERSION,
		'request_snapshot_schema'  => $snapshot::SCHEMA,
		'request_snapshot_version' => $snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $snapshot->json(),
		'request_snapshot_hash'    => $snapshot->hash(),
		'request_hash'             => $snapshot->hash(),
	);
	$expires     = '2026-08-26 18:00:00';
	$purpose     = 'report_json_package' === $snapshot->artifact_kind() ? 'report_json_package' : 'submission_bundle';
	$job         = $common + array(
		'public_id'              => $job_id,
		'state'                  => 'ready',
		'queued_at_utc'          => '2026-08-25 17:00:00',
		'started_at_utc'         => '2026-08-25 17:00:00',
		'finished_at_utc'        => '2026-08-25 17:00:01',
		'error_code'             => null,
		'result_file_public_id'  => $file_id,
		'pdf_artifact_public_id' => null,
		'pdf_file_public_id'     => null,
		'pdf_expires_at_utc'     => null,
		'file_public_id'         => $file_id,
		'owner_type'             => 'export_job',
		'owner_public_id'        => $job_id,
		'purpose'                => $purpose,
		'mime_type'              => 'application/zip',
		'file_state'             => 'ready',
		'scan_state'             => 'clean',
		'file_expires_at_utc'    => $expires,
	);
	$artifact    = $common + array(
		'job_state'                 => 'ready',
		'result_file_public_id'     => $file_id,
		'artifact_public_id'        => $file_id,
		'artifact_state'            => 'ready',
		'artifact_source_type'      => null,
		'artifact_source_public_id' => null,
		'artifact_source_hash'      => null,
		'expires_at_utc'            => $expires,
		'page_count'                => null,
		'job_public_id'             => $job_id,
		'file_public_id'            => $file_id,
		'owner_type'                => 'export_job',
		'owner_public_id'           => $job_id,
		'purpose'                   => $purpose,
		'storage_key'               => $storage_key,
		'original_name'             => $file->original_name(),
		'mime_type'                 => $file->mime_type(),
		'byte_size'                 => $file->byte_size(),
		'sha256'                    => $file->sha256(),
		'file_state'                => 'ready',
		'scan_state'                => 'clean',
		'file_expires_at_utc'       => $expires,
	);
	return array( $job, $artifact );
}

g5s04_assert( class_exists( 'ZipArchive' ) && method_exists( 'ZipArchive', 'setMtimeName' ), 'S04 requires deterministic ZipArchive support.' );
$report_renderer = new G5S04_Pdf_Renderer();
$report_writer   = new Report_Export_Artifact_Writer( $g5s04_report_canonical, $report_renderer, new Report_Print_Html_Serializer() );
$temporary_files = array();
$stores          = array();

try {
	$csv_snapshot      = g5s04_report_snapshot( $g5s04_report_snapshot, 'report_csv', $g5s04_report_canonical, true );
	$csv               = $report_writer->write( $csv_snapshot, $g5s04_report_manifest_json );
	$temporary_files[] = $csv->path();
	$csv_bytes         = file_get_contents( $csv->path() );
	g5s04_assert( is_string( $csv_bytes ) && str_starts_with( $csv_bytes, "\xEF\xBB\xBF" ), 'C46 CSV BOM is missing.' );
	g5s04_assert( str_contains( $csv_bytes, "'=SUM(1,1)" ) && 0 === preg_match( '/(?<!\r)\n/', $csv_bytes ), 'C46 formula neutralization or CRLF drifted.' );
	new Export_Download( $csv->path(), $csv->original_name(), $csv->mime_type(), $csv->artifact_kind(), $csv->byte_size(), $csv->sha256() );

	$pdf_snapshot      = g5s04_report_snapshot( $g5s04_report_snapshot, 'report_pdf', $g5s04_report_canonical );
	$pdf               = $report_writer->write( $pdf_snapshot, $g5s04_report_manifest_json );
	$temporary_files[] = $pdf->path();
	g5s04_same( 2, $pdf->page_count(), 'C47 report PDF page evidence drifted.' );
	$pdf_bytes = file_get_contents( $pdf->path() );
	g5s04_assert( is_string( $pdf_bytes ) && str_starts_with( $pdf_bytes, '%PDF-' ) && str_contains( end( $report_renderer->html ), '활동지 보고서' ), 'C47 report PDF bytes or HTML drifted.' );
	new Export_Download( $pdf->path(), $pdf->original_name(), $pdf->mime_type(), $pdf->artifact_kind(), $pdf->byte_size(), $pdf->sha256() );

	$json_one          = $report_writer->write( $g5s04_report_snapshot, $g5s04_report_manifest_json );
	$json_two          = $report_writer->write( $g5s04_report_snapshot, $g5s04_report_manifest_json );
	$temporary_files[] = $json_one->path();
	$temporary_files[] = $json_two->path();
	g5s04_same( $json_one->sha256(), $json_two->sha256(), 'C69 deterministic ZIP hash drifted.' );
	$json_entries = g5s04_zip_entries( $json_one->path() );
	g5s04_same( array( 'checksums.sha256', 'manifest.json', 'report.json' ), array_keys( $json_entries ), 'C69 exact ZIP entries drifted.' );
	g5s04_assert( str_contains( $json_entries['checksums.sha256'], hash( 'sha256', $json_entries['manifest.json'] ) . '  manifest.json' ), 'C69 manifest checksum drifted.' );

	$bundle_source                = array(
		'schema_id'      => Submission_Bundle_Request_Snapshot::SOURCE_SCHEMA,
		'schema_version' => Submission_Bundle_Request_Snapshot::SOURCE_VERSION,
		'context'        => array(
			'type'     => 'class',
			'id'       => 'class-21',
			'revision' => 'class-r3',
		),
		'assignment'     => array(
			'id'                     => $assignment_id,
			'hash'                   => hash( 'sha256', 'g5-s04-assignment' ),
			'publication_id'         => $publication_id,
			'publication_hash'       => $publication_hash,
			'title'                  => '계절 관찰 활동지',
			'state'                  => 'open',
			'schedule_version'       => 2,
			'schedule_hash'          => hash( 'sha256', 'g5-s04-schedule' ),
			'target_roster_revision' => 'roster-r2',
			'target_snapshot_hash'   => hash( 'sha256', 'g5-s04-targets' ),
		),
		'submissions'    => array(
			array(
				'target'            => array(
					'id'                  => $target_id,
					'hash'                => hash( 'sha256', 'g5-s04-target' ),
					'display_name'        => '=김하늘',
					'membership_revision' => 'member-r2',
					'snapshot_hash'       => hash( 'sha256', 'g5-s04-target-snapshot' ),
				),
				'submission'        => array(
					'id'               => $submission_id,
					'hash'             => hash( 'sha256', 'g5-s04-submission' ),
					'state'            => 'submitted',
					'submitted_at_utc' => '2026-08-23 09:30:00',
				),
				'official_revision' => array(
					'id'                  => $submission_revision_id,
					'hash'                => $revision_hash,
					'attachment_set_hash' => $attachment_set_hash,
				),
				'pdf_path'          => 'submissions/0001-' . strtolower( $target_id ) . '.pdf',
				'attachments'       => array(),
			),
		),
	);
	$bundle_source['source_hash'] = hash( 'sha256', $g5s04_submission_canonical->encode( $bundle_source ) );
	$bundle_snapshot              = Submission_Bundle_Request_Snapshot::freeze( $bundle_source, $bundle_source['context'], 'ko-KR', false, $g5s04_submission_canonical );
	$bundle_renderer              = new G5S04_Pdf_Renderer();
	$bundle_writer                = new Deterministic_Submission_Bundle_Writer(
		$g5s04_submission_projection,
		new OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer( $g5s04_submission_canonical ),
		$bundle_renderer,
		new G5S04_No_Attachments(),
		$g5s04_submission_canonical
	);
	$bundle_one                   = $bundle_writer->write( $bundle_snapshot );
	$bundle_two                   = $bundle_writer->write( $bundle_snapshot );
	$temporary_files[]            = $bundle_one->path();
	$temporary_files[]            = $bundle_two->path();
	g5s04_same( $bundle_one->sha256(), $bundle_two->sha256(), 'C50 deterministic bundle hash drifted.' );
	$bundle_entries = g5s04_zip_entries( $bundle_one->path() );
	g5s04_same( array( 'checksums.sha256', 'manifest.csv', 'submissions/0001-' . strtolower( $target_id ) . '.pdf' ), array_keys( $bundle_entries ), 'C50 exact bundle entries drifted.' );
	g5s04_assert( str_starts_with( $bundle_entries['manifest.csv'], "\xEF\xBB\xBF" ) && str_contains( $bundle_entries['manifest.csv'], "'=김하늘" ), 'C50 manifest CSV safety drifted.' );
	new Export_Download( $bundle_one->path(), $bundle_one->original_name(), $bundle_one->mime_type(), $bundle_one->artifact_kind(), $bundle_one->byte_size(), $bundle_one->sha256() );

	$report_repository = new G5S04_Work_Repository( $g5s04_report_snapshot );
	$report_store      = new G5S04_Private_Files();
	$stores[]          = $report_store;
	$report_policy     = new G5S04_Policy();
	$report_worker     = new Run_Next_Report_Export(
		$report_repository,
		$g5s04_report_reader,
		$report_writer,
		$report_store,
		$report_policy,
		new Public_Id_Generator(),
		$g5s04_report_canonical,
		static fn (): string => '2026-08-25 17:00:00',
		static fn (): string => '11111111-1111-4111-8111-111111111111'
	);
	$report_result     = $report_worker->run_once();
	g5s04_same( Run_Report_Export_Result::READY, $report_result->status(), 'Report worker did not complete ready: ' . (string) $report_repository->failure . '; policy=' . count( $report_policy->requests ) . '; files=' . count( $report_store->paths ) );
	g5s04_same( 2, count( $report_policy->requests ), 'Report worker did not reauthorize before and after generation.' );
	g5s04_assert( is_array( $report_repository->ready ) && 1 === count( $report_store->paths ), 'Report ready CAS or private storage drifted.' );

	$revoked_repository               = new G5S04_Work_Repository( $g5s04_report_snapshot );
	$revoked_store                    = new G5S04_Private_Files();
	$stores[]                         = $revoked_store;
	$revoked_policy                   = new G5S04_Policy();
	$revoked_policy->report_relations = array( 'educator', 'manager' );
	$revoked_worker                   = new Run_Next_Report_Export(
		$revoked_repository,
		$g5s04_report_reader,
		$report_writer,
		$revoked_store,
		$revoked_policy,
		new Public_Id_Generator(),
		$g5s04_report_canonical,
		static fn (): string => '2026-08-25 17:00:00',
		static fn (): string => '22222222-2222-4222-8222-222222222222'
	);
	g5s04_same( Run_Report_Export_Result::FAILED, $revoked_worker->run_once()->status(), 'Post-render report relation drift did not fail.' );
	g5s04_same( Report_Export_Failure::SOURCE_ACCESS_DENIED, $revoked_repository->failure, 'Report relation drift failure code changed.' );
	g5s04_same( 0, count( $revoked_store->paths ), 'Revoked report bytes reached private storage.' );

	$bundle_repository = new G5S04_Work_Repository( $bundle_snapshot );
	$bundle_store      = new G5S04_Private_Files();
	$stores[]          = $bundle_store;
	$bundle_policy     = new G5S04_Policy();
	$bundle_worker     = new Run_Next_Submission_Bundle(
		$bundle_repository,
		new G5S04_Bundle_Source( array( $bundle_source, $bundle_source ) ),
		$bundle_writer,
		$bundle_store,
		$bundle_policy,
		new Public_Id_Generator(),
		$g5s04_submission_canonical,
		static fn (): string => '2026-08-25 17:00:00',
		static fn (): string => '33333333-3333-4333-8333-333333333333'
	);
	g5s04_same( Run_Submission_Bundle_Result::READY, $bundle_worker->run_once()->status(), 'Submission bundle worker did not complete ready.' );
	g5s04_same( 2, count( $bundle_policy->requests ), 'Submission bundle worker did not reauthorize twice.' );

	$drifted_source                        = $bundle_source;
	$drifted_source['assignment']['title'] = '변경된 제목';
	unset( $drifted_source['source_hash'] );
	$drifted_source['source_hash'] = hash( 'sha256', $g5s04_submission_canonical->encode( $drifted_source ) );
	$drift_repository              = new G5S04_Work_Repository( $bundle_snapshot );
	$drift_store                   = new G5S04_Private_Files();
	$stores[]                      = $drift_store;
	$drift_worker                  = new Run_Next_Submission_Bundle(
		$drift_repository,
		new G5S04_Bundle_Source( array( $bundle_source, $drifted_source ) ),
		$bundle_writer,
		$drift_store,
		new G5S04_Policy(),
		new Public_Id_Generator(),
		$g5s04_submission_canonical,
		static fn (): string => '2026-08-25 17:00:00',
		static fn (): string => '44444444-4444-4444-8444-444444444444'
	);
	g5s04_same( Run_Submission_Bundle_Result::FAILED, $drift_worker->run_once()->status(), 'Post-render bundle source drift did not fail.' );
	g5s04_same( Submission_Bundle_Failure::SOURCE_STALE, $drift_repository->failure, 'Bundle drift failure code changed.' );
	g5s04_same( 0, count( $drift_store->paths ), 'Drifted bundle bytes reached private storage.' );

	$ids                            = new Public_Id_Generator();
	$job_id                         = $ids->create()->value();
	$file_id                        = $ids->create()->value();
	list( $job_row, $artifact_row ) = g5s04_query_rows( $g5s04_report_snapshot, $json_one, $job_id, $file_id, 'generated/report/' . strtolower( $file_id ) . '.zip' );
	$job_query                      = new WordPress_Export_Query( new G5S04_Query_Database( 'job', $job_row ), $g5s04_report_canonical );
	$artifact_query                 = new WordPress_Export_Query( new G5S04_Query_Database( 'direct', $artifact_row ), $g5s04_report_canonical );
	$normalized_job                 = $job_query->find_job_for_actor( 77, $job_id );
	$normalized_artifact            = $artifact_query->find_artifact_for_actor( 77, $file_id );
	g5s04_same( 'context_reporter', $normalized_job['authorization']['object']['relation'], 'Q23 report relation descriptor drifted.' );
	g5s04_same( $file_id, $normalized_artifact['artifact_id'], 'Q24 direct report artifact identity drifted.' );

	$read_files = new G5S04_Private_Files();
	$stores[]   = $read_files;
	$read_files->put_quarantined( $json_one->path(), $artifact_row['storage_key'], $json_one->sha256() );
	$report_access_policy = new G5S04_Policy();
	$job_reader           = new Read_Export_Job( $job_query, $report_access_policy, static fn (): string => '2026-08-25 17:00:00' );
	$artifact_reader      = new Read_Export_Artifact( $artifact_query, $read_files, $report_access_policy, static fn (): string => '2026-08-25 17:00:00' );
	g5s04_same( 'ready', $job_reader->execute( 77, $job_id, 'g5-s04-q23' )['state'], 'Q23 ready state drifted.' );
	g5s04_same( 'report_json_package', $artifact_reader->execute( 77, $file_id, 'g5-s04-q24' )->artifact_kind(), 'Q24 report download profile drifted.' );
	$report_access_policy->report_relations = array( 'manager' );
	g5s04_export_failure(
		static fn () => $artifact_reader->execute( 77, $file_id, 'g5-s04-revoked' ),
		Export_Failure::ACCESS_DENIED,
		'Q24 accepted a report object relation that no longer matched Context.'
	);
	$expired_policy = new G5S04_Policy();
	$expired_reader = new Read_Export_Artifact( $artifact_query, $read_files, $expired_policy, static fn (): string => '2026-08-27 00:00:00' );
	g5s04_export_failure( static fn () => $expired_reader->execute( 77, $file_id, 'g5-s04-expired' ), Export_Failure::EXPIRED, 'Q24 served an expired report artifact.' );

	$bundle_job_id                                = $ids->create()->value();
	$bundle_file_id                               = $ids->create()->value();
	list( $bundle_job_row, $bundle_artifact_row ) = g5s04_query_rows( $bundle_snapshot, $bundle_one, $bundle_job_id, $bundle_file_id, 'generated/submission-bundle/' . strtolower( $bundle_file_id ) . '.zip' );
	$bundle_query                                 = new WordPress_Export_Query( new G5S04_Query_Database( 'job', $bundle_job_row ), $g5s04_submission_canonical );
	$bundle_artifact_query                        = new WordPress_Export_Query( new G5S04_Query_Database( 'direct', $bundle_artifact_row ), $g5s04_submission_canonical );
	g5s04_same( 'export_submissions', $bundle_query->find_job_for_actor( 77, $bundle_job_id )['authorization']['object']['action'], 'Q23 bundle authorization action drifted.' );
	g5s04_same( (string) $bundle_snapshot->schedule_version(), $bundle_artifact_query->find_artifact_for_actor( 77, $bundle_file_id )['authorization']['object']['revision'], 'Q24 bundle schedule evidence drifted.' );

	$runtime_files = array(
		'src/Reporting/Export/Infrastructure/WordPress_Export_Work_Repository.php',
		'src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		'src/Reporting/Export/Application/Export_Access_Guard.php',
		'src/Reporting/ReportExport/Application/Run_Next_Report_Export.php',
		'src/Reporting/SubmissionBundle/Application/Run_Next_Submission_Bundle.php',
		'src/Storage/Infrastructure/WordPress_Export_Artifact_Private_File_Metadata.php',
		'src/Core/Composition_Root.php',
	);
	$runtime       = '';
	foreach ( $runtime_files as $file ) {
		$runtime .= (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
	}
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5s04_assert( ! str_contains( $runtime, $forbidden ), 'S04 contains a forbidden boundary marker: ' . $forbidden );
	}
	foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_pdf_artifacts'", "'oj_ai_private_files'", 'START TRANSACTION', 'lease_token', 'context_reporter', 'new Report_Export_Worker_Module', 'new Submission_Bundle_Worker_Module' ) as $marker ) {
		g5s04_assert( str_contains( $runtime, $marker ), 'S04 runtime marker is missing: ' . $marker );
	}
} finally {
	foreach ( $temporary_files as $path ) {
		if ( is_string( $path ) && is_file( $path ) ) {
			unlink( $path );
		}
	}
	foreach ( $stores as $store ) {
		$store->cleanup();
	}
}

fwrite( STDOUT, "G5-IMP-03-S04 passed: actual CSV/PDF/JSON/submission ZIP bytes, pre/post authorization and source checks, private ready CAS, Q23/Q24 download, expiry, and no-prefix boundaries are frozen.\n" );
