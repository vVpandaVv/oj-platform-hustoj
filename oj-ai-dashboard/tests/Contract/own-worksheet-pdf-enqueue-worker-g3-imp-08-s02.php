<?php
/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Own_Worksheet_Pdf;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Own_Worksheet_Pdf_Command;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Run_Next_Worksheet_Pdf;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Run_Worksheet_Pdf_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Failure;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Own_Worksheet_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Own_Worksheet_Pdf_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/worksheet-print-projection-g2-imp-07-s01.php';

/**
 * G3s02a assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02a_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s02a same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02a_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3s02a pdf failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02a_pdf_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Pdf_Failure $failure ) {
		g3s02a_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G3s02a invalid.
 *
 * @param callable $callback Callback.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s02a_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Own_Source implements Own_Worksheet_Pdf_Source_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g3 s02 a own source dependencies and state.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	public function __construct( public ?array $row ) {
	}
	/**
	 * Find available.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 */
	public function find_available( int $actor_user_id, string $assignment_id, string $now_utc ): ?array {
		++$this->calls;
		if ( 42 !== $actor_user_id || $assignment_id !== ( $this->row['assignment_id'] ?? null ) ) {
			return null;
		}
		return $this->row;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Enqueue_Repository implements Worksheet_Pdf_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Key.
	 *
	 * @var string
	 */
	private string $key = '';
	/**
	 * Digest.
	 *
	 * @var string
	 */
	private string $digest = '';
	/**
	 * Receipt.
	 *
	 * @var Enqueue_Worksheet_Pdf_Result|null
	 */
	private ?Enqueue_Worksheet_Pdf_Result $receipt = null;

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Worksheet_Pdf_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Worksheet_Pdf_Result {
		if ( $this->key !== $command_storage_key ) {
			return null;
		}
		if ( ! hash_equals( $this->digest, $request_digest ) ) {
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::IDEMPOTENCY_CONFLICT );
		}
		if ( null === $this->receipt ) {
			return null;
		}
		return new Enqueue_Worksheet_Pdf_Result(
			$this->receipt->job_id(),
			$this->receipt->state(),
			$this->receipt->source_id(),
			$this->receipt->source_hash(),
			$this->receipt->queued_at_utc(),
			true
		);
	}

	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Worksheet_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Pdf_Result {
		$this->record  = $record;
		$this->key     = (string) $record['command_storage_key'];
		$this->digest  = (string) $record['request_digest'];
		$this->receipt = new Enqueue_Worksheet_Pdf_Result(
			(string) $record['job_public_id'],
			'queued',
			(string) $record['source_public_id'],
			(string) $record['source_hash'],
			(string) $record['queued_at_utc'],
			false
		);
		return $this->receipt;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Queue implements Worksheet_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Wakes.
	 *
	 * @var int
	 */
	public int $wakes = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->wakes;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Query_Database {
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
	 * Initialize g3 s02 a query database dependencies and state.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	public function __construct( public ?array $row ) {
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
	 * @return array|null
	 */
	public function get_row( string $sql, string $mode ): ?array {
		unset( $sql, $mode );
		return $this->row;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Work_Repository implements Worksheet_Pdf_Work_Repository_Port_Interface {
	/**
	 * Ready file.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $ready_file = null;
	/**
	 * Ready artifact.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $ready_artifact = null;
	/**
	 * Failure code.
	 *
	 * @var string|null
	 */
	public ?string $failure_code = null;
	/**
	 * Ready commit.
	 *
	 * @var bool
	 */
	public bool $ready_commit = true;
	/**
	 * Initialize g3 s02 a work repository dependencies and state.
	 *
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $job_id Job id.
	 */
	public function __construct( private Worksheet_Pdf_Request_Snapshot $snapshot, private string $job_id ) {
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Worksheet_Pdf_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Worksheet_Pdf_Work_Item {
		unset( $now_utc, $lease_expires_at_utc );
		$context = $this->snapshot->context();
		return new Worksheet_Pdf_Work_Item(
			1,
			$this->job_id,
			1,
			42,
			$this->snapshot->source_type(),
			$this->snapshot->source_id(),
			$this->snapshot->source_hash(),
			$context['type'] ?? null,
			$context['id'] ?? null,
			$context['revision'] ?? null,
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
	 * @param Worksheet_Pdf_Work_Item $item Item.
	 * @param array                   $file File.
	 * @param array                   $artifact Artifact.
	 * @param string                  $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_ready( Worksheet_Pdf_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool {
		unset( $item, $finished_at_utc );
		$this->ready_file     = $file;
		$this->ready_artifact = $artifact;
		return $this->ready_commit;
	}

	/**
	 * Complete failure.
	 *
	 * @param Worksheet_Pdf_Work_Item $item Item.
	 * @param string                  $error_code Error code.
	 * @param string                  $audit_public_id Audit public id.
	 * @param string                  $outbox_public_id Outbox public id.
	 * @param string                  $correlation_id Request correlation identifier for diagnostics.
	 * @param string                  $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure( Worksheet_Pdf_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool {
		unset( $item, $audit_public_id, $outbox_public_id, $correlation_id, $finished_at_utc );
		$this->failure_code = $error_code;
		return true;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Renderer implements Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Render g3 s02 a renderer.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result {
		++$this->calls;
		g3s02a_assert( str_contains( $html, 'answer-box' ), 'C51 worker lost the blank response area.' );
		return new Worksheet_Pdf_Render_Result( "%PDF-1.7\nC51 fixture\n%%EOF", 1, 'test-renderer-1' );
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Private_Files implements Private_File_Store_Port_Interface {
	/**
	 * Stored.
	 *
	 * @var array<int,string>
	 */
	public array $stored = array();
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
		g3s02a_assert( is_file( $source_path ) && hash_equals( $sha256, (string) hash_file( 'sha256', $source_path ) ), 'C51 private file hash drifted.' );
		$this->stored[] = $storage_key;
		return true;
	}
	/**
	 * Delete g3 s02 a private files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		$this->deleted[] = $storage_key;
	}
}

/**
 * G3-IMP-08-S02 C51 learner worksheet PDF enqueue and worker contract.
 */
final class G3S02A_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->requests[] = $request;
		$context          = $request->context();
		$object           = $request->object();
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( (string) $context?->type(), (string) $context?->id(), 'active', (string) $context?->expected_revision(), 'learner' ),
			Object_Authorization_Result::allowed( '4', 'open', 'self_target' )
		);
	}
}

$assignment_id      = g2s01_id( 90 );
$target_id          = g2s01_id( 91 );
$source_row         = array(
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
);
$own_sources        = new G3S02A_Own_Source( $source_row );
$enqueue_repository = new G3S02A_Enqueue_Repository();
$queue              = new G3S02A_Queue();
$enqueue            = new Enqueue_Own_Worksheet_Pdf(
	$own_sources,
	$builder,
	$enqueue_repository,
	$queue,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 10:00:00',
	static fn (): string => 'Asia/Seoul'
);
$idempotency        = hash( 'sha256', 'g3-s02-c51-idempotency' );
$command            = new Enqueue_Own_Worksheet_Pdf_Command(
	42,
	$assignment_id,
	'cmd-own-pdf-0001',
	4,
	'open',
	'class-r3',
	$publication_id,
	$publication_hash,
	'active',
	'student_blank',
	$idempotency,
	'corr-own-pdf-0001',
	'4',
	'open',
	'self_target',
	'class',
	'class-21',
	'class-r3',
	'learner',
	$canonical
);
$result             = $enqueue->execute( $command );
g3s02a_same( 'queued', $result->state(), 'C51 must enqueue a shared worksheet PDF job.' );
g3s02a_same( 1, $queue->wakes, 'C51 must wake the queue once after persistence.' );
g3s02a_assert( is_array( $enqueue_repository->record ), 'C51 did not persist a frozen request.' );
$snapshot = Worksheet_Pdf_Request_Snapshot::restore(
	(string) $enqueue_repository->record['snapshot_json'],
	(string) $enqueue_repository->record['snapshot_hash'],
	$canonical
);
g3s02a_same( Worksheet_Pdf_Request_Snapshot::OWN_VERSION, $snapshot->version(), 'C51 snapshot version drifted.' );
g3s02a_same( 'worksheet_publication', $snapshot->source_type(), 'C51 must pin an immutable publication.' );
g3s02a_same( $assignment_id, $snapshot->assignment()['id'] ?? null, 'C51 snapshot lost the assignment.' );
g3s02a_same(
	array(
		'id'       => 'class-21',
		'revision' => 'class-r3',
		'type'     => 'class',
	),
	$snapshot->context(),
	'C51 snapshot Context drifted.'
);
g3s02a_same( 'Asia/Seoul', $snapshot->value()['print']['timezone'], 'C51 snapshot timezone was not frozen.' );
foreach ( array( 'grading', 'example_answer', 'teacher_note', 'rubric_criterion_ids', 'learner_user_id' ) as $forbidden ) {
	g3s02a_assert( ! str_contains( $snapshot->json(), '"' . $forbidden . '"' ), 'C51 snapshot leaked ' . $forbidden . '.' );
}

$replay = $enqueue->execute( $command );
g3s02a_same( $result->job_id(), $replay->job_id(), 'Exact C51 retry must replay the original job.' );
g3s02a_same( true, $replay->replayed(), 'Exact C51 retry must report replay.' );
g3s02a_same( 1, $queue->wakes, 'C51 replay must not wake the queue.' );

$stale_context_command = new Enqueue_Own_Worksheet_Pdf_Command(
	42,
	$assignment_id,
	'cmd-own-pdf-0002',
	4,
	'open',
	'class-r4',
	$publication_id,
	$publication_hash,
	'active',
	'student_blank',
	hash( 'sha256', 'g3-s02-c51-stale' ),
	'corr-own-pdf-0002',
	'4',
	'open',
	'self_target',
	'class',
	'class-21',
	'class-r4',
	'learner',
	$canonical
);
g3s02a_pdf_failure( static fn () => $enqueue->execute( $stale_context_command ), Worksheet_Pdf_Failure::SOURCE_STALE, 'C51 must reject binding Context drift.' );
g3s02a_invalid(
	static fn () => new Enqueue_Own_Worksheet_Pdf_Command(
		42,
		$assignment_id,
		'cmd-own-pdf-0003',
		4,
		'open',
		'class-r3',
		$publication_id,
		$publication_hash,
		'active',
		'student_blank',
		hash( 'sha256', 'g3-s02-c51-owner' ),
		'corr-own-pdf-0003',
		'4',
		'open',
		'owner',
		'class',
		'class-21',
		'class-r3',
		'learner',
		$canonical
	),
	'C51 must reject an owner relation in the learner route.'
);

$database        = new G3S02A_Query_Database(
	array(
		'assignment_id'                   => $assignment_id,
		'assignment_lock_version'         => '4',
		'assignment_state'                => 'open',
		'context_type'                    => 'class',
		'context_id'                      => 'class-21',
		'context_revision'                => 'class-r3',
		'worksheet_publication_public_id' => $publication_id,
		'publication_hash'                => $publication_hash,
		'opens_at_utc'                    => '2026-08-23 09:00:00',
		'due_at_utc'                      => '2026-08-23 11:00:00',
		'closes_at_utc'                   => '2026-08-23 12:00:00',
		'target_id'                       => $target_id,
		'target_state'                    => 'active',
	)
);
$database_query  = new WordPress_Own_Worksheet_Pdf_Source_Query( $database );
$database_source = $database_query->find_available( 42, $assignment_id, '2026-08-23 10:00:00' );
g3s02a_same( $canonical->encode( $source_row ), $canonical->encode( $database_source ), 'Literal-table C51 source normalization drifted.' );
g3s02a_assert( str_contains( $database->last_sql, 'FROM oj_ai_assignments a' ) && str_contains( $database->last_sql, 'INNER JOIN oj_ai_assignment_targets t' ), 'C51 source query lost literal tables.' );
g3s02a_same( array( $assignment_id, 42 ), $database->last_values, 'C51 source query did not bind assignment and learner.' );
$database->row['target_state'] = 'revoked';
g3s02a_same( null, $database_query->find_available( 42, $assignment_id, '2026-08-23 10:00:00' ), 'Revoked C51 target must be hidden.' );

$work_sources    = new G3S02A_Own_Source( $source_row );
$work_repository = new G3S02A_Work_Repository( $snapshot, $result->job_id() );
$renderer        = new G3S02A_Renderer();
$files           = new G3S02A_Private_Files();
$policy          = new G3S02A_Policy();
$worker          = new Run_Next_Worksheet_Pdf(
	$work_repository,
	$builder,
	new Worksheet_Print_Html_Serializer(),
	$renderer,
	$files,
	$policy,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 10:00:05',
	static fn (): string => '12345678-1234-4234-8234-123456789abc',
	$work_sources
);
$worked          = $worker->run_once();
g3s02a_same( Run_Worksheet_Pdf_Result::READY, $worked->status(), 'Authorized C51 worker did not complete.' );
g3s02a_same( 2, $work_sources->calls, 'C51 worker must reread the learner source before and after render.' );
g3s02a_same( 2, $policy->calls, 'C51 worker must authorize before and after render.' );
g3s02a_same( 1, $renderer->calls, 'C51 worker rendered more than once.' );
g3s02a_same( 'worksheet-' . strtolower( $assignment_id ) . '.pdf', $work_repository->ready_file['original_name'] ?? null, 'C51 artifact filename must use the assignment ID.' );
foreach ( $policy->requests as $request ) {
	g3s02a_same( Access_Policy_Request::SHELL_PUBLIC, $request->shell(), 'C51 worker must use the public shell.' );
	g3s02a_same( Context_Scope::OPERATION_PARTICIPATE, $request->context()?->operation(), 'C51 worker must use participate Context.' );
	g3s02a_same( 42, $request->context()?->learner_user_id(), 'C51 worker must authorize the learner as Context subject.' );
	g3s02a_same( 'export_pdf', $request->object()?->action(), 'C51 worker object action drifted.' );
	g3s02a_assert( in_array( Addon_Capabilities::PARTICIPATE, $request->required_capabilities(), true ), 'C51 worker lost participate capability.' );
}

$revoked_sources    = new G3S02A_Own_Source( array_merge( $source_row, array( 'target_state' => 'revoked' ) ) );
$revoked_repository = new G3S02A_Work_Repository( $snapshot, g2s01_id( 92 ) );
$revoked_renderer   = new G3S02A_Renderer();
$revoked_worker     = new Run_Next_Worksheet_Pdf(
	$revoked_repository,
	$builder,
	new Worksheet_Print_Html_Serializer(),
	$revoked_renderer,
	new G3S02A_Private_Files(),
	new G3S02A_Policy(),
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 10:00:05',
	static fn (): string => '87654321-4321-4321-8321-cba987654321',
	$revoked_sources
);
g3s02a_same( Run_Worksheet_Pdf_Result::FAILED, $revoked_worker->run_once()->status(), 'Revoked C51 target must fail the worker.' );
g3s02a_same( Worksheet_Pdf_Failure::ACCESS_DENIED, $revoked_repository->failure_code, 'Revoked C51 worker failure code drifted.' );
g3s02a_same( 0, $revoked_renderer->calls, 'Revoked C51 target reached the renderer.' );

$runtime_files = array(
	$addon_root . '/src/Reporting/WorksheetPdf/Application/Enqueue_Own_Worksheet_Pdf.php',
	$addon_root . '/src/Reporting/WorksheetPdf/Application/Run_Next_Worksheet_Pdf.php',
	$addon_root . '/src/Submission/Student/Infrastructure/WordPress_Own_Worksheet_Pdf_Source_Query.php',
	$addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
foreach ( $runtime_files as $runtime_file ) {
	$source_code = (string) file_get_contents( $runtime_file );
	g3s02a_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'C51 used a forbidden table prefix: ' . $runtime_file );
	g3s02a_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C51 depends on OJ Platform internals: ' . $runtime_file );
}
$rest_source = (string) file_get_contents( $runtime_files[3] );
foreach ( array( '/commands/export-pdf', 'expected_context_revision', 'expected_publication_hash', 'expected_target_state', "'student_blank'" ) as $marker ) {
	g3s02a_assert( str_contains( $rest_source, $marker ), 'C51 REST marker is missing: ' . $marker );
}
$composition_source = (string) file_get_contents( $runtime_files[4] );
g3s02a_assert( str_contains( $composition_source, 'new Enqueue_Own_Worksheet_Pdf(' ) && str_contains( $composition_source, '$own_worksheet_pdf_source' ), 'C51 application wiring is missing.' );

echo "G3-IMP-08-S02-A passed: strict C51, immutable publication snapshot, self Context, semantic replay, literal source query, double worker reauthorization, revocation, and private artifact.\n";
