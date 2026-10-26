<?php
/**
 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/result-pdf-job-g4-imp-07-s02.php';
ob_end_clean();

use OJAI\Dashboard\Reporting\Export\Application\Export_Download;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Artifact;
use OJAI\Dashboard\Reporting\Export\Application\Read_Export_Job;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Export\Presentation\Export_Rest_Controller;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
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
 * G4s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4s03_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4s03 failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4s03_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Export_Failure $failure ) {
		g4s03_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
 */
final class G4S03_Database {
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
	 * Initialize g4 s03 database dependencies and state.
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
 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
 */
final class G4S03_Query implements Export_Query_Port_Interface {
	/**
	 * Job calls.
	 *
	 * @var int
	 */
	public int $job_calls = 0;
	/**
	 * Artifact calls.
	 *
	 * @var int
	 */
	public int $artifact_calls = 0;
	/**
	 * Initialize g4 s03 query dependencies and state.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $artifact Artifact.
	 * @param int                 $owner Owner.
	 */
	public function __construct( public array $job, public array $artifact, private int $owner = 42 ) {
	}
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		++$this->job_calls;
		return $actor_user_id === $this->owner && $job_id === $this->job['job_id'] ? $this->job : null;
	}
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		++$this->artifact_calls;
		return $actor_user_id === $this->owner && $artifact_id === $this->artifact['artifact_id'] ? $this->artifact : null;
	}
}

/**
 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
 */
final class G4S03_Release_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Current revision.
	 *
	 * @var string
	 */
	public string $current_revision;
	/**
	 * Current state.
	 *
	 * @var string
	 */
	public string $current_state;
	/**
	 * Context revision.
	 *
	 * @var string
	 */
	public string $context_revision;
	/**
	 * Relation.
	 *
	 * @var string
	 */
	public string $relation = 'self_release';
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Initialize g4 s03 release policy dependencies and state.
	 *
	 * @param array  $context Access or execution context for this operation.
	 * @param string $revision Revision.
	 * @param string $state State.
	 */
	public function __construct( private array $context, string $revision, string $state ) {
		$this->current_revision = $revision;
		$this->current_state    = $state;
		$this->context_revision = (string) $context['revision'];
	}

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
			return Access_Policy_Decision::denied( 403, 'test_result_access_revoked', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null === $context ? null : Context_Authorization_Result::allowed(
				$context->type(),
				$context->id(),
				'active',
				$this->context_revision,
				'learner'
			),
			null === $object ? null : Object_Authorization_Result::allowed(
				$this->current_revision,
				$this->current_state,
				$this->relation
			)
		);
	}
}

/**
 * G4-IMP-07-S03 result PDF Q23/Q24 revocation, expiry, and stream contract.
 */
final class G4S03_Private_Read implements Private_File_Read_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Verify hash.
	 *
	 * @var bool
	 */
	public bool $verify_hash = true;
	/**
	 * Initialize g4 s03 private read dependencies and state.
	 *
	 * @param string $path Path.
	 * @param string $storage_key Storage key.
	 */
	public function __construct( private string $path, private string $storage_key ) {
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
		if ( $storage_key !== $this->storage_key || ! is_file( $this->path ) ) {
			throw new RuntimeException( 'Result PDF fixture is unavailable.' );
		}
		if ( $this->verify_hash && ! hash_equals( $sha256, (string) hash_file( 'sha256', $this->path ) ) ) {
			throw new RuntimeException( 'Result PDF fixture hash drifted.' );
		}
		return $this->path;
	}
}

/**
 * G4s03 controller.
 *
 * @param Read_Export_Artifact $reader Reader.
 * @param array<string,mixed>  &$values Values.
 * @return Export_Rest_Controller
 */
function g4s03_controller( Read_Export_Artifact $reader, array &$values ): Export_Rest_Controller {
	$reflection = new ReflectionClass( Export_Rest_Controller::class );
	/**
	 * Controller.
	 *
	 * @var Export_Rest_Controller $controller
	 */
	$controller = $reflection->newInstanceWithoutConstructor();
	$streams    = new WeakMap();
	foreach ( array(
		'read_artifact' => $reader,
		'streams'       => $streams,
	) as $property => $value ) {
		$target = $reflection->getProperty( $property );
		$target->setValue( $controller, $value );
	}
	$values = array(
		'reflection' => $reflection,
		'streams'    => $streams,
	);
	return $controller;
}

/**
 * G4s03 response.
 *
 * @param Export_Rest_Controller $controller Controller.
 * @param array<string,mixed>    $state State.
 * @param Export_Download        $download Download.
 * @param int                    $actor Actor.
 * @param string                 $artifact_id Artifact id.
 * @param string                 $correlation Correlation.
 * @return object
 */
function g4s03_response( Export_Rest_Controller $controller, array $state, Export_Download $download, int $actor, string $artifact_id, string $correlation ): object {
	unset( $controller );
	$response = new stdClass();
	/**
	 * Streams.
	 *
	 * @var WeakMap<object,array<string,mixed>> $streams
	 */
	$streams              = $state['streams'];
	$streams[ $response ] = array(
		'download'    => $download,
		'actor'       => $actor,
		'artifact_id' => $artifact_id,
		'correlation' => $correlation,
	);
	return $response;
}

$context        = $result_pdf_snapshot->context();
$ids            = new Public_Id_Generator();
$job_id         = $ids->create()->value();
$artifact_id    = $ids->create()->value();
$file_id        = $ids->create()->value();
$expires        = '2026-08-25 12:01:00';
$storage_key    = 'generated/pdf/result-' . strtolower( $artifact_id ) . '.pdf';
$original_bytes = "%PDF-1.7\nG4 S03 CURRENT result\n%%EOF";
$tampered_bytes = "%PDF-1.7\nG4 S03 TAMPER! result\n%%EOF";
g4s03_same( strlen( $original_bytes ), strlen( $tampered_bytes ), 'Same-size stream tamper fixture drifted.' );
$pdf_path = tempnam( sys_get_temp_dir(), 'oj-ai-g4-s03-' );
if ( false === $pdf_path || false === file_put_contents( $pdf_path, $original_bytes, LOCK_EX ) ) {
	throw new RuntimeException( 'Result PDF fixture could not be created.' );
}

try {
	$byte_size = filesize( $pdf_path );
	$sha256    = hash_file( 'sha256', $pdf_path );
	g4s03_assert( is_int( $byte_size ) && is_string( $sha256 ), 'Result PDF fixture metadata is unavailable.' );
	$common         = array(
		'artifact_kind'            => 'result_pdf',
		'source_type'              => 'submission_release',
		'source_public_id'         => $result_pdf_snapshot->source_id(),
		'source_hash'              => $result_pdf_snapshot->source_hash(),
		'print_profile_version'    => $result_pdf_snapshot->profile_version(),
		'context_type'             => $context['type'],
		'context_id'               => $context['id'],
		'context_revision'         => $context['revision'],
		'request_contract_version' => Result_Pdf_Request_Snapshot::VERSION,
		'request_snapshot_schema'  => Result_Pdf_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => (string) Result_Pdf_Request_Snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $result_pdf_snapshot->json(),
		'request_snapshot_hash'    => $result_pdf_snapshot->hash(),
		'request_hash'             => $result_pdf_snapshot->hash(),
	);
	$job_database   = new G4S03_Database(
		array_merge(
			$common,
			array(
				'public_id'              => $job_id,
				'state'                  => 'ready',
				'queued_at_utc'          => '2026-08-24 12:00:00',
				'started_at_utc'         => '2026-08-24 12:00:01',
				'finished_at_utc'        => '2026-08-24 12:01:00',
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
	$real_job_query = new WordPress_Export_Query( $job_database, $result_pdf_canonical );
	$job            = $real_job_query->find_job_for_actor( 42, $job_id );
	g4s03_assert( is_array( $job ), 'Q23 did not restore the result PDF job.' );
	g4s03_same( 'self_release', $job['authorization']['object']['relation'] ?? null, 'Q23 result relation drifted.' );
	g4s03_same( $result_pdf_snapshot->source_hash(), $job['authorization']['object']['revision'] ?? null, 'Q23 lost the exact release hash.' );
	g4s03_assert( str_contains( $job_database->last_sql, 'requested_by_user_id = %d' ) && in_array( 'result_pdf', $job_database->last_values, true ), 'Q23 result SQL lost actor or profile isolation.' );

	$artifact_row        = array_merge(
		$common,
		array(
			'job_state'                 => 'ready',
			'result_file_public_id'     => $file_id,
			'artifact_public_id'        => $artifact_id,
			'artifact_state'            => 'ready',
			'artifact_source_type'      => 'submission_release',
			'artifact_source_public_id' => $result_pdf_snapshot->source_id(),
			'artifact_source_hash'      => $result_pdf_snapshot->source_hash(),
			'expires_at_utc'            => $expires,
			'page_count'                => '2',
			'job_public_id'             => $job_id,
			'file_public_id'            => $file_id,
			'owner_type'                => 'pdf_artifact',
			'owner_public_id'           => $artifact_id,
			'purpose'                   => 'generated_pdf',
			'storage_key'               => $storage_key,
			'original_name'             => 'result-' . strtolower( $result_pdf_snapshot->source_id() ) . '.pdf',
			'mime_type'                 => 'application/pdf',
			'byte_size'                 => $byte_size,
			'sha256'                    => $sha256,
			'file_state'                => 'ready',
			'scan_state'                => 'clean',
			'file_expires_at_utc'       => $expires,
		)
	);
	$artifact_database   = new G4S03_Database( $artifact_row );
	$real_artifact_query = new WordPress_Export_Query( $artifact_database, $result_pdf_canonical );
	$artifact            = $real_artifact_query->find_artifact_for_actor( 42, $artifact_id );
	g4s03_assert( is_array( $artifact ), 'Q24 did not restore the result PDF artifact.' );
	g4s03_same( 'self_release', $artifact['authorization']['object']['relation'] ?? null, 'Q24 result relation drifted.' );
	g4s03_assert( str_contains( $artifact_database->last_sql, 'a.source_type = j.source_type' ), 'Q24 did not bind artifact and job source identity.' );

	$query         = new G4S03_Query( $job, $artifact );
	$policy        = new G4S03_Release_Policy( $context, $result_pdf_snapshot->source_hash(), $result_pdf_snapshot->source_state() );
	$files         = new G4S03_Private_Read( $pdf_path, $storage_key );
	$read_job      = new Read_Export_Job( $query, $policy, static fn (): string => '2026-08-24 12:30:00' );
	$read_artifact = new Read_Export_Artifact( $query, $files, $policy, static fn (): string => '2026-08-24 12:30:00' );
	$public_job    = $read_job->execute( 42, $job_id, 'g4-s03-job-current' );
	g4s03_same( 'ready', $public_job['state'], 'Current result Q23 state drifted.' );
	$download = $read_artifact->execute( 42, $artifact_id, 'g4-s03-download-current' );
	g4s03_same( 'result_pdf', $download->artifact_kind(), 'Q24 result profile was not activated.' );
	g4s03_same( 'application/pdf', $download->mime_type(), 'Q24 result MIME drifted.' );
	g4s03_same( Access_Policy_Request::SHELL_PUBLIC, $policy->last_request?->shell(), 'Q24 result download left PublicUI.' );
	g4s03_assert( in_array( Addon_Capabilities::PARTICIPATE, $policy->last_request?->required_capabilities() ?? array(), true ), 'Q24 result download lost participate.' );

	$reads                    = $files->reads;
	$policy->current_revision = hash( 'sha256', 'corrected-result-release' );
	$policy->current_state    = 'corrected';
	g4s03_failure( static fn () => $read_job->execute( 42, $job_id, 'g4-s03-job-corrected' ), Export_Failure::ACCESS_DENIED, 'Q23 exposed a superseded result PDF after correction.' );
	g4s03_failure( static fn () => $read_artifact->execute( 42, $artifact_id, 'g4-s03-download-corrected' ), Export_Failure::ACCESS_DENIED, 'Q24 exposed superseded bytes after correction.' );
	g4s03_same( $reads, $files->reads, 'Corrected Q24 read private bytes.' );

	$policy->current_revision = hash( 'sha256', 'withdrawn-result-release' );
	$policy->current_state    = 'withdrawn';
	g4s03_failure( static fn () => $read_artifact->execute( 42, $artifact_id, 'g4-s03-download-withdrawn' ), Export_Failure::ACCESS_DENIED, 'Q24 exposed bytes after result withdrawal.' );
	g4s03_same( $reads, $files->reads, 'Withdrawn Q24 read private bytes.' );

	$policy->current_revision = $result_pdf_snapshot->source_hash();
	$policy->current_state    = $result_pdf_snapshot->source_state();
	$policy->allowed          = false;
	g4s03_failure( static fn () => $read_artifact->execute( 42, $artifact_id, 'g4-s03-download-revoked' ), Export_Failure::ACCESS_DENIED, 'Q24 ignored current permission revocation.' );
	g4s03_same( $reads, $files->reads, 'Permission-revoked Q24 read private bytes.' );

	$policy->allowed          = true;
	$policy->context_revision = 'context-revision-revoked';
	g4s03_failure( static fn () => $read_artifact->execute( 42, $artifact_id, 'g4-s03-download-context' ), Export_Failure::ACCESS_DENIED, 'Q24 ignored current Context drift.' );
	g4s03_same( $reads, $files->reads, 'Context-drifted Q24 read private bytes.' );
	$policy->context_revision = (string) $context['revision'];

	g4s03_failure( static fn () => $read_artifact->execute( 84, $artifact_id, 'g4-s03-download-other' ), Export_Failure::NOT_FOUND, 'Q24 did not hide another actor result artifact.' );
	g4s03_same( $reads, $files->reads, 'Another actor Q24 read private bytes.' );
	g4s03_failure(
		static fn () => ( new Read_Export_Artifact( $query, $files, $policy, static fn (): string => $expires ) )->execute( 42, $artifact_id, 'g4-s03-download-expired' ),
		Export_Failure::EXPIRED,
		'Q24 exact expiry boundary did not close the artifact.'
	);
	g4s03_same( $reads, $files->reads, 'Expired Q24 read private bytes.' );

	$bad_mime              = $artifact_row;
	$bad_mime['mime_type'] = 'application/octet-stream';
	g4s03_failure(
		static fn () => ( new WordPress_Export_Query( new G4S03_Database( $bad_mime ), $result_pdf_canonical ) )->find_artifact_for_actor( 42, $artifact_id ),
		Export_Failure::STORED_DATA_INVALID,
		'Q24 accepted a non-PDF MIME.'
	);
	$bad_bytes              = $artifact;
	$bad_bytes['byte_size'] = $byte_size + 1;
	$byte_query             = new G4S03_Query( $job, $bad_bytes );
	g4s03_failure(
		static fn () => ( new Read_Export_Artifact( $byte_query, $files, $policy, static fn (): string => '2026-08-24 12:30:00' ) )->execute( 42, $artifact_id, 'g4-s03-download-size' ),
		Export_Failure::STORED_DATA_INVALID,
		'Q24 accepted a byte-size mismatch.'
	);
	$bad_hash           = $artifact;
	$bad_hash['sha256'] = hash( 'sha256', 'wrong-result-pdf' );
	$hash_query         = new G4S03_Query( $job, $bad_hash );
	g4s03_failure(
		static fn () => ( new Read_Export_Artifact( $hash_query, $files, $policy, static fn (): string => '2026-08-24 12:30:00' ) )->execute( 42, $artifact_id, 'g4-s03-download-hash' ),
		Export_Failure::STORAGE_UNAVAILABLE,
		'Q24 accepted a private-file hash mismatch.'
	);

	$controller_state = array();
	$controller       = g4s03_controller( $read_artifact, $controller_state );
	$response         = g4s03_response( $controller, $controller_state, $download, 42, $artifact_id, 'g4-s03-stream-current' );
	g4s03_assert( is_object( $response ), 'Result stream response token is unavailable.' );
	$calls = $policy->calls;
	ob_start();
	$served   = $controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$streamed = ob_get_clean();
	g4s03_same( true, $served, 'Current result stream was not served.' );
	g4s03_same( $original_bytes, $streamed, 'Current result stream bytes drifted.' );
	g4s03_same( $calls + 1, $policy->calls, 'Q24 did not reauthorize immediately before streaming.' );

	$download                 = $read_artifact->execute( 42, $artifact_id, 'g4-s03-stream-revoked-prepare' );
	$response                 = g4s03_response( $controller, $controller_state, $download, 42, $artifact_id, 'g4-s03-stream-revoked' );
	$policy->current_revision = hash( 'sha256', 'correction-during-stream-handoff' );
	$policy->current_state    = 'corrected';
	$reads                    = $files->reads;
	ob_start();
	$served   = $controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$streamed = ob_get_clean();
	g4s03_same( true, $served, 'Revoked result stream was not consumed fail-closed.' );
	g4s03_same( '', $streamed, 'Correction during stream handoff leaked result bytes.' );
	g4s03_same( $reads, $files->reads, 'Correction during stream handoff reached private storage.' );

	$policy->current_revision = $result_pdf_snapshot->source_hash();
	$policy->current_state    = $result_pdf_snapshot->source_state();
	$files->verify_hash       = false;
	$download                 = $read_artifact->execute( 42, $artifact_id, 'g4-s03-stream-tamper-prepare' );
	$response                 = g4s03_response( $controller, $controller_state, $download, 42, $artifact_id, 'g4-s03-stream-tamper' );
	file_put_contents( $pdf_path, $tampered_bytes, LOCK_EX );
	ob_start();
	$served   = $controller->serve_stream( false, $response, new stdClass(), new stdClass() );
	$streamed = ob_get_clean();
	g4s03_same( true, $served, 'Tampered result stream was not consumed fail-closed.' );
	g4s03_same( '', $streamed, 'Same-size hash tamper leaked result bytes.' );

	$runtime_files = array(
		$addon_root . '/src/Reporting/Export/Application/Export_Download.php',
		$addon_root . '/src/Reporting/Export/Application/Read_Export_Job.php',
		$addon_root . '/src/Reporting/Export/Application/Read_Export_Artifact.php',
		$addon_root . '/src/Reporting/Export/Application/Export_Access_Guard.php',
		$addon_root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$addon_root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
		$addon_root . '/src/Submission/Assessment/Infrastructure/Submission_Result_Object_Authorization.php',
	);
	foreach ( $runtime_files as $runtime_file ) {
		$runtime_source = (string) file_get_contents( $runtime_file );
		g4s03_assert( ! str_contains( $runtime_source, '$wpdb->prefix' ) && ! str_contains( $runtime_source, 'wp_oj_ai_' ), 'S03 runtime used a forbidden table prefix.' );
		g4s03_assert( ! str_contains( $runtime_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S03 runtime depends on a platform internal namespace.' );
	}
	$download_source = (string) file_get_contents( $runtime_files[0] );
	g4s03_assert( str_contains( $download_source, "'result_pdf'" ) && str_contains( $download_source, "'application/pdf'" ), 'Result PDF download profile is missing.' );
	$controller_source = (string) file_get_contents( $runtime_files[5] );
	foreach ( array( "'self_release'", 'read_artifact->execute', 'same_download', 'verified_handle', 'LOCK_SH', "hash_init( 'sha256' )" ) as $marker ) {
		g4s03_assert( str_contains( $controller_source, $marker ), 'Pre-stream result authorization marker is missing: ' . $marker );
	}
} finally {
	if ( is_string( $pdf_path ) && is_file( $pdf_path ) ) {
		@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
}

echo "G4-IMP-07-S03 passed: current release Q23/Q24, correction and withdrawal revocation, exact expiry, other actor isolation, MIME/hash/bytes integrity, self_release PublicUI, and pre-stream reauthorization are frozen.\n";
