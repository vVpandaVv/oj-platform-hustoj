<?php
/**
 * G3-IMP-08-S01-D C49 enqueue and Context snapshot contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Command;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Enqueue_Submission_Pdf_Result;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Presentation\Submission_Pdf_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/submission-print-projection-g3-imp-08-s01.php';

/**
 * G3s01d assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01d_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s01d same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01d_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3s01d invalid.
 *
 * @param callable $callback Callback.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01d_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G3-IMP-08-S01-D C49 enqueue and Context snapshot contract.
 */
final class G3S01D_Repository implements Submission_Pdf_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Receipt.
	 *
	 * @var Enqueue_Submission_Pdf_Result|null
	 */
	private ?Enqueue_Submission_Pdf_Result $receipt = null;
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
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Enqueue_Submission_Pdf_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Enqueue_Submission_Pdf_Result {
		if ( $this->key !== $key ) {
			return null;
		}
		if ( ! hash_equals( $this->digest, $digest ) ) {
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::IDEMPOTENCY_CONFLICT );
		}
		if ( null === $this->receipt ) {
			return null;
		}
		return new Enqueue_Submission_Pdf_Result(
			$this->receipt->job_id(),
			$this->receipt->state(),
			$this->receipt->source_id(),
			$this->receipt->source_hash(),
			$this->receipt->submission_id(),
			$this->receipt->queued_at_utc(),
			true
		);
	}

	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Submission_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Submission_Pdf_Result {
		$this->record  = $record;
		$this->key     = (string) $record['command_storage_key'];
		$this->digest  = (string) $record['request_digest'];
		$this->receipt = new Enqueue_Submission_Pdf_Result(
			(string) $record['job_public_id'],
			'queued',
			(string) $record['source_public_id'],
			(string) $record['source_hash'],
			(string) $record['submission_public_id'],
			(string) $record['queued_at_utc'],
			false
		);
		return $this->receipt;
	}
}

/**
 * G3-IMP-08-S01-D C49 enqueue and Context snapshot contract.
 */
final class G3S01D_Queue implements Submission_Pdf_Queue_Wakeup_Port_Interface {
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
 * G3-IMP-08-S01-D C49 enqueue and Context snapshot contract.
 */
final class G3S01D_Request {
	/**
	 * Initialize g3 s01 d request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param array<string,mixed> $query Query.
	 */
	public function __construct( private array $body, private array $query ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

$enqueue_repository = new G3S01D_Repository();
$enqueue_queue      = new G3S01D_Queue();
$enqueue_ids        = new Public_Id_Generator();
$enqueue            = new Enqueue_Submission_Pdf(
	$builder,
	$enqueue_repository,
	$enqueue_queue,
	$enqueue_ids,
	$canonical,
	static fn (): string => '2026-08-23 10:00:00',
	static fn (): string => 'Asia/Seoul'
);
$idempotency        = hash( 'sha256', 'g3-s01-d-idempotency' );
$command            = new Enqueue_Submission_Pdf_Command(
	42,
	$submission_revision_id,
	'cmd-submission-pdf-0001',
	$submission_revision_id,
	$revision_hash,
	$publication_hash,
	$attachment_set_hash,
	$submission_id,
	'submitted',
	2,
	'class',
	'class-21',
	'class-r3',
	Build_Submission_Print_Projection::PROFILE,
	$idempotency,
	'corr-submission-pdf-0001',
	'2',
	'submitted',
	'context_staff',
	'class',
	'class-21',
	'class-r3',
	'educator',
	$canonical
);
$enqueue_result     = $enqueue->execute( $command );
g3s01d_same( 'queued', $enqueue_result->state(), 'C49 must enqueue a submission PDF job.' );
g3s01d_same( $submission_id, $enqueue_result->submission_id(), 'C49 receipt lost the submission root.' );
g3s01d_same( 1, $enqueue_queue->wakes, 'A committed C49 job must wake its dedicated queue once.' );
g3s01d_assert( is_array( $enqueue_repository->record ), 'C49 must persist a complete frozen request.' );

$record = $enqueue_repository->record;
g3s01d_same( 'class', $record['context_type'], 'C49 job must persist Context type.' );
g3s01d_same( 'class-21', $record['context_id'], 'C49 job must persist Context ID.' );
g3s01d_same( 'class-r3', $record['context_revision'], 'C49 job must persist exact Context revision.' );
g3s01d_same( 2, $record['submission_lock_version'], 'C49 job must pin the submission root lock version.' );
g3s01d_same( Build_Submission_Print_Projection::ARTIFACT_PROFILE_VERSION, $record['print_profile_version'], 'C49 print profile drifted.' );

$snapshot = Submission_Pdf_Request_Snapshot::restore( $record['snapshot_json'], $record['snapshot_hash'], $canonical );
g3s01d_same( $submission_revision_id, $snapshot->source_id(), 'Snapshot source revision drifted.' );
g3s01d_same( $revision_hash, $snapshot->source_hash(), 'Snapshot source hash drifted.' );
g3s01d_same( $attachment_set_hash, $snapshot->attachment_set_hash(), 'Snapshot attachment set drifted.' );
g3s01d_same(
	array(
		'id'       => 'class-21',
		'revision' => 'class-r3',
		'type'     => 'class',
	),
	$snapshot->context(),
	'Snapshot Context drifted.'
);
g3s01d_same(
	array(
		'id'           => $submission_id,
		'lock_version' => 2,
		'state'        => 'submitted',
	),
	$snapshot->submission(),
	'Snapshot submission evidence drifted.'
);
g3s01d_same( 'Asia/Seoul', $snapshot->value()['print']['timezone'], 'Snapshot timezone must be frozen.' );
g3s01d_assert( ! str_contains( $snapshot->json(), 'learner_user_id' ) && ! str_contains( $snapshot->json(), 'storage_key' ), 'C49 snapshot leaked a private identity or storage key.' );

$replay = $enqueue->execute( $command );
g3s01d_same( $enqueue_result->job_id(), $replay->job_id(), 'An exact C49 retry must replay the original job.' );
g3s01d_same( true, $replay->replayed(), 'An exact C49 retry must be marked as replayed.' );
g3s01d_same( 1, $enqueue_queue->wakes, 'A command receipt replay must not wake the queue again.' );

$conflicting_command = new Enqueue_Submission_Pdf_Command(
	42,
	$submission_revision_id,
	'cmd-submission-pdf-0002',
	$submission_revision_id,
	$revision_hash,
	$publication_hash,
	$attachment_set_hash,
	$submission_id,
	'submitted',
	2,
	'class',
	'class-21',
	'class-r3',
	Build_Submission_Print_Projection::PROFILE,
	$idempotency,
	'corr-submission-pdf-0002',
	'2',
	'submitted',
	'context_staff',
	'class',
	'class-21',
	'class-r3',
	'educator',
	$canonical
);
try {
	$enqueue->execute( $conflicting_command );
	throw new RuntimeException( 'A reused Idempotency-Key with another C49 digest must fail.' );
} catch ( Submission_Pdf_Failure $failure ) {
	g3s01d_same( Submission_Pdf_Failure::IDEMPOTENCY_CONFLICT, $failure->reason(), 'C49 idempotency conflict reason drifted.' );
}

g3s01d_invalid(
	static fn () => new Enqueue_Submission_Pdf_Command(
		42,
		$submission_revision_id,
		'cmd-submission-pdf-0003',
		$submission_revision_id,
		$revision_hash,
		$publication_hash,
		$attachment_set_hash,
		$submission_id,
		'submitted',
		2,
		'class',
		'class-21',
		'class-r3',
		Build_Submission_Print_Projection::PROFILE,
		hash( 'sha256', 'other' ),
		'corr-submission-pdf-0003',
		'2',
		'submitted',
		'owner',
		'class',
		'class-21',
		'class-r3',
		'educator',
		$canonical
	),
	'C49 must reject an authorization relation other than context_staff.'
);

$tampered                  = json_decode( $snapshot->json(), true, 128, JSON_THROW_ON_ERROR );
$tampered['context']['id'] = 'class-99';
$tampered_json             = $canonical->encode( $tampered );
g3s01d_invalid(
	static fn () => Submission_Pdf_Request_Snapshot::restore( $tampered_json, hash( 'sha256', $tampered_json ), $canonical ),
	'C49 snapshot must reject a Context that no longer matches the print projection.'
);

$body           = array(
	'command_id' => 'cmd-submission-pdf-0004',
	'expected'   => array(
		'expected_revision_id'             => $submission_revision_id,
		'expected_revision_hash'           => $revision_hash,
		'expected_publication_hash'        => $publication_hash,
		'expected_attachment_set_hash'     => $attachment_set_hash,
		'expected_submission_id'           => $submission_id,
		'expected_submission_state'        => 'submitted',
		'expected_submission_lock_version' => 2,
		'expected_context_revision'        => 'class-r3',
	),
	'payload'    => array( 'profile' => Build_Submission_Print_Projection::PROFILE ),
);
$query          = array(
	'context_type'     => 'class',
	'context_id'       => 'class-21',
	'context_revision' => 'class-r3',
);
$controller     = ( new ReflectionClass( Submission_Pdf_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method   = new ReflectionMethod( Submission_Pdf_Rest_Controller::class, 'input' );
$context_method = new ReflectionMethod( Submission_Pdf_Rest_Controller::class, 'context_query' );
$parsed         = $input_method->invoke( $controller, new G3S01D_Request( $body, $query ) );
g3s01d_same( $attachment_set_hash, $parsed['expected_attachment_set_hash'], 'C49 strict parser lost attachment evidence.' );
g3s01d_same( $query, $context_method->invoke( $controller, new G3S01D_Request( $body, $query ) ), 'C49 strict Context query drifted.' );
$extra_body          = $body;
$extra_body['debug'] = true;
g3s01d_invalid(
	static fn () => $input_method->invoke( $controller, new G3S01D_Request( $extra_body, $query ) ),
	'C49 strict body must reject unknown top-level fields.'
);
$extra_query                    = $query;
$extra_query['learner_user_id'] = '103';
g3s01d_invalid(
	static fn () => $context_method->invoke( $controller, new G3S01D_Request( $body, $extra_query ) ),
	'C49 Context query must reject private or unknown fields.'
);

$runtime_files = array(
	$addon_root . '/src/Reporting/SubmissionPdf/Infrastructure/WordPress_Submission_Pdf_Repository.php',
	$addon_root . '/src/Reporting/SubmissionPdf/Presentation/Submission_Pdf_Rest_Controller.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
foreach ( $runtime_files as $runtime_file ) {
	$source_code = (string) file_get_contents( $runtime_file );
	g3s01d_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'C49 runtime used a forbidden table prefix.' );
	g3s01d_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C49 runtime depends on OJ Platform internals.' );
}
$repository_source = (string) file_get_contents( $runtime_files[0] );
foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", "'artifact_kind' => 'submission_pdf'", "'source_type' => 'submission_revision'", "'context_type' => \$record['context_type']" ) as $marker ) {
	g3s01d_assert( Ojai_Php_Source_Contract::marker( $repository_source, $marker ), 'C49 repository marker is missing: ' . $marker );
}
$rest_source = (string) file_get_contents( $runtime_files[1] );
foreach ( array( '/submission-revisions/', 'expected_attachment_set_hash', 'expected_submission_lock_version', 'Context_Scope::OPERATION_REPORT', 'Addon_Capabilities::EXPORT_REPORTS', "'teacher_view'" ) as $marker ) {
	g3s01d_assert( str_contains( $rest_source, $marker ), 'C49 REST marker is missing: ' . $marker );
}
$composition_source = (string) file_get_contents( $runtime_files[2] );
g3s01d_assert( str_contains( $composition_source, 'new Submission_Pdf_Rest_Module( $submission_pdf_rest )' ), 'C49 REST module is not registered.' );

echo "G3-IMP-08-S01-D passed: strict C49 body, official hash CAS, exact Context snapshot, active semantic idempotency, literal tables, and dedicated queue wakeup.\n";
