<?php
/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Reporting\Export\Application\Export_Access_Guard;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Own_Result_Pdf;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Command;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Result;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Result_Pdf_Failure;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Run_Next_Result_Pdf;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Run_Result_Pdf_Result;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Result_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Result_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Result_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\ResultPdf\Presentation\Result_Pdf_Rest_Controller;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

ob_start();
require __DIR__ . '/result-print-projection-g4-imp-07-s01.php';
ob_end_clean();

/**
 * G4 result pdf assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_pdf_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G4 result pdf same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_pdf_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G4 result pdf failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_result_pdf_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Result_Pdf_Failure $failure ) {
		g4_result_pdf_same( $reason, $failure->reason(), $message );
		return;
	} throw new RuntimeException( $message );
}

/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Enqueue_Repository implements Result_Pdf_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Result.
	 *
	 * @var Enqueue_Result_Pdf_Result|null
	 */
	private ?Enqueue_Result_Pdf_Result $result = null;
	/**
	 * Key.
	 *
	 * @var string|null
	 */
	private ?string $key = null;
	/**
	 * Digest.
	 *
	 * @var string|null
	 */
	private ?string $digest = null;
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Result_Pdf_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Result_Pdf_Result {
		if ( null === $this->result ) {
			return null;
		}
		if ( $this->key !== $command_storage_key || $this->digest !== $request_digest ) {
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::IDEMPOTENCY_CONFLICT );
		}
		return new Enqueue_Result_Pdf_Result( $this->result->job_id(), $this->result->state(), $this->result->release_id(), $this->result->release_hash(), $this->result->queued_at_utc(), true );
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Result_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Result_Pdf_Result {
		$this->record = $record;
		$this->key    = (string) $record['command_storage_key'];
		$this->digest = (string) $record['request_digest'];
		$this->result = new Enqueue_Result_Pdf_Result( (string) $record['job_public_id'], 'queued', (string) $record['source_public_id'], (string) $record['source_hash'], (string) $record['queued_at_utc'], false );
		return $this->result;
	}
}

/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Queue implements Result_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->calls;
	}
}

/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Work_Repository implements Result_Pdf_Work_Repository_Port_Interface {
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
	 * Claimed.
	 *
	 * @var bool
	 */
	private bool $claimed = false;
	/**
	 * Initialize g4 result pdf work repository dependencies and state.
	 *
	 * @param Result_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @param string                      $job_id Job id.
	 * @param int                         $actor_user_id Authenticated actor from the access context.
	 */
	public function __construct( private Result_Pdf_Request_Snapshot $snapshot, private string $job_id, private int $actor_user_id = 42 ) {
	}
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Result_Pdf_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Result_Pdf_Work_Item {
		unset( $now_utc, $lease_expires_at_utc );
		if ( $this->claimed ) {
			return null;
		} $this->claimed = true;
		$context         = $this->snapshot->context();
		return new Result_Pdf_Work_Item( 1, $this->job_id, 1, $this->actor_user_id, (string) $context['type'], (string) $context['id'], (string) $context['revision'], $this->snapshot->source_id(), $this->snapshot->source_hash(), $this->snapshot->json(), $this->snapshot->hash(), $this->snapshot->hash(), $this->snapshot->profile_version(), '2026-08-24 12:00:00', $lease_token );
	}
	/**
	 * Complete ready.
	 *
	 * @param Result_Pdf_Work_Item $item Item.
	 * @param array                $file File.
	 * @param array                $artifact Artifact.
	 * @param string               $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_ready( Result_Pdf_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		$this->ready = compact( 'item', 'file', 'artifact', 'finished_at_utc' );
		return true;
	}
	/**
	 * Complete failure.
	 *
	 * @param Result_Pdf_Work_Item $item Item.
	 * @param string               $error_code Error code.
	 * @param string               $audit_public_id Audit public id.
	 * @param string               $outbox_public_id Outbox public id.
	 * @param string               $correlation_id Request correlation identifier for diagnostics.
	 * @param string               $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure( Result_Pdf_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
		$this->failure = compact( 'item', 'error_code', 'audit_public_id', 'outbox_public_id', 'correlation_id', 'finished_at_utc' );
		return true;
	}
}

/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Renderer implements Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Html.
	 *
	 * @var string
	 */
	public string $html = '';
	/**
	 * Render g4 result pdf renderer.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result {
		++$this->calls;
		$this->html = $html;
		g4_result_pdf_assert( str_contains( $html, '점수' ) && ! str_contains( $html, '<script>' ), 'C52 serializer emitted unsafe or incomplete HTML.' );
		return new Worksheet_Pdf_Render_Result( "%PDF-1.7\nG4 result fixture\n%%EOF", 2, 'test-renderer-1' );
	}
}

/**
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Files implements Private_File_Store_Port_Interface {
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
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		++$this->puts;
		$bytes = file_get_contents( $source_path );
		g4_result_pdf_assert( is_string( $bytes ) && hash_equals( $sha256, hash( 'sha256', $bytes ) ), 'C52 private PDF hash drifted.' );
		$this->stored[ $storage_key ] = $bytes;
		return true;
	}
	/**
	 * Delete g4 result pdf files.
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
 * G4-IMP-07-S02 C52 enqueue, worker, artifact, and Q23/Q24 basis contract.
 */
final class G4_Result_Pdf_Policy implements Access_Policy_Port_Interface {
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
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize g4 result pdf policy dependencies and state.
	 *
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $release_hash Release hash.
	 * @param string $release_state Release state.
	 */
	public function __construct( private string $context_type, private string $context_id, private string $context_revision, private string $release_hash, private string $release_state ) {
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
		if ( $this->deny_on_call === $this->calls ) {
			return Access_Policy_Decision::denied( 403, 'result_pdf_access_revoked', $request->correlation_id() );
		}
		$object = $request->object();
		g4_result_pdf_assert( $object instanceof Object_Scope && Object_Scope::TYPE_RELEASE === $object->type() && 'export_pdf' === $object->action(), 'C52 policy object scope drifted.' );
		g4_result_pdf_assert( in_array( Addon_Capabilities::PARTICIPATE, $request->required_capabilities(), true ), 'C52 lost participate capability.' );
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( $this->context_type, $this->context_id, 'active', $this->context_revision, 'learner', $request->correlation_id() ),
			Object_Authorization_Result::allowed( $this->release_hash, $this->release_state, 'self_release' )
		);
	}
}

$result_pdf_canonical      = new Canonical_Json();
$result_pdf_context        = array(
	'type'     => (string) $q33_fixture['source']['context_type'],
	'id'       => (string) $q33_fixture['source']['context_id'],
	'revision' => (string) $q33_fixture['source']['context_revision'],
);
$result_pdf_repository     = new G4_Result_Pdf_Enqueue_Repository();
$result_pdf_queue          = new G4_Result_Pdf_Queue();
$result_pdf_enqueue        = new Enqueue_Own_Result_Pdf( $result_print_builder, $result_pdf_repository, $result_pdf_queue, new Public_Id_Generator(), $result_pdf_canonical, static fn (): string => '2026-08-24 12:00:00', static fn (): string => 'Asia/Seoul' );
$result_pdf_command_values = array(
	42,
	$q33_released->release_id(),
	'command-g4-result-pdf-0001',
	$q33_released->release_hash(),
	'released',
	'ko-KR',
	Build_Result_Print_Projection::PROFILE,
	hash( 'sha256', 'g4-result-pdf-idempotency' ),
	'g4-result-pdf-correlation',
	$q33_released->release_hash(),
	'released',
	'self_release',
	$result_pdf_context['type'],
	$result_pdf_context['id'],
	$result_pdf_context['revision'],
	'learner',
	$result_pdf_canonical,
);
$result_pdf_command        = new Enqueue_Result_Pdf_Command( ...$result_pdf_command_values );
$result_pdf_enqueued       = $result_pdf_enqueue->execute( $result_pdf_command );
g4_result_pdf_same( 'queued', $result_pdf_enqueued->state(), 'C52 must enqueue a result PDF job.' );
g4_result_pdf_same( 1, $result_pdf_queue->calls, 'C52 must wake its queue once.' );
g4_result_pdf_assert( is_array( $result_pdf_repository->record ), 'C52 enqueue record is missing.' );
$result_pdf_snapshot = Result_Pdf_Request_Snapshot::restore( (string) $result_pdf_repository->record['snapshot_json'], (string) $result_pdf_repository->record['snapshot_hash'], $result_pdf_canonical );
g4_result_pdf_same( Result_Pdf_Request_Snapshot::VERSION, $result_pdf_snapshot->value()['schema_version'], 'C52 snapshot version drifted.' );
g4_result_pdf_same( $q33_released->release_id(), $result_pdf_snapshot->source_id(), 'C52 snapshot lost the release.' );
g4_result_pdf_same( $result_pdf_canonical->encode( $result_pdf_context ), $result_pdf_canonical->encode( $result_pdf_snapshot->context() ), 'C52 snapshot lost the authorized Context.' );
g4_result_pdf_same( 'Asia/Seoul', $result_pdf_snapshot->timezone(), 'C52 snapshot did not pin timezone.' );
$result_pdf_replay = $result_pdf_enqueue->execute( $result_pdf_command );
g4_result_pdf_same( $result_pdf_enqueued->job_id(), $result_pdf_replay->job_id(), 'Exact C52 retry must replay the job.' );
g4_result_pdf_same( true, $result_pdf_replay->replayed(), 'Exact C52 retry must report replay.' );
g4_result_pdf_same( 1, $result_pdf_queue->calls, 'C52 replay must not wake the queue.' );

$bad_relation     = $result_pdf_command_values;
$bad_relation[11] = 'context_staff';
try {
	new Enqueue_Result_Pdf_Command( ...$bad_relation );
	$bad_relation = 'accepted';
} catch ( InvalidArgumentException ) {
	$bad_relation = null;
}
g4_result_pdf_same( null, $bad_relation, 'C52 must reject non-self release evidence.' );
$bad_state     = $result_pdf_command_values;
$bad_state[4]  = 'withdrawn';
$bad_state[10] = 'withdrawn';
try {
	new Enqueue_Result_Pdf_Command( ...$bad_state );
	$bad_state = 'accepted';
} catch ( InvalidArgumentException ) {
	$bad_state = null;
}
g4_result_pdf_same( null, $bad_state, 'C52 must reject withdrawn release evidence.' );

$result_pdf_job_id   = ( new Public_Id_Generator() )->create()->value();
$result_pdf_work     = new G4_Result_Pdf_Work_Repository( $result_pdf_snapshot, $result_pdf_job_id );
$result_pdf_renderer = new G4_Result_Pdf_Renderer();
$result_pdf_files    = new G4_Result_Pdf_Files();
$result_pdf_policy   = new G4_Result_Pdf_Policy( $result_pdf_context['type'], $result_pdf_context['id'], $result_pdf_context['revision'], $result_pdf_snapshot->source_hash(), $result_pdf_snapshot->source_state() );
$result_pdf_worker   = new Run_Next_Result_Pdf( $result_pdf_work, $result_print_builder, new Result_Print_Html_Serializer( $result_pdf_canonical ), $result_pdf_renderer, $result_pdf_files, $result_pdf_policy, new Public_Id_Generator(), $result_pdf_canonical, static fn (): string => '2026-08-24 12:01:00', static fn (): string => '12345678-1234-4234-8234-123456789abc' );
g4_result_pdf_same( Run_Result_Pdf_Result::READY, $result_pdf_worker->run_once()->status(), 'Authorized C52 worker must complete.' );
g4_result_pdf_same( 2, $result_pdf_policy->calls, 'C52 worker must authorize before and after render.' );
g4_result_pdf_same( 1, $result_pdf_renderer->calls, 'C52 worker rendered more than once.' );
g4_result_pdf_same( 1, $result_pdf_files->puts, 'C52 worker must persist one private PDF.' );
g4_result_pdf_same( 'result-' . strtolower( $q33_released->release_id() ) . '.pdf', $result_pdf_work->ready['file']['original_name'] ?? null, 'C52 result filename drifted.' );
g4_result_pdf_same( '2026-08-25 12:01:00', $result_pdf_work->ready['file']['expires_at_utc'] ?? null, 'C52 retention must be 24 hours.' );

$revoked_work                 = new G4_Result_Pdf_Work_Repository( $result_pdf_snapshot, ( new Public_Id_Generator() )->create()->value() );
$revoked_renderer             = new G4_Result_Pdf_Renderer();
$revoked_files                = new G4_Result_Pdf_Files();
$revoked_policy               = new G4_Result_Pdf_Policy( $result_pdf_context['type'], $result_pdf_context['id'], $result_pdf_context['revision'], $result_pdf_snapshot->source_hash(), $result_pdf_snapshot->source_state() );
$revoked_policy->deny_on_call = 2;
$revoked_worker               = new Run_Next_Result_Pdf( $revoked_work, $result_print_builder, new Result_Print_Html_Serializer( $result_pdf_canonical ), $revoked_renderer, $revoked_files, $revoked_policy, new Public_Id_Generator(), $result_pdf_canonical, static fn (): string => '2026-08-24 12:02:00', static fn (): string => '12345678-1234-4234-8234-123456789abc' );
g4_result_pdf_same( Run_Result_Pdf_Result::FAILED, $revoked_worker->run_once()->status(), 'Revoked C52 worker must fail.' );
g4_result_pdf_same( Result_Pdf_Failure::ACCESS_DENIED, $revoked_work->failure['error_code'] ?? null, 'C52 revocation failure code drifted.' );
g4_result_pdf_same( 1, $revoked_renderer->calls, 'C52 second authorization must occur after render.' );
g4_result_pdf_same( 0, $revoked_files->puts, 'Revoked C52 worker persisted private bytes.' );

$descriptor_method = new ReflectionMethod( WordPress_Export_Query::class, 'authorization' );
$descriptor        = $descriptor_method->invoke(
	new WordPress_Export_Query( null, $result_pdf_canonical ),
	array(
		'request_snapshot_json'    => $result_pdf_snapshot->json(),
		'request_snapshot_hash'    => $result_pdf_snapshot->hash(),
		'request_hash'             => $result_pdf_snapshot->hash(),
		'request_contract_version' => Result_Pdf_Request_Snapshot::VERSION,
		'request_snapshot_schema'  => Result_Pdf_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => Result_Pdf_Request_Snapshot::DATABASE_VERSION,
		'source_public_id'         => $result_pdf_snapshot->source_id(),
		'source_hash'              => $result_pdf_snapshot->source_hash(),
		'print_profile_version'    => $result_pdf_snapshot->profile_version(),
		'context_type'             => $result_pdf_context['type'],
		'context_id'               => $result_pdf_context['id'],
		'context_revision'         => $result_pdf_context['revision'],
	),
	'submission_release',
	'result_pdf'
);
g4_result_pdf_same( 'self_release', $descriptor['object']['relation'] ?? null, 'Q23/Q24 result descriptor relation drifted.' );
g4_result_pdf_same( $result_pdf_snapshot->source_hash(), $descriptor['object']['revision'] ?? null, 'Q23/Q24 descriptor lost exact release hash.' );
$guard_policy = new G4_Result_Pdf_Policy( $result_pdf_context['type'], $result_pdf_context['id'], $result_pdf_context['revision'], $result_pdf_snapshot->source_hash(), $result_pdf_snapshot->source_state() );
g4_result_pdf_same( true, ( new Export_Access_Guard( $guard_policy ) )->allows( 42, array( 'authorization' => $descriptor ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g4-result-pdf-q23' ), 'Q23/Q24 must accept the current self release descriptor.' );

$export_object_policy = new Submission_Result_Object_Authorization( $q33_query );
$export_object        = new Object_Scope( Object_Scope::TYPE_RELEASE, $q33_released->release_id(), 'export_pdf' );
$export_context       = Context_Authorization_Result::allowed( $result_pdf_context['type'], $result_pdf_context['id'], 'active', $result_pdf_context['revision'], 'learner' );
g4_result_pdf_same( true, $export_object_policy->authorize( 42, Result_Pdf_Rest_Controller::ACTION_ENQUEUE, $export_context, $export_object )->is_allowed(), 'Release object policy must authorize C52 for the owner.' );

$controller_reflection = new ReflectionClass( Result_Pdf_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input_method          = $controller_reflection->getMethod( 'input' );
$request               = new class( $q33_released ) {
	/**
	 * Initialize result-pdf-job-g4-imp-07-s02 dependencies and state.
	 *
	 * @param object $release Release.
	 */
	public function __construct( private object $release ) {
	}

	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return array(
			'command_id' => 'command-g4-result-pdf-rest',
			'expected'   => array(
				'expected_release_hash'  => $this->release->release_hash(),
				'expected_release_state' => 'released',
			),
			'payload'    => array(
				'locale'  => 'ko-KR',
				'profile' => 'student_result',
			),
		);
	}

};
$parsed                = $input_method->invoke( $controller, $request );
g4_result_pdf_same( $q33_released->release_hash(), $parsed['expected_release_hash'], 'C52 REST parser lost release hash.' );

$runtime_files  = array(
	$addon_root . '/src/Reporting/ResultPdf/Application/Enqueue_Own_Result_Pdf.php',
	$addon_root . '/src/Reporting/ResultPdf/Application/Run_Next_Result_Pdf.php',
	$addon_root . '/src/Reporting/ResultPdf/Domain/Result_Pdf_Request_Snapshot.php',
	$addon_root . '/src/Reporting/ResultPdf/Domain/Result_Print_Html_Serializer.php',
	$addon_root . '/src/Reporting/ResultPdf/Infrastructure/WordPress_Result_Pdf_Repository.php',
	$addon_root . '/src/Reporting/ResultPdf/Presentation/Result_Pdf_Rest_Controller.php',
	$addon_root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
$runtime_source = '';
foreach ( $runtime_files as $runtime_file ) {
	$runtime_source .= (string) file_get_contents( $runtime_file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_result_pdf_assert( ! str_contains( $runtime_source, $forbidden ), 'C52 runtime contains a forbidden dependency: ' . $forbidden );
}
foreach ( array( 'oj_ai_export_jobs', 'oj_ai_pdf_artifacts', 'oj_ai_audit_events', 'oj_ai_outbox_events', "'result_pdf'", "'submission_release'", 'START TRANSACTION', 'FOR UPDATE', 'active_identity_key' ) as $marker ) {
	g4_result_pdf_assert( Ojai_Php_Source_Contract::marker( $runtime_source, $marker ), 'C52 persistence marker is missing: ' . $marker );
}
foreach ( array( Result_Pdf_Rest_Controller::ROUTE_ENQUEUE, 'Enqueue_Result_Pdf_Command', "'artifact_kind' => 'result_pdf'", "'source_type' => 'submission_release'" ) as $marker ) {
	g4_result_pdf_assert( Ojai_Php_Source_Contract::marker( $runtime_source, $marker ), 'C52 REST marker is missing: ' . $marker );
}
g4_result_pdf_assert( str_contains( $runtime_source, 'new Result_Pdf_Rest_Module' ) && str_contains( $runtime_source, 'new Result_Pdf_Worker_Module' ), 'C52 Composition Root wiring is missing.' );

fwrite( STDOUT, "G4-IMP-07-S02 passed: strict C52, immutable result snapshot, idempotent queue, 300-second lease, allowlist HTML, private PDF, double current-release authorization, and Q23/Q24 self-release basis are frozen.\n" );
