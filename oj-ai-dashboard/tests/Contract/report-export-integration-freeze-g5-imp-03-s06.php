<?php
/**
 * G5-IMP-03-S06 export checksum, authorization, control, and stream freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Export\Presentation\Export_Rest_Controller;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;

ob_start();
require __DIR__ . '/report-export-control-g5-imp-03-s05.php';
ob_end_clean();

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-03-S06 export checksum, authorization, control, and stream freeze.
	 */
	final class WP_REST_Response {
		/**
		 * Headers.
		 *
		 * @var array<string,string>
		 */
		public array $headers = array();
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( public mixed $data = null, public int $status = 200 ) {
		}
		/**
		 * Header.
		 *
		 * @param string $name Name.
		 * @param string $value Value.
		 * @return void
		 */
		public function header( string $name, string $value ): void {
			$this->headers[ $name ] = $value;
		}
	}
}

/**
 * G5s06 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s06_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5s06 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s06_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5s06 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s06_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation(); } catch ( Export_Failure $failure ) {
		g5s06_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G5s06 id.
 *
 * @param int $value Value.
 * @return string
 */
function g5s06_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * G5s06 report authorization.
 *
 * @param string $source_id Source id.
 * @param string $source_hash Source hash.
 * @return array{context:array<string,string>,object:array<string,mixed>}
 */
function g5s06_report_authorization( string $source_id, string $source_hash ): array {
	return array(
		'context' => array(
			'id'       => 'class-21',
			'revision' => 'class-r3',
			'type'     => 'class',
		),
		'object'  => array(
			'action'   => 'view',
			'id'       => $source_id,
			'relation' => 'context_reporter',
			'revision' => $source_hash,
			'state'    => 'ready',
			'type'     => 'report',
		),
	);
}

/**
 * G5s06 bundle authorization.
 *
 * @param string $assignment_id Distribution public identifier.
 * @return array{context:array<string,string>,object:array<string,mixed>}
 */
function g5s06_bundle_authorization( string $assignment_id ): array {
	return array(
		'context' => array(
			'id'       => 'class-21',
			'revision' => 'class-r3',
			'type'     => 'class',
		),
		'object'  => array(
			'action'   => 'export_submissions',
			'id'       => $assignment_id,
			'relation' => 'context_staff',
			'revision' => '2',
			'state'    => 'open',
			'type'     => 'assignment',
		),
	);
}

/**
 * G5-IMP-03-S06 export checksum, authorization, control, and stream freeze.
 */
final class G5S06_Query implements Export_Query_Port_Interface {
	/**
	 * Initialize g5 s06 query dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $jobs Jobs.
	 * @param array<string,array<string,mixed>> $artifacts Artifacts.
	 */
	public function __construct( public array $jobs, public array $artifacts ) {
	}
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		return 77 === $actor_user_id ? ( $this->jobs[ $job_id ] ?? null ) : null;
	}
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		return 77 === $actor_user_id ? ( $this->artifacts[ $artifact_id ] ?? null ) : null;
	}
}

/**
 * G5-IMP-03-S06 export checksum, authorization, control, and stream freeze.
 */
final class G5S06_Private_Files implements Private_File_Read_Port_Interface {
	/**
	 * Paths.
	 *
	 * @var array<string,string>
	 */
	public array $paths = array();
	/**
	 * Mutate after validation.
	 *
	 * @var array<string,string>
	 */
	public array $mutate_after_validation = array();
	/**
	 * Calls.
	 *
	 * @var array<string,int>
	 */
	public array $calls = array();

	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		$path                        = $this->paths[ $storage_key ] ?? null;
		$this->calls[ $storage_key ] = ( $this->calls[ $storage_key ] ?? 0 ) + 1;
		if ( ! is_string( $path ) || ! is_file( $path ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'S06 private file checksum mismatch.' );
		}
		if ( isset( $this->mutate_after_validation[ $storage_key ] ) ) {
			file_put_contents( $path, $this->mutate_after_validation[ $storage_key ] );
			unset( $this->mutate_after_validation[ $storage_key ] );
		}
		return $path;
	}
}

/**
 * G5-IMP-03-S06 export checksum, authorization, control, and stream freeze.
 */
final class G5S06_Current_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Revision override.
	 *
	 * @var string|null
	 */
	public ?string $revision_override = null;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		if ( ! $this->allowed ) {
			return Access_Policy_Decision::denied( 403, 'g5_s06_revoked', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if ( null === $object ) {
			return Access_Policy_Decision::denied( 403, 'g5_s06_object_missing', $request->correlation_id() );
		}
		$relation = Object_Scope::TYPE_ASSIGNMENT === $object->type() ? 'context_staff' : 'educator';
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null === $context ? null : Context_Authorization_Result::allowed(
				$context->type(),
				$context->id(),
				'active',
				(string) $context->expected_revision(),
				'educator'
			),
			Object_Authorization_Result::allowed(
				$this->revision_override ?? (string) $object->expected_revision(),
				(string) $object->expected_state(),
				$relation
			)
		);
	}
}

/**
 * G5s06 fixture file.
 *
 * @param string $kind Kind.
 * @return array{path:string,bytes:string}
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s06_fixture_file( string $kind ): array {
	$path = tempnam( sys_get_temp_dir(), 'oj-ai-g5-s06-' );
	if ( false === $path ) {
		throw new RuntimeException( 'S06 temporary file is unavailable.' );
	}
	if ( in_array( $kind, array( 'report_json_package', 'submission_bundle' ), true ) ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'S06 ZIP fixture is unavailable.' );
		}
		$zip->addFromString( 'checksums.sha256', hash( 'sha256', $kind ) . '  payload.json' . "\n" );
		$zip->addFromString( 'payload.json', '{"kind":"' . $kind . '"}' );
		$zip->close();
	} else {
		$bytes = 'report_csv' === $kind ? "\xEF\xBB\xBFtitle,score\r\nWorksheet,100\r\n" : "%PDF-1.7\nG5 S06 report\n%%EOF";
		file_put_contents( $path, $bytes );
	}
	$bytes = file_get_contents( $path );
	if ( ! is_string( $bytes ) || '' === $bytes ) {
		throw new RuntimeException( 'S06 fixture bytes are unavailable.' );
	}
	return compact( 'path', 'bytes' );
}

/**
 * G5s06 artifact row.
 *
 * @param string $kind Kind.
 * @param int    $number Number.
 * @param string $path Path.
 * @param string $job_id Job id.
 * @param string $source_id Source id.
 * @param string $source_hash Source hash.
 * @return array<string,mixed>
 */
function g5s06_artifact_row( string $kind, int $number, string $path, string $job_id, string $source_id, string $source_hash ): array {
	$artifact_id     = g5s06_id( $number );
	$profiles        = array(
		'report_csv'          => array( 'text/csv', 'report.csv' ),
		'report_pdf'          => array( 'application/pdf', 'report.pdf' ),
		'report_json_package' => array( 'application/zip', 'report-json.zip' ),
		'submission_bundle'   => array( 'application/zip', 'submissions.zip' ),
	);
	[ $mime, $name ] = $profiles[ $kind ];
	$is_bundle       = 'submission_bundle' === $kind;
	return array(
		'artifact_id'    => $artifact_id,
		'artifact_kind'  => $kind,
		'job_id'         => $job_id,
		'source_type'    => $is_bundle ? 'assignment' : 'report_snapshot',
		'source_id'      => $source_id,
		'source_hash'    => $source_hash,
		'artifact_state' => 'ready',
		'expires_at_utc' => '2026-08-26 18:00:00',
		'page_count'     => 'report_pdf' === $kind ? 2 : null,
		'file_id'        => $artifact_id,
		'storage_key'    => 'generated/integration/' . strtolower( $artifact_id ),
		'original_name'  => $name,
		'mime_type'      => $mime,
		'byte_size'      => filesize( $path ),
		'sha256'         => hash_file( 'sha256', $path ),
		'file_state'     => 'ready',
		'scan_state'     => 'clean',
		'authorization'  => $is_bundle ? g5s06_bundle_authorization( $source_id ) : g5s06_report_authorization( $source_id, $source_hash ),
	);
}

/**
 * G5s06 stream.
 *
 * @param Export_Rest_Controller $controller Controller.
 * @param ReflectionMethod       $method Method.
 * @param Export_Download        $download Download.
 * @param string                 $artifact_id Artifact id.
 * @param string                 $correlation Correlation.
 * @return array{0:WP_REST_Response,1:string}
 */
function g5s06_stream( Export_Rest_Controller $controller, ReflectionMethod $method, Export_Download $download, string $artifact_id, string $correlation ): array {
	$response = $method->invoke( $controller, $download, $correlation, 77, $artifact_id );
	ob_start();
	$served = $controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$output = ob_get_clean();
	g5s06_same( true, $served, 'S06 stream filter did not claim its response.' );
	return array( $response, is_string( $output ) ? $output : '' );
}

g5s06_same( 'cancelled', $cancel_database->tables['oj_ai_export_jobs'][0]['state'], 'C53 cancelled state was not preserved for the integration freeze.' );
g5s06_same( null, $cancel_database->tables['oj_ai_export_jobs'][0]['result_file_public_id'], 'A cancelled job retained a downloadable file pointer.' );
g5s06_same( null, $cancel_database->tables['oj_ai_export_jobs'][0]['active_identity_key'], 'C53 did not release the active identity before a new request.' );
g5s06_same( 'failed', $retry_database->tables['oj_ai_export_jobs'][0]['state'], 'C54 mutated the failed source history.' );
g5s06_same( 'queued', $retried_job['state'], 'C54 retry did not remain queued for worker claim.' );
foreach ( array( 'artifact_kind', 'source_type', 'source_public_id', 'source_hash', 'request_contract_version', 'request_snapshot_schema', 'request_snapshot_version', 'request_snapshot_json', 'request_snapshot_hash', 'request_hash', 'print_profile_version' ) as $key ) {
	g5s06_same( $failed_job[ $key ], $retried_job[ $key ], 'C54 changed frozen retry evidence: ' . $key );
}
g5s06_same( $g5s05_active, $retried_job['active_identity_key'], 'C54 retry lost its semantic active identity.' );

$source_id      = (string) $retried_job['source_public_id'];
$source_hash    = (string) $retried_job['source_hash'];
$assignment_id  = g5s06_id( 6060 );
$temporary      = array();
$artifacts      = array();
$artifact_bytes = array();
$files          = new G5S06_Private_Files();
$kinds          = array( 'report_csv', 'report_pdf', 'report_json_package', 'submission_bundle' );
foreach ( $kinds as $index => $kind ) {
	$fixture                               = g5s06_fixture_file( $kind );
	$temporary[]                           = $fixture['path'];
	$job_id                                = 'report_json_package' === $kind ? (string) $retried_job['public_id'] : g5s06_id( 6200 + $index );
	$row                                   = g5s06_artifact_row( $kind, 6300 + $index, $fixture['path'], $job_id, 'submission_bundle' === $kind ? $assignment_id : $source_id, $source_hash );
	$artifacts[ $row['artifact_id'] ]      = $row;
	$artifact_bytes[ $row['artifact_id'] ] = $fixture['bytes'];
	$files->paths[ $row['storage_key'] ]   = $fixture['path'];
}

$authorization   = g5s06_report_authorization( $source_id, $source_hash );
$jobs            = array(
	(string) $cancel_job['public_id']  => array(
		'job_id'         => $cancel_job['public_id'],
		'state'          => 'cancelled',
		'artifact_id'    => null,
		'expires_at_utc' => null,
		'authorization'  => $authorization,
	),
	(string) $failed_job['public_id']  => array(
		'job_id'         => $failed_job['public_id'],
		'state'          => 'failed',
		'artifact_id'    => null,
		'expires_at_utc' => null,
		'authorization'  => $authorization,
	),
	(string) $retried_job['public_id'] => array(
		'job_id'         => $retried_job['public_id'],
		'state'          => 'queued',
		'artifact_id'    => null,
		'expires_at_utc' => null,
		'authorization'  => $authorization,
	),
);
$query           = new G5S06_Query( $jobs, $artifacts );
$policy          = new G5S06_Current_Policy();
$job_reader      = new Read_Export_Job( $query, $policy, static fn (): string => '2026-08-25 19:00:00' );
$artifact_reader = new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-25 19:00:00' );
g5s06_same( 'cancelled', $job_reader->execute( 77, (string) $cancel_job['public_id'], 'g5-s06-cancelled-q23' )['state'], 'Q23 hid the cancelled terminal state.' );
g5s06_same( 'failed', $job_reader->execute( 77, (string) $failed_job['public_id'], 'g5-s06-failed-q23' )['state'], 'Q23 hid the failed source history.' );
g5s06_same( 'queued', $job_reader->execute( 77, (string) $retried_job['public_id'], 'g5-s06-retry-q23' )['state'], 'Q23 did not expose the queued retry.' );
$policy->allowed = false;
g5s06_failure( static fn () => $job_reader->execute( 77, (string) $cancel_job['public_id'], 'g5-s06-q23-revoked' ), Export_Failure::ACCESS_DENIED, 'Q23 disclosed a cancelled job after current authorization was revoked.' );
$policy->allowed = true;
g5s06_failure( static fn () => $artifact_reader->execute( 77, (string) $cancel_job['public_id'], 'g5-s06-cancelled-q24' ), Export_Failure::NOT_FOUND, 'Q24 found bytes for a cancelled job.' );

$controller_reflection = new ReflectionClass( Export_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$property              = $controller_reflection->getProperty( 'read_artifact' );
$property->setValue( $controller, $artifact_reader );
$property = $controller_reflection->getProperty( 'streams' );
$property->setValue( $controller, new WeakMap() );
$stream_response = $controller_reflection->getMethod( 'stream_response' );

try {
	foreach ( $artifacts as $artifact_id => $row ) {
		$download                = $artifact_reader->execute( 77, $artifact_id, 'g5-s06-preflight-' . $row['artifact_kind'] );
		[ $response, $streamed ] = g5s06_stream( $controller, $stream_response, $download, $artifact_id, 'g5-s06-stream-' . $row['artifact_kind'] );
		g5s06_same( $artifact_bytes[ $artifact_id ], $streamed, 'S06 did not stream exact bytes for ' . $row['artifact_kind'] . '.' );
		g5s06_same( $row['sha256'], $response->headers['X-OJ-Content-SHA256'] ?? null, 'S06 response checksum header drifted for ' . $row['artifact_kind'] . '.' );
		g5s06_same( (string) $row['byte_size'], $response->headers['Content-Length'] ?? null, 'S06 response byte header drifted for ' . $row['artifact_kind'] . '.' );
	}

	$json_id  = array_values( array_filter( array_keys( $artifacts ), static fn ( string $id ): bool => 'report_json_package' === $artifacts[ $id ]['artifact_kind'] ) )[0];
	$json_row = $query->artifacts[ $json_id ];

	$download        = $artifact_reader->execute( 77, $json_id, 'g5-s06-revocation-preflight' );
	$response        = $stream_response->invoke( $controller, $download, 'g5-s06-revocation-stream', 77, $json_id );
	$policy->allowed = false;
	ob_start();
	$controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$revoked_bytes   = ob_get_clean();
	$policy->allowed = true;
	g5s06_same( '', is_string( $revoked_bytes ) ? $revoked_bytes : '', 'Pre-stream authorization revocation emitted bytes.' );

	$download                  = $artifact_reader->execute( 77, $json_id, 'g5-s06-source-drift-preflight' );
	$response                  = $stream_response->invoke( $controller, $download, 'g5-s06-source-drift-stream', 77, $json_id );
	$policy->revision_override = str_repeat( '0', 64 );
	ob_start();
	$controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$drifted_bytes             = ob_get_clean();
	$policy->revision_override = null;
	g5s06_same( '', is_string( $drifted_bytes ) ? $drifted_bytes : '', 'Pre-stream source revision drift emitted bytes.' );

	$download                                      = $artifact_reader->execute( 77, $json_id, 'g5-s06-metadata-preflight' );
	$response                                      = $stream_response->invoke( $controller, $download, 'g5-s06-metadata-stream', 77, $json_id );
	$query->artifacts[ $json_id ]['original_name'] = 'changed-report-json.zip';
	ob_start();
	$controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$swapped_bytes = ob_get_clean();
	g5s06_same( '', is_string( $swapped_bytes ) ? $swapped_bytes : '', 'Changed artifact metadata emitted bytes.' );
	$query->artifacts[ $json_id ] = $json_row;

	$query->artifacts[ $json_id ]['sha256'] = str_repeat( 'f', 64 );
	g5s06_failure( static fn () => $artifact_reader->execute( 77, $json_id, 'g5-s06-bad-metadata-hash' ), Export_Failure::STORAGE_UNAVAILABLE, 'Q24 accepted a metadata checksum that did not match private bytes.' );
	$query->artifacts[ $json_id ]              = $json_row;
	$query->artifacts[ $json_id ]['byte_size'] = (int) $json_row['byte_size'] + 1;
	g5s06_failure( static fn () => $artifact_reader->execute( 77, $json_id, 'g5-s06-bad-size' ), Export_Failure::STORED_DATA_INVALID, 'Q24 accepted a metadata byte count that did not match private bytes.' );
	$query->artifacts[ $json_id ] = $json_row;

	$download = $artifact_reader->execute( 77, $json_id, 'g5-s06-tamper-preflight' );
	$response = $stream_response->invoke( $controller, $download, 'g5-s06-tamper-stream', 77, $json_id );
	$files->mutate_after_validation[ $json_row['storage_key'] ] = str_repeat( 'X', (int) $json_row['byte_size'] );
	ob_start();
	$controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$tampered_bytes = ob_get_clean();
	g5s06_same( '', is_string( $tampered_bytes ) ? $tampered_bytes : '', 'Same-size post-validation file tampering emitted bytes.' );
	g5s06_assert( ! hash_equals( $json_row['sha256'], (string) hash_file( 'sha256', $files->paths[ $json_row['storage_key'] ] ) ), 'S06 tamper fixture did not change the checksum.' );
} finally {
	foreach ( $temporary as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}

$runtime_files = array(
	'src/Reporting/Export/Application/Read_Export_Artifact.php',
	'src/Reporting/Export/Presentation/Export_Rest_Controller.php',
	'src/Reporting/Export/Infrastructure/WordPress_Export_Control_Repository.php',
	'src/Reporting/ReportExport/Application/Run_Next_Report_Export.php',
	'src/Reporting/SubmissionBundle/Application/Run_Next_Submission_Bundle.php',
	'src/Storage/Infrastructure/WordPress_Export_Artifact_Private_File_Metadata.php',
);
$runtime       = '';
foreach ( $runtime_files as $file ) {
	$runtime .= (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5s06_assert( ! str_contains( $runtime, $forbidden ), 'S06 runtime contains a forbidden boundary marker: ' . $forbidden );
}
foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_private_files'", 'same_download', 'verified_handle', 'hash_init', 'readable_path', 'export_job.retry_queued' ) as $marker ) {
	g5s06_assert( str_contains( $runtime, $marker ), 'S06 runtime marker is missing: ' . $marker );
}

fwrite( STDOUT, "G5-IMP-03-S06 passed: C53/C54 immutable lineage, all four private export streams, current authorization, metadata identity, byte size, checksum, and same-size tamper blocking are frozen.\n" );
