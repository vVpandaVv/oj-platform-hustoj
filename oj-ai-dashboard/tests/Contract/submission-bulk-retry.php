<?php
/**
 * C30 routes failed submissions through the real C31 application.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations;
use OJAI\Dashboard\Submission\Assessment\Application\Retry_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;

require __DIR__ . '/submission-bulk-grading.php';

/** Test-only durable C31 repository. */
final class Bulk_Retry_Controls implements Submission_Evaluation_Control_Repository_Port_Interface {

	/**
	 * Current evaluation.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot = array();
	/**
	 * Append-only runs.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Child receipts.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $receipts = array();

	/**
	 * Look up a child receipt independently of the mutable evaluation state.
	 *
	 * @param string $key Storage key.
	 * @param string $digest Request digest.
	 * @param string $operation Operation.
	 * @return Submission_Evaluation_Control_Result|null
	 * @throws Submission_Evaluation_Failure Rejects conflicting child requests.
	 */
	public function find_command_receipt( string $key, string $digest, string $operation ): ?Submission_Evaluation_Control_Result {
		$record = $this->receipts[ $key ] ?? null;
		if ( null === $record ) {
			return null;
		}
		if ( $record['request_digest'] !== $digest || 'retry' !== $operation ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->result( $record, true );
	}

	/**
	 * Read the current source.
	 *
	 * @param string $evaluation_id Evaluation.
	 * @return array<string,mixed>|null
	 */
	public function find_snapshot( string $evaluation_id ): ?array {
		return $evaluation_id === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
	}

	/**
	 * Append a run without replacing earlier records.
	 *
	 * @param array<string,mixed> $record Run.
	 * @return Submission_Evaluation_Control_Result
	 */
	public function retry( array $record ): Submission_Evaluation_Control_Result {
		$this->records[]                                  = $record;
		$this->receipts[ $record['command_storage_key'] ] = $record;
		$this->snapshot['evaluation_state']               = 'queued';
		$this->snapshot['latest_run_state']               = 'queued';
		$this->snapshot['latest_run_id']                  = $record['run_public_id'];
		$this->snapshot['latest_run_no']                  = $record['run_no'];
		return $this->result( $record, false );
	}

	/**
	 * Cancellation is outside bulk grading.
	 *
	 * @param array<string,mixed> $record Unused command.
	 * @return Submission_Evaluation_Control_Result
	 * @throws RuntimeException Rejects unexpected cancellation.
	 */
	public function cancel( array $record ): Submission_Evaluation_Control_Result {
		throw new RuntimeException( 'Bulk grading must not cancel.' );
	}

	/**
	 * Build the production receipt type.
	 *
	 * @param array<string,mixed> $record Run.
	 * @param bool                $replayed Replay flag.
	 * @return Submission_Evaluation_Control_Result
	 */
	private function result( array $record, bool $replayed ): Submission_Evaluation_Control_Result {
		return new Submission_Evaluation_Control_Result(
			'retry',
			$record['evaluation_public_id'],
			'queued',
			$record['submission_public_id'],
			$record['submission_revision_public_id'],
			$record['submission_revision_hash'],
			$record['worksheet_publication_public_id'],
			$record['publication_hash'],
			$record['run_public_id'],
			$record['run_no'],
			'queued',
			$record['expected_latest_result_id'],
			$record['occurred_at_utc'],
			$replayed
		);
	}
}

$failed_evaluation_id = $next_id();
$failed_run_id        = $next_id();
$controls             = new Bulk_Retry_Controls();
$controls->snapshot   = array(
	'evaluation_id'              => $failed_evaluation_id,
	'evaluation_state'           => 'failed',
	'evaluation_lock_version'    => 3,
	'submission_id'              => $items[0]['submission_id'],
	'submission_revision_id'     => $items[0]['official_revision_id'],
	'source_hash'                => $items[0]['official_revision_hash'],
	'publication_id'             => $publication_id,
	'publication_hash'           => $hash( 'publication' ),
	'context_type'               => 'class',
	'context_id'                 => $context_id,
	'context_revision'           => $context_revision,
	'input_hash'                 => $hash( 'input' ),
	'evaluation_identity_digest' => $hash( 'identity' ),
	'latest_run_id'              => $failed_run_id,
	'latest_run_no'              => 2,
	'latest_run_state'           => 'failed',
	'latest_run_lock_version'    => 4,
	'latest_result_id'           => null,
	'result_count'               => 0,
	'source_is_current'          => true,
);
$original_snapshot    = $controls->snapshot;
$retry_queue          = new class() implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Wakeups.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/** Wake the queue. */
	public function wake(): void {
		++$this->calls;
	}
};
$retry_route          = new G4_S03_Routing_Port( new Submission_Evaluation_Routing_Snapshot( OJAI\Dashboard\Platform\Addon_Manifest::TASK_STUDENT_ASSESSMENT, 'provider-test', 'model-test', $hash( 'profile' ), 'route-test' ) );
$retry                = new Retry_Submission_Evaluation( $controls, $retry_route, $retry_queue, $id_generator, $canonical, static fn (): string => '2026-08-23 02:00:00' );
$retry_page           = $guard_page;
$retry_page['items'][0]['failed_evaluation_id'] = $failed_evaluation_id;
$retry_page['items'][]                          = array_merge( $items[2], array( 'has_evaluation' => true ) );
$retry_source                                   = new G4_S04_Source_Port( $retry_page );
$retry_receipts                                 = new G4_S04_Receipt_Port();
$retry_enqueuer                                 = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $id_generator );
$retry_batch                                    = new Enqueue_Assignment_Evaluations( $retry_source, $retry_enqueuer, $retry_receipts, $id_generator, $canonical, static fn (): string => '2026-08-23 02:00:00', $retry, $controls );
$retry_command                                  = g4_s04_command( $retry_page, $canonical, $hash( 'bulk-retry' ) );
$retry_result                                   = $retry_batch->execute( $retry_command );
g4_s04_same( 2, $retry_result->counts()['created'], 'One failed retry plus one new submission must be queued.' );
g4_s04_same( 1, $retry_result->counts()['skipped'], 'Protected evaluation must stay excluded.' );
g4_s04_same( 1, count( $retry_enqueuer->commands ), 'Failed evaluation must not create a second evaluation through C29.' );
g4_s04_same( $failed_evaluation_id, $retry_result->items()[0]['evaluation_id'], 'Retry keeps the evaluation identity.' );
g4_s04_same( 3, $controls->records[0]['run_no'], 'Retry appends the next run.' );
g4_s04_same( $failed_run_id, $controls->records[0]['expected_run_public_id'], 'Retry is conditional on the failed run.' );
g4_s04_same( 1, $retry_queue->calls, 'Only the new retry wakes the queue.' );
g4_s04_same( true, $retry_batch->execute( $retry_command )->replayed(), 'Uncertain delivery replays the page receipt.' );
g4_s04_same( 1, count( $controls->records ), 'Page replay must not append another run.' );

// Even if the page receipt was lost and the retry failed quickly, the child key is stable.
$retry_receipts->rows                   = array();
$controls->snapshot['evaluation_state'] = 'failed';
$controls->snapshot['latest_run_state'] = 'failed';
$retry_result                           = $retry_batch->execute( $retry_command );
g4_s04_same( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, $retry_result->items()[0]['reason'], 'An already-used child command must not retry a newer failed run.' );
g4_s04_same( 1, count( $controls->records ), 'Receipt loss must not loop retries.' );

foreach ( array( 'queued', 'running', 'review_required', 'reviewed', 'cancelled' ) as $protected_state ) {
	$controls->snapshot = array_merge( $original_snapshot, array( 'evaluation_state' => $protected_state ) );
	$result             = $retry_batch->execute( g4_s04_command( $retry_page, $canonical, $hash( 'race-' . $protected_state ) ) );
	g4_s04_same( Submission_Evaluation_Failure::SOURCE_STALE, $result->items()[0]['reason'], 'Changed state after page read must block retry.' );
}
foreach ( array(
	'source_is_current'      => false,
	'context_id'             => 'another-class',
	'submission_revision_id' => $next_id(),
	'source_hash'            => $hash( 'another-source' ),
) as $field => $value ) {
	$controls->snapshot = array_merge( $original_snapshot, array( $field => $value ) );
	$result             = $retry_batch->execute( g4_s04_command( $retry_page, $canonical, $hash( 'scope-' . $field ) ) );
	g4_s04_same( 'failed', $result->items()[0]['status'], 'Stale or differently scoped source must never enter the retry queue.' );
}
g4_s04_same( 1, count( $controls->records ), 'Every stale, protected and out-of-scope case must preserve runs.' );
fwrite( STDOUT, "PASS bulk retry: real C30/C31 integration, same evaluation/next run, mixed batch, durable replay, receipt-loss guard, state races and source/context checks.\n" );
