<?php
/**
 * G3-IMP-08-S03 C67 enqueue, own snapshot, and worker contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-pdf-worker-g3-imp-08-s01.php';
ob_end_clean();

use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Own_Submission_Pdf_Command;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Result;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Run_Next_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Run_Submission_Pdf_Result;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Own_Submission_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s03_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3s03 pdf failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s03_pdf_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Pdf_Failure $failure ) {
		g3s03_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G3-IMP-08-S03 C67 enqueue, own snapshot, and worker contract.
 */
final class G3S03_Own_Source implements Own_Submission_Pdf_Source_Query_Port_Interface {
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
	 * Initialize g3 s03 own source dependencies and state.
	 *
	 * @param array<string,mixed> $value Value.
	 */
	public function __construct( public array $value ) {
	}
	/**
	 * Find current official.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $submission_id Submission public identifier.
	 * @return array|null
	 */
	public function find_current_official( int $actor_user_id, string $submission_id ): ?array {
		++$this->calls;
		if ( 42 !== $actor_user_id || $submission_id !== $this->value['submission_id'] || $this->calls === $this->deny_on_call ) {
			return null;
		}
		return $this->value;
	}
}

/**
 * G3-IMP-08-S03 C67 enqueue, own snapshot, and worker contract.
 */
final class G3S03_Enqueue_Repository implements Submission_Pdf_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Submission_Pdf_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Submission_Pdf_Result {
		unset( $command_storage_key, $request_digest );
		return null;
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Submission_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Submission_Pdf_Result {
		$this->record = $record;
		return new Enqueue_Submission_Pdf_Result(
			(string) $record['job_public_id'],
			'queued',
			(string) $record['source_public_id'],
			(string) $record['source_hash'],
			(string) $record['submission_public_id'],
			(string) $record['queued_at_utc'],
			false
		);
	}
}

/**
 * G3-IMP-08-S03 C67 enqueue, own snapshot, and worker contract.
 */
final class G3S03_Queue implements Submission_Pdf_Queue_Wakeup_Port_Interface {
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
 * G3-IMP-08-S03 C67 enqueue, own snapshot, and worker contract.
 */
final class G3S03_Self_Policy implements Access_Policy_Port_Interface {
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
		g3s03_same( Access_Policy_Request::SHELL_PUBLIC, $request->shell(), 'C67 worker must use the public shell.' );
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'class-r3', 'member', $request->correlation_id() ),
			Object_Authorization_Result::allowed( '2', 'submitted', 'self_submission' )
		);
	}
}

$own_descriptor     = array(
	'assignment_id'           => $assignment_id,
	'assignment_state'        => 'open',
	'attachment_set_hash'     => $attachment_set_hash,
	'context_type'            => 'class',
	'context_id'              => 'class-21',
	'context_revision'        => 'class-r3',
	'official_revision_id'    => $submission_revision_id,
	'official_revision_hash'  => $revision_hash,
	'publication_id'          => $publication_id,
	'publication_hash'        => $publication_hash,
	'submission_id'           => $submission_id,
	'submission_lock_version' => 2,
	'submission_state'        => 'submitted',
	'target_id'               => $target_id,
	'target_state'            => 'active',
);
$own_source         = new G3S03_Own_Source( $own_descriptor );
$enqueue_repository = new G3S03_Enqueue_Repository();
$queue              = new G3S03_Queue();
$service            = new Enqueue_Own_Submission_Pdf(
	$own_source,
	$builder,
	$enqueue_repository,
	$queue,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 10:30:00',
	static fn (): string => 'Asia/Seoul'
);
$command_values     = array(
	42,
	$submission_id,
	'command-g3-s03-0001',
	$assignment_id,
	'open',
	$submission_revision_id,
	$revision_hash,
	$attachment_set_hash,
	$publication_id,
	$publication_hash,
	2,
	'submitted',
	'class-r3',
	'active',
	'teacher_submission',
	hash( 'sha256', 'g3-s03-idempotency' ),
	'g3-s03-correlation',
	'2',
	'submitted',
	'self_submission',
	'class',
	'class-21',
	'class-r3',
	'member',
	$canonical,
);
$command            = new Enqueue_Own_Submission_Pdf_Command( ...$command_values );
$enqueue_result     = $service->execute( $command );
g3s03_same( 'queued', $enqueue_result->state(), 'Valid C67 command must enqueue.' );
g3s03_same( 1, $queue->calls, 'C67 enqueue must wake the shared submission PDF queue once.' );
g3s03_assert( is_array( $enqueue_repository->record ), 'C67 enqueue record is missing.' );
$snapshot = Submission_Pdf_Request_Snapshot::restore(
	(string) $enqueue_repository->record['snapshot_json'],
	(string) $enqueue_repository->record['snapshot_hash'],
	$canonical
);
g3s03_same( Submission_Pdf_Request_Snapshot::OWN_VERSION, $snapshot->version(), 'C67 snapshot version drifted.' );
g3s03_same( $assignment_id, $snapshot->ownership()['assignment_id'] ?? null, 'C67 snapshot lost assignment ownership.' );
g3s03_same( $target_id, $snapshot->ownership()['target_id'] ?? null, 'C67 snapshot lost target ownership.' );

$bad_relation     = $command_values;
$bad_relation[19] = 'context_staff';
try {
	new Enqueue_Own_Submission_Pdf_Command( ...$bad_relation ); } catch ( InvalidArgumentException ) {
	$bad_relation = null;
	}
	g3s03_same( null, $bad_relation, 'C67 must reject non-self object authorization evidence.' );

	$stale_source  = new G3S03_Own_Source( array_replace( $own_descriptor, array( 'official_revision_hash' => hash( 'sha256', 'stale' ) ) ) );
	$stale_service = new Enqueue_Own_Submission_Pdf(
		$stale_source,
		$builder,
		new G3S03_Enqueue_Repository(),
		new G3S03_Queue(),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => '2026-08-23 10:30:00',
		static fn (): string => 'Asia/Seoul'
);
	g3s03_pdf_failure( static fn () => $stale_service->execute( $command ), Submission_Pdf_Failure::SOURCE_STALE, 'C67 must reject stale official revision evidence.' );

	$work_repository = new G3S01E_Work_Repository( $snapshot, g3s01_id( 180 ), 42 );
	$renderer        = new G3S01E_Renderer();
	$files           = new G3S01E_Private_Files();
	$policy          = new G3S03_Self_Policy();
	$worker_source   = new G3S03_Own_Source( $own_descriptor );
	$worker          = new Run_Next_Submission_Pdf(
		$work_repository,
		$builder,
		new Submission_Print_Html_Serializer( new Canonical_Json() ),
		$renderer,
		$files,
		$policy,
		new Public_Id_Generator(),
		new Canonical_Json(),
		static fn (): string => '2026-08-23 10:31:00',
		static fn (): string => '12345678-1234-4234-8234-123456789abc',
		$worker_source
	);
	g3s03_same( Run_Submission_Pdf_Result::READY, $worker->run_once()->status(), 'Valid C67 worker item must become ready.' );
	g3s03_same( 2, $policy->calls, 'C67 worker must authorize twice.' );
	g3s03_same( 2, $worker_source->calls, 'C67 worker must reread current ownership twice.' );
	g3s03_same( 1, $renderer->calls, 'C67 worker must render once.' );
	g3s03_same( 1, $files->puts, 'C67 worker must persist one private PDF.' );

	$revoked_repository           = new G3S01E_Work_Repository( $snapshot, g3s01_id( 181 ), 42 );
	$revoked_renderer             = new G3S01E_Renderer();
	$revoked_files                = new G3S01E_Private_Files();
	$revoked_source               = new G3S03_Own_Source( $own_descriptor );
	$revoked_source->deny_on_call = 2;
	$revoked_worker               = new Run_Next_Submission_Pdf(
		$revoked_repository,
		$builder,
		new Submission_Print_Html_Serializer( new Canonical_Json() ),
		$revoked_renderer,
		$revoked_files,
		new G3S03_Self_Policy(),
		new Public_Id_Generator(),
		new Canonical_Json(),
		static fn (): string => '2026-08-23 10:31:00',
		static fn (): string => '12345678-1234-4234-8234-123456789abc',
		$revoked_source
	);
	g3s03_same( Run_Submission_Pdf_Result::FAILED, $revoked_worker->run_once()->status(), 'Revoked C67 ownership must fail.' );
	g3s03_same( Submission_Pdf_Failure::SOURCE_STALE, $revoked_repository->failure['error_code'] ?? null, 'Revoked C67 source failure code drifted.' );
	g3s03_same( 1, $revoked_renderer->calls, 'Second source check must occur after rendering.' );
	g3s03_same( 0, $revoked_files->puts, 'Revoked C67 ownership persisted private bytes.' );

	foreach ( array(
		$addon_root . '/src/Reporting/SubmissionPdf/Application/Enqueue_Own_Submission_Pdf.php',
		$addon_root . '/src/Reporting/SubmissionPdf/Application/Run_Next_Submission_Pdf.php',
		$addon_root . '/src/Submission/Student/Infrastructure/WordPress_Own_Submission_Pdf_Source_Query.php',
		$addon_root . '/src/Core/Composition_Root.php',
	) as $runtime_file ) {
		$runtime_source = (string) file_get_contents( $runtime_file );
		g3s03_assert( ! str_contains( $runtime_source, '$wpdb->prefix' ) && ! str_contains( $runtime_source, 'wp_oj_ai_' ), 'C67 runtime used a forbidden table prefix.' );
		g3s03_assert( ! str_contains( $runtime_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C67 runtime depends on a platform internal namespace.' );
	}
	$literal_source = (string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Own_Submission_Pdf_Source_Query.php' );
	foreach ( array( "'oj_ai_assignments'", "'oj_ai_assignment_targets'", "'oj_ai_submissions'", 's.learner_user_id = %d', 'find_official_revision' ) as $marker ) {
		g3s03_assert( str_contains( $literal_source, $marker ), 'C67 self source marker is missing: ' . $marker );
	}

	echo "G3-IMP-08-S03 enqueue/worker passed: strict C67, current official source, own snapshot 1.1, double self authorization, and private persistence revocation.\n";
