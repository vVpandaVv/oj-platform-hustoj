<?php
/**
 * C35 feedback source, queue, lease, and immutable draft repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C35 feedback source, queue, lease, and immutable draft repository.
 */
final class WordPress_Submission_Feedback_Repository implements Submission_Feedback_Repository_Port_Interface, Submission_Feedback_Work_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results';
	private const TABLE_FEEDBACK    = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const ENQUEUE_EVENT           = 'submission_feedback.queued';
	private const RECEIPT_SCHEMA          = 'oj_ai.submission_feedback_enqueue_receipt';
	private const FEEDBACK_PROFILE_SCHEMA = 'oj_ai.submission_feedback_result';
	private const AUDIT_SCHEMA            = 'oj_ai.submission_feedback_audit';
	private const OUTBOX_SCHEMA           = 'oj_ai.submission_feedback_event';
	private const USAGE_SCHEMA            = 'oj_ai.ai_usage';
	private const PROFILE_VERSION         = 1;

	/**
	 * Initialize WordPress submission feedback repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Generate_Submission_Feedback_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Generate_Submission_Feedback_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Find owned source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_source( int $actor_user_id, string $evaluation_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Submission feedback actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		return $this->source( $evaluation_id, false );
	}

	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Generate_Submission_Feedback_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Generate_Submission_Feedback_Result {
		$this->assert_enqueue_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Generate_Submission_Feedback_Result ) {
			return $receipt;
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Generate_Submission_Feedback_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}
			$source = $this->source( $record['evaluation_public_id'], true );
			if ( null === $source ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_enqueue_source( $source, $record );
			$semantic      = $this->semantic_run( $record['run_idempotency_key'], true );
			$semantic_dead = null !== $semantic && $this->is_dead_semantic_match( $semantic );
			if ( null !== $semantic && ! $semantic_dead ) {
				$result = $this->result_from_run( $source, $semantic, true );
				$this->insert_enqueue_outbox( $record, $result );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $result;
			}
			$run_no = $this->non_negative_integer( $this->scalar( $this->prepare( 'SELECT COALESCE(MAX(run_no),0) FROM ' . self::TABLE_RUNS . ' WHERE subject_type = %s AND evaluation_public_id = %s', array( 'submission_evaluation', $record['evaluation_public_id'] ) ) ) ) + 1;
			if ( $semantic_dead ) {
				// The prior attempt for this exact input either permanently
				// failed, or succeeded but its draft was since rejected --
				// never replay a dead or superseded run. Derive a fresh,
				// still-unique idempotency key (salted by the new run_no) so
				// a genuine retry can be inserted instead of colliding on
				// the UNIQUE idempotency_key index.
				$record['run_idempotency_key'] = 'run:' . hash( 'sha256', $record['run_idempotency_key'] . ':retry:' . $run_no );
			}
			$this->insert_run( $record, $run_no );
			$result = new Generate_Submission_Feedback_Result( $record['evaluation_public_id'], $record['official_review_id'], $record['official_review_hash'], $record['run_public_id'], $run_no, 'queued', $record['input_digest'], $record['queued_at_utc'], false );
			$this->insert_enqueue_audit( $record, $result );
			$this->insert_enqueue_outbox( $record, $result );
			$this->assert_enqueue_postcondition( $record, $run_no );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			} throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			try {
				$recovered = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
				if ( $recovered instanceof Generate_Submission_Feedback_Result ) {
					return $recovered;
				}
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
				// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Feedback_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Feedback_Work_Item {
		$this->datetime( $now_utc );
		$this->datetime( $lease_expires_at_utc );
		$this->lease_token( $lease_token );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$sql         = 'SELECT r.id AS run_pk, r.public_id AS run_id, r.lock_version AS run_lock_version, r.state AS run_state, r.created_by_user_id AS actor_user_id, r.task_key, r.contract_version, r.operation_key, r.input_schema_version, r.output_schema_version, r.input_digest, r.idempotency_key AS run_idempotency_key, r.provider_key, r.model_key, r.profile_fingerprint, r.routing_revision, r.queued_at_utc, e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.authorization_envelope_json, e.authorization_envelope_hash, (SELECT COUNT(*) FROM ' . self::TABLE_ATTEMPTS . ' a WHERE a.evaluation_run_id = r.id) AS attempt_count '
				. 'FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.public_id = r.evaluation_public_id '
				. 'WHERE r.subject_type = %s AND r.operation_key = %s AND e.state IN (%s,%s) AND (('
				. 'r.state IN (%s,%s) AND r.available_at_utc <= %s) OR (r.state IN (%s,%s) AND r.lease_expires_at_utc IS NOT NULL '
				. 'AND r.lease_expires_at_utc <= %s)) ORDER BY r.available_at_utc ASC, r.id ASC LIMIT 1 FOR UPDATE';
			$row         = $this->row( $this->prepare( $sql, array( 'submission_evaluation', Submission_Feedback_Input_Builder::OPERATION, 'reviewed', 'review_required', 'queued', 'retry_wait', $now_utc, 'leased', 'running', $now_utc ) ) );
			if ( null === $row ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return null;
			}
			$run_pk         = $this->positive_integer( $row['run_pk'] ?? null );
			$lock           = $this->non_negative_integer( $row['run_lock_version'] ?? null );
			$previous_state = (string) ( $row['run_state'] ?? '' );
			if ( in_array( $previous_state, array( 'leased', 'running' ), true ) ) {
				$this->expire_started_attempt( $run_pk, $now_utc );
			}
			$updated = $this->query( $this->prepare( 'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lease_token = %s, lease_expires_at_utc = %s, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s', array( 'leased', $lease_token, $lease_expires_at_utc, $now_utc, $run_pk, $lock, $previous_state ) ) );
			if ( 1 !== $updated ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return null;
			}
			$item = $this->work_item( $row, $lease_token, $lock + 1 );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			} if ( $error instanceof Submission_Evaluation_Failure ) {
				throw $error;
			} throw $this->storage_failure();
		}
	}

	/**
	 * Start attempt.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $attempt_public_id Attempt public id.
	 * @param string                        $started_at_utc Started at utc.
	 * @param string                        $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Feedback_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start_attempt( Submission_Feedback_Work_Item $item, string $attempt_public_id, string $started_at_utc, string $lease_expires_at_utc ): ?Submission_Feedback_Work_Item {
		new Public_Id( $attempt_public_id );
		$this->datetime( $started_at_utc );
		$this->datetime( $lease_expires_at_utc );
		if ( ! $item->is_prepared() ) {
			throw new InvalidArgumentException( 'Submission feedback attempt requires prepared input.' );
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->lock_matches( $item, 'leased' ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return null;
			}
			$attempt_no = $item->attempt_count() + 1;
			$this->insert_or_fail(
				self::TABLE_ATTEMPTS,
				array(
					'public_id'              => $attempt_public_id,
					'created_at_utc'         => $started_at_utc,
					'evaluation_run_id'      => $item->run_pk(),
					'attempt_no'             => $attempt_no,
					'state'                  => 'started',
					'request_hash'           => $item->input_digest(),
					'provider_output_digest' => null,
					'usage_schema'           => null,
					'usage_version'          => null,
					'usage_json'             => null,
					'usage_hash'             => null,
					'gateway_correlation_id' => null,
					'duration_ms'            => null,
					'error_code'             => null,
					'started_at_utc'         => $started_at_utc,
					'finished_at_utc'        => null,
				)
			);
			$updated = $this->query( $this->prepare( 'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, started_at_utc = COALESCE(started_at_utc,%s), lease_expires_at_utc = %s, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s', array( 'running', $started_at_utc, $lease_expires_at_utc, $started_at_utc, $item->run_pk(), $item->lock_version(), 'leased', $item->lease_token() ) ) );
			if ( 1 !== $updated ) {
				throw $this->storage_failure();
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item->started( $attempt_no, $item->lock_version() + 1 );
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			} if ( $error instanceof Submission_Evaluation_Failure ) {
				throw $error;
			} throw $this->storage_failure();
		}
	}

	/**
	 * Complete success.
	 *
	 * @param Submission_Feedback_Work_Item         $item Item.
	 * @param Submission_Evaluation_Gateway_Result  $gateway Gateway.
	 * @param Submission_Feedback_Normalized_Result $result Result.
	 * @param string                                $feedback_public_id Feedback public id.
	 * @param string                                $audit_public_id Audit public id.
	 * @param string                                $outbox_public_id Outbox public id.
	 * @param string                                $finished_at_utc Finished at utc.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_success( Submission_Feedback_Work_Item $item, Submission_Evaluation_Gateway_Result $gateway, Submission_Feedback_Normalized_Result $result, string $feedback_public_id, string $audit_public_id, string $outbox_public_id, string $finished_at_utc ): string {
		foreach ( array( $feedback_public_id, $audit_public_id, $outbox_public_id ) as $id ) {
			new Public_Id( $id );
		} $this->datetime( $finished_at_utc );
		if ( ! $item->is_prepared() || ! $gateway->is_successful() || null === $gateway->routing() ) {
			throw new InvalidArgumentException( 'Submission feedback success record is invalid.' );
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$locked      = $this->success_snapshot( $item->run_pk() );
			if ( null === $locked || ! $this->locked_run_matches( $item, $locked ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return self::COMMIT_STALE;
			}
			if ( ! $this->success_source_matches( $item, $locked ) ) {
				$this->finish_attempt( $item, 'output_discarded', Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $gateway->correlation_id(), $result->provider_output_digest(), $gateway->duration_ms(), $finished_at_utc );
				$this->finish_run( $item, 'failed', Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, null, null, $finished_at_utc, $finished_at_utc );
				$this->insert_worker_audit( $item, $audit_public_id, 'submission_feedback.source_stale', Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, null, $finished_at_utc );
				$this->insert_worker_outbox( $item, $outbox_public_id, 'submission_feedback.source_stale', Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, null, $finished_at_utc, $finished_at_utc );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return self::COMMIT_SOURCE_STALE;
			}
			$feedback_count = $this->non_negative_integer( $locked['feedback_count'] ?? null );
			$previous_pk    = null;
			$previous_id    = null;
			if ( $feedback_count > 0 ) {
				$previous_pk = $this->positive_integer( $locked['latest_feedback_pk'] ?? null );
				$previous_id = $this->public_id( $locked['latest_feedback_id'] ?? null );
				if ( $feedback_count !== $this->positive_integer( $locked['latest_feedback_no'] ?? null ) ) {
					throw $this->stored_invalid();
				}
			} elseif ( null !== ( $locked['latest_feedback_id'] ?? null ) ) {
				throw $this->stored_invalid();
			}
			$revision_no  = $feedback_count + 1;
			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'audience'             => 'student',
						'evaluation_id'        => $item->evaluation_id(),
						'feedback_hash'        => $result->profile_hash(),
						'previous_revision_id' => $previous_id,
						'review_id'            => $item->official_review_id(),
						'revision_no'          => $revision_no,
						'source_run_id'        => $item->run_id(),
						'state'                => 'ai_draft',
					)
				)
			);
			$this->insert_or_fail(
				self::TABLE_FEEDBACK,
				array(
					'public_id'                       => $feedback_public_id,
					'content_hash'                    => $content_hash,
					'created_by_user_id'              => $item->actor_user_id(),
					'created_at_utc'                  => $finished_at_utc,
					'submission_evaluation_id'        => $item->evaluation_pk(),
					'submission_review_id'            => $this->positive_integer( $locked['review_pk'] ?? null ),
					'revision_no'                     => $revision_no,
					'audience'                        => 'student',
					'feedback_schema'                 => self::FEEDBACK_PROFILE_SCHEMA,
					'feedback_version'                => self::PROFILE_VERSION,
					'feedback_json'                   => $result->profile_json(),
					'feedback_hash'                   => $result->profile_hash(),
					'state'                           => 'ai_draft',
					'previous_revision_id'            => $previous_pk,
					'source_evaluation_run_public_id' => $item->run_id(),
				)
			);
			$this->finish_attempt( $item, 'succeeded', null, $gateway->correlation_id(), $result->provider_output_digest(), $gateway->duration_ms(), $finished_at_utc );
			$this->finish_run( $item, 'succeeded', null, 'submission_feedback_revision', $feedback_public_id, $finished_at_utc, $finished_at_utc );
			$this->insert_worker_audit( $item, $audit_public_id, 'submission_feedback.draft_created', null, $feedback_public_id, $finished_at_utc );
			$this->insert_worker_outbox( $item, $outbox_public_id, 'submission_feedback.draft_created', null, $feedback_public_id, $finished_at_utc, $finished_at_utc );
			$post = $this->row( $this->prepare( 'SELECT r.state AS run_state, r.result_public_id, f.public_id AS feedback_id, f.state AS feedback_state FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_FEEDBACK . ' f ON f.source_evaluation_run_public_id = r.public_id WHERE r.id = %d LIMIT 1', array( $item->run_pk() ) ) );
			if ( null === $post || 'succeeded' !== ( $post['run_state'] ?? null ) || 'ai_draft' !== ( $post['feedback_state'] ?? null ) || ! hash_equals( $feedback_public_id, (string) ( $post['result_public_id'] ?? '' ) ) || ! hash_equals( $feedback_public_id, (string) ( $post['feedback_id'] ?? '' ) ) ) {
				throw $this->storage_failure();
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return self::COMMIT_SUCCEEDED;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			} if ( $error instanceof Submission_Evaluation_Failure ) {
				throw $error;
			} throw $this->storage_failure();
		}
	}

	/**
	 * Complete failure.
	 *
	 * @param Submission_Feedback_Work_Item               $item Item.
	 * @param string                                      $attempt_state Attempt state.
	 * @param string                                      $error_code Error code.
	 * @param string|null                                 $gateway_correlation_id Gateway correlation id.
	 * @param string|null                                 $provider_output_digest Provider output digest.
	 * @param int|null                                    $duration_ms Duration ms.
	 * @param Submission_Evaluation_Routing_Snapshot|null $actual_routing Actual routing.
	 * @param bool                                        $retry Retry.
	 * @param string                                      $available_at_utc Available at utc.
	 * @param string                                      $audit_public_id Audit public id.
	 * @param string                                      $outbox_public_id Outbox public id.
	 * @param string                                      $finished_at_utc Finished at utc.
	 * @param string|null                                 $error_message Error message.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_failure( Submission_Feedback_Work_Item $item, string $attempt_state, string $error_code, ?string $gateway_correlation_id, ?string $provider_output_digest, ?int $duration_ms, ?Submission_Evaluation_Routing_Snapshot $actual_routing, bool $retry, string $available_at_utc, string $audit_public_id, string $outbox_public_id, string $finished_at_utc, ?string $error_message = null ): bool {
		unset( $actual_routing );
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$this->datetime( $available_at_utc );
		$this->datetime( $finished_at_utc );
		$this->error_code( $error_code );
		$error_message = $this->error_message( $error_message );
		if ( '' !== $attempt_state && ( null === $gateway_correlation_id || null === $duration_ms ) ) {
			throw new InvalidArgumentException( 'Submission feedback attempt failure is incomplete.' );
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->lock_matches( $item, $item->state() ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return false;
			}
			if ( '' !== $attempt_state ) {
				$this->finish_attempt( $item, $attempt_state, $error_code, $gateway_correlation_id, $provider_output_digest, $duration_ms, $finished_at_utc );
			}
			$state = $retry ? 'retry_wait' : 'failed';
			$this->finish_run( $item, $state, $error_code, null, null, $available_at_utc, $retry ? null : $finished_at_utc, $error_message );
			$event = $retry ? 'submission_feedback.retry_wait' : 'submission_feedback.failed';
			$this->insert_worker_audit( $item, $audit_public_id, $event, $error_code, null, $finished_at_utc );
			$this->insert_worker_outbox( $item, $outbox_public_id, $event, $error_code, null, $finished_at_utc, $available_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			} if ( $error instanceof Submission_Evaluation_Failure ) {
				throw $error;
			} throw $this->storage_failure();
		}
	}

	/**
	 * Source.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source( string $evaluation_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, revision.content_hash AS revision_hash, revision.state AS revision_state, s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id AS official_review_id, s.official_review_hash, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state, i.input_json AS score_input_json, i.input_hash AS score_input_hash, review.id AS review_pk, review.public_id AS joined_review_id, review.content_hash AS joined_review_hash, review.review_no, review.decision, review.official_score, review.score_overrides_schema, review.score_overrides_version, review.score_overrides_json, review.score_overrides_hash, review.reason, previous.public_id AS previous_review_id, result.id AS result_pk, result.public_id AS result_id, result.content_hash AS result_hash, result.normalized_result_hash, result.provider_output_digest, score_run.public_id AS score_run_id, score_run.state AS score_run_state, score_run.result_public_id AS score_run_result_id, score_run.operation_key AS score_run_operation FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.submission_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = s.official_review_public_id AND review.submission_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
			. 'INNER JOIN ' . self::TABLE_RESULTS . ' result ON result.id = review.submission_evaluation_result_id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' score_run ON score_run.id = result.evaluation_run_id '
			. 'WHERE e.public_id = %s AND t.state = %s AND a.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, 'active', 'withdrawn' ) ) );
		if ( null === $row ) {
			return null;
		}
		$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
		$submission_id = $this->public_id( $row['submission_id'] ?? null );
		$revision_id   = $this->public_id( $row['submission_revision_id'] ?? null );
		$revision_hash = $this->hash_value( $row['submission_revision_hash'] ?? null );
		$review_id     = $this->public_id( $row['official_review_id'] ?? null );
		$review_hash   = $this->hash_value( $row['official_review_hash'] ?? null );
		$result_id     = $this->public_id( $row['result_id'] ?? null );
		$result_hash   = $this->hash_value( $row['result_hash'] ?? null );
		$score_run_id  = $this->public_id( $row['score_run_id'] ?? null );
		if (
			'reviewed' !== ( $row['evaluation_state'] ?? null ) || ! hash_equals( $revision_hash, $this->hash_value( $row['revision_hash'] ?? null ) )
			|| ! hash_equals( $revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) )
			|| ! in_array( $row['revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| ! hash_equals( $review_id, $this->public_id( $row['joined_review_id'] ?? null ) ) || ! hash_equals( $review_hash, $this->hash_value( $row['joined_review_hash'] ?? null ) )
			|| ! in_array( $row['decision'] ?? null, array( 'accepted', 'adjusted' ), true ) || 'succeeded' !== ( $row['score_run_state'] ?? null )
			|| ! in_array( $row['score_run_operation'] ?? null, array( Submission_Score_Input_Builder::OPERATION, 'manual_prepare' ), true ) || ! hash_equals( $result_id, $this->public_id( $row['score_run_result_id'] ?? null ) )
		) {
			throw $this->stored_invalid();
		}
		$input                = $this->decode_object( (string) ( $row['score_input_json'] ?? '' ), $this->hash_value( $row['score_input_hash'] ?? null ) );
		$score                = $this->score_profile( $row );
		$expected_result_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'          => $evaluation_id,
					'normalized_result_hash' => $this->hash_value( $row['normalized_result_hash'] ?? null ),
					'provider_output_digest' => $this->hash_value( $row['provider_output_digest'] ?? null ),
					'run_id'                 => $score_run_id,
				)
			)
		);
		$previous_id          = null === ( $row['previous_review_id'] ?? null ) ? null : $this->public_id( $row['previous_review_id'] );
		$official_score       = $this->decimal( $row['official_score'] ?? null );
		$expected_review_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'decision'             => $row['decision'],
					'evaluation_id'        => $evaluation_id,
					'official_score'       => $official_score,
					'previous_review_id'   => $previous_id,
					'reason'               => $this->nullable_text( $row['reason'] ?? null ),
					'result_hash'          => $result_hash,
					'result_id'            => $result_id,
					'review_no'            => $this->positive_integer( $row['review_no'] ?? null ),
					'score_decisions_hash' => $score['hash'],
				)
			)
		);
		if ( ! hash_equals( $result_hash, $expected_result_hash ) || ! hash_equals( $review_hash, $expected_review_hash ) || ! hash_equals( $official_score, $this->score_total( $score['items'] ) ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'evaluation_pk'            => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'            => $evaluation_id,
			'evaluation_lock_version'  => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'         => 'reviewed',
			'context_type'             => $this->context_token( $row['context_type'] ?? null, 16 ),
			'context_id'               => $this->context_token( $row['context_id'] ?? null, 191, true ),
			'context_revision'         => $this->context_token( $row['context_revision'] ?? null, 191, true ),
			'submission_pk'            => $this->positive_integer( $row['submission_pk'] ?? null ),
			'submission_id'            => $submission_id,
			'submission_lock_version'  => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'submission_revision_id'   => $revision_id,
			'submission_revision_hash' => $revision_hash,
			'official_review_pk'       => $this->positive_integer( $row['review_pk'] ?? null ),
			'official_review_id'       => $review_id,
			'official_review_hash'     => $review_hash,
			'result_pk'                => $this->positive_integer( $row['result_pk'] ?? null ),
			'result_id'                => $result_id,
			'result_hash'              => $result_hash,
			'score_input'              => $input,
			'score_decisions'          => $score['items'],
		);
	}

	/**
	 * Score profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{items:array<int,array<string,mixed>>,hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_profile( array $row ): array {
		$hash    = $this->hash_value( $row['score_overrides_hash'] ?? null );
		$profile = $this->decode_object( (string) ( $row['score_overrides_json'] ?? '' ), $hash );
		$this->exact_keys( $profile, array( 'items', 'schema_id', 'schema_version' ) );
		if ( Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA !== ( $row['score_overrides_schema'] ?? null ) || Review_Submission_Evaluation::SCORE_DECISIONS_VERSION !== $this->positive_integer( $row['score_overrides_version'] ?? null ) || Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA !== ( $profile['schema_id'] ?? null ) || '1.0.0' !== ( $profile['schema_version'] ?? null ) || ! is_array( $profile['items'] ?? null ) || ! array_is_list( $profile['items'] ) || array() === $profile['items'] ) {
			throw $this->stored_invalid();
		} return array(
			'items' => $profile['items'],
			'hash'  => $hash,
		);
	}

	/**
	 * Score total.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_total( array $items ): string {
		$units = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw $this->stored_invalid();
			} $units += $this->decimal_units( $this->decimal( $item['official_points'] ?? null ) );
		} return intdiv( $units, 10000 ) . '.' . str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Insert run.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param int                 $run_no Run no.
	 * @return void
	 */
	private function insert_run( array $record, int $run_no ): void {
		$this->insert_or_fail(
			self::TABLE_RUNS,
			array(
				'public_id'             => $record['run_public_id'],
				'lock_version'          => 0,
				'created_by_user_id'    => $record['actor_user_id'],
				'created_at_utc'        => $record['queued_at_utc'],
				'updated_by_user_id'    => $record['actor_user_id'],
				'updated_at_utc'        => $record['queued_at_utc'],
				'subject_type'          => 'submission_evaluation',
				'evaluation_public_id'  => $record['evaluation_public_id'],
				'run_no'                => $run_no,
				'state'                 => 'queued',
				'task_key'              => $record['task_key'],
				'contract_version'      => Submission_Feedback_Input_Builder::OUTER_INPUT_CONTRACT,
				'operation_key'         => Submission_Feedback_Input_Builder::OPERATION,
				'input_schema_version'  => Submission_Feedback_Input_Builder::REQUEST_VERSION,
				'output_schema_version' => Submission_Feedback_Input_Builder::OUTPUT_VERSION,
				'input_digest'          => $record['input_digest'],
				'result_type'           => null,
				'result_public_id'      => null,
				'idempotency_key'       => $record['run_idempotency_key'],
				'correlation_id'        => $record['correlation_id'],
				'provider_key'          => $record['provider_key'],
				'model_key'             => $record['model_key'],
				'profile_fingerprint'   => $record['profile_fingerprint'],
				'routing_revision'      => $record['routing_revision'],
				'available_at_utc'      => $record['queued_at_utc'],
				'queued_at_utc'         => $record['queued_at_utc'],
				'lease_token'           => null,
				'lease_expires_at_utc'  => null,
				'started_at_utc'        => null,
				'finished_at_utc'       => null,
				'last_error_code'       => null,
			)
		);
	}

	/**
	 * Insert enqueue audit.
	 *
	 * @param array<string,mixed>                 $record Record.
	 * @param Generate_Submission_Feedback_Result $result Result.
	 * @return void
	 */
	private function insert_enqueue_audit( array $record, Generate_Submission_Feedback_Result $result ): void {
		$metadata = array(
			'command_id'           => $record['command_id'],
			'input_digest'         => $record['input_digest'],
			'official_review_hash' => $record['official_review_hash'],
			'official_review_id'   => $record['official_review_id'],
			'operation'            => Submission_Feedback_Input_Builder::OPERATION,
			'request_digest'       => $record['request_digest'],
			'result_id'            => $record['result_id'],
			'run_id'               => $result->run_id(),
			'run_no'               => $result->run_no(),
		);
		$this->insert_audit_row( $record['audit_public_id'], $record['actor_user_id'], self::ENQUEUE_EVENT, $record['evaluation_public_id'], $record['context_type'], $record['context_id'], $record['context_revision'], $record['correlation_id'], null, hash( 'sha256', $this->canonical->encode( $metadata ) ), $metadata, $record['queued_at_utc'] );
	}

	/**
	 * Insert enqueue outbox.
	 *
	 * @param array<string,mixed>                 $record Record.
	 * @param Generate_Submission_Feedback_Result $result Result.
	 * @return void
	 */
	private function insert_enqueue_outbox( array $record, Generate_Submission_Feedback_Result $result ): void {
		$payload = $this->receipt_payload( $result, $record['request_digest'] );
		$json    = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['queued_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['queued_at_utc'],
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $record['evaluation_public_id'],
				'event_type'           => self::ENQUEUE_EVENT,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::RECEIPT_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $json,
				'payload_hash'         => hash( 'sha256', $json ),
				'available_at_utc'     => $record['queued_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Generate_Submission_Feedback_Result $result Result.
	 * @param string                              $request_digest Request digest.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Generate_Submission_Feedback_Result $result, string $request_digest ): array {
		return array(
			'evaluation_id'        => $result->evaluation_id(),
			'input_digest'         => $result->input_digest(),
			'official_review_hash' => $result->official_review_hash(),
			'official_review_id'   => $result->official_review_id(),
			'queued_at_utc'        => $result->queued_at_utc(),
			'request_digest'       => $request_digest,
			'run'                  => array(
				'id'     => $result->run_id(),
				'run_no' => $result->run_no(),
				'state'  => $result->run_state(),
			),
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Generate_Submission_Feedback_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Generate_Submission_Feedback_Result {
		$sql = 'SELECT payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s AND event_type = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		} $row = $this->row( $this->prepare( $sql, array( $key, self::ENQUEUE_EVENT ) ) );
		if ( null === $row ) {
			return null;
		} $payload = $this->decode_object( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		} return $this->result_from_payload( $payload, true );
	}

	/**
	 * Semantic run.
	 *
	 * @param string $key Key.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function semantic_run( string $key, bool $for_update ): ?array {
		$sql = 'SELECT r.public_id AS run_id, r.run_no, r.state AS run_state, r.evaluation_public_id AS evaluation_id, r.input_digest, r.queued_at_utc, r.operation_key, r.contract_version, r.input_schema_version, r.output_schema_version, latest.state AS latest_revision_state FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.public_id = r.evaluation_public_id LEFT JOIN ' . self::TABLE_FEEDBACK . ' latest ON latest.submission_evaluation_id = e.id AND latest.revision_no = ( SELECT MAX(f2.revision_no) FROM ' . self::TABLE_FEEDBACK . ' f2 WHERE f2.submission_evaluation_id = e.id ) WHERE r.idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		} $row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		} if ( Submission_Feedback_Input_Builder::OPERATION !== ( $row['operation_key'] ?? null ) || Submission_Feedback_Input_Builder::OUTER_INPUT_CONTRACT !== ( $row['contract_version'] ?? null ) || Submission_Feedback_Input_Builder::REQUEST_VERSION !== ( $row['input_schema_version'] ?? null ) || Submission_Feedback_Input_Builder::OUTPUT_VERSION !== ( $row['output_schema_version'] ?? null ) ) {
			throw $this->stored_invalid();
		} return $row;
	}
	/**
	 * A semantic match is safe to replay only while its outcome is still
	 * live: still in flight, or succeeded with the EVALUATION's current
	 * latest draft (which may have been produced by a different, later
	 * retry run than the one this identity happens to point at -- a
	 * retry gets its own salted key, so the canonical semantic key keeps
	 * resolving to whichever run first claimed it) not since rejected by
	 * the teacher. Checking the matched run's own resulting revision
	 * instead of the evaluation's latest one would miss a rejection that
	 * landed on a newer revision produced by a different run.
	 *
	 * @param array $semantic Semantic.
	 * @return bool
	 */
	private function is_dead_semantic_match( array $semantic ): bool {
		$state = $semantic['run_state'] ?? null;
		if ( in_array( $state, array( 'failed', 'cancelled' ), true ) ) {
			return true;
		} return 'succeeded' === $state && 'rejected' === ( $semantic['latest_revision_state'] ?? null );
	}

	/**
	 * Result from run.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param array<string,mixed> $run Run.
	 * @param bool                $replayed Replayed.
	 * @return Generate_Submission_Feedback_Result
	 */
	private function result_from_run( array $source, array $run, bool $replayed ): Generate_Submission_Feedback_Result {
		return new Generate_Submission_Feedback_Result( $this->public_id( $run['evaluation_id'] ?? null ), $source['official_review_id'], $source['official_review_hash'], $this->public_id( $run['run_id'] ?? null ), $this->positive_integer( $run['run_no'] ?? null ), (string) ( $run['run_state'] ?? '' ), $this->hash_value( $run['input_digest'] ?? null ), (string) ( $run['queued_at_utc'] ?? '' ), $replayed );
	}

	/**
	 * Result from payload.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param bool                $replayed Replayed.
	 * @return Generate_Submission_Feedback_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_payload( array $payload, bool $replayed ): Generate_Submission_Feedback_Result {
		$run = $payload['run'] ?? null;
		if ( ! is_array( $run ) || array_is_list( $run ) ) {
			throw $this->stored_invalid();
		} try {
			return new Generate_Submission_Feedback_Result( (string) ( $payload['evaluation_id'] ?? '' ), (string) ( $payload['official_review_id'] ?? '' ), (string) ( $payload['official_review_hash'] ?? '' ), (string) ( $run['id'] ?? '' ), $this->positive_integer( $run['run_no'] ?? null ), (string) ( $run['state'] ?? '' ), (string) ( $payload['input_digest'] ?? '' ), (string) ( $payload['queued_at_utc'] ?? '' ), $replayed );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
	}

	/**
	 * Reject invalid enqueue source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_enqueue_source( array $source, array $record ): void {
		if ( ! hash_equals( $record['evaluation_public_id'], $source['evaluation_id'] ) || $record['evaluation_lock_version'] !== $source['evaluation_lock_version'] || 'reviewed' !== $source['evaluation_state'] || ! hash_equals( $record['submission_public_id'], $source['submission_id'] ) || ! hash_equals( $record['submission_revision_hash'], $source['submission_revision_hash'] ) || ! hash_equals( $record['official_review_id'], $source['official_review_id'] ) || ! hash_equals( $record['official_review_hash'], $source['official_review_hash'] ) || ! hash_equals( $record['result_id'], $source['result_id'] ) || ! hash_equals( $record['context_type'], $source['context_type'] ) || ! hash_equals( $record['context_id'], $source['context_id'] ) || ! hash_equals( $record['context_revision'], $source['context_revision'] ) ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_review_id'   => (string) $source['official_review_id'],
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_review_hash' => (string) $source['official_review_hash'],
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_state'                => (string) $source['evaluation_state'],
				)
			);
		}
	}

	/**
	 * Reject invalid enqueue postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param int                 $run_no Run no.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_enqueue_postcondition( array $record, int $run_no ): void {
		$row = $this->row( $this->prepare( 'SELECT public_id, operation_key, input_digest FROM ' . self::TABLE_RUNS . ' WHERE subject_type = %s AND evaluation_public_id = %s AND run_no = %d LIMIT 1 FOR UPDATE', array( 'submission_evaluation', $record['evaluation_public_id'], $run_no ) ) );
		if ( null === $row || ! hash_equals( $record['run_public_id'], (string) ( $row['public_id'] ?? '' ) ) || Submission_Feedback_Input_Builder::OPERATION !== ( $row['operation_key'] ?? null ) || ! hash_equals( $record['input_digest'], (string) ( $row['input_digest'] ?? '' ) ) ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid enqueue record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_enqueue_record( array $record ): void {
		$expected = array( 'actor_user_id', 'audit_public_id', 'command_id', 'command_storage_key', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'evaluation_lock_version', 'evaluation_public_id', 'evaluation_state', 'input_digest', 'model_key', 'official_review_hash', 'official_review_id', 'outbox_public_id', 'profile_fingerprint', 'provider_key', 'queued_at_utc', 'request_digest', 'result_id', 'routing_revision', 'run_idempotency_key', 'run_public_id', 'submission_public_id', 'submission_revision_hash', 'task_key' );
		$this->exact_keys( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['evaluation_lock_version'] ) || $record['evaluation_lock_version'] < 0 || 'reviewed' !== $record['evaluation_state'] || Addon_Manifest::TASK_STUDENT_ASSESSMENT !== $record['task_key'] ) {
			throw new InvalidArgumentException( 'Submission feedback enqueue record is invalid.' );
		} foreach ( array( 'audit_public_id', 'evaluation_public_id', 'official_review_id', 'outbox_public_id', 'result_id', 'run_public_id', 'submission_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		} foreach ( array( 'input_digest', 'official_review_hash', 'profile_fingerprint', 'request_digest', 'submission_revision_hash' ) as $key ) {
			$this->hash_value( $record[ $key ] );
		} foreach ( array( 'command_storage_key', 'run_idempotency_key' ) as $key ) {
			$this->storage_key( $record[ $key ] );
		} $this->datetime( $record['queued_at_utc'] );
		foreach ( array(
			'command_id'       => 128,
			'correlation_id'   => 100,
			'context_type'     => 16,
			'context_id'       => 191,
			'context_revision' => 191,
			'model_key'        => 100,
			'provider_key'     => 100,
			'routing_revision' => 191,
		) as $key => $max ) {
			$this->context_token( $record[ $key ], $max, in_array( $key, array( 'context_id', 'context_revision', 'model_key' ), true ) );
		}
	}

	/**
	 * Work item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $lease_token Lease token.
	 * @param int                 $lock_version Lock version.
	 * @return Submission_Feedback_Work_Item
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function work_item( array $row, string $lease_token, int $lock_version ): Submission_Feedback_Work_Item {
		$authorization = $this->decode_object( (string) ( $row['authorization_envelope_json'] ?? '' ), $this->hash_value( $row['authorization_envelope_hash'] ?? null ) );
		$context       = $authorization['context'] ?? null;
		$object        = $authorization['object'] ?? null;
		if ( ! is_array( $context ) || array_is_list( $context ) || ! is_array( $object ) || array_is_list( $object ) || 'submission' !== ( $object['type'] ?? null ) || Addon_Manifest::TASK_STUDENT_ASSESSMENT !== ( $row['task_key'] ) || Submission_Feedback_Input_Builder::OUTER_INPUT_CONTRACT !== ( $row['contract_version'] ) || Submission_Feedback_Input_Builder::OPERATION !== ( $row['operation_key'] ?? null ) || Submission_Feedback_Input_Builder::REQUEST_VERSION !== ( $row['input_schema_version'] ?? null ) || Submission_Feedback_Input_Builder::OUTPUT_VERSION !== ( $row['output_schema_version'] ?? null ) ) {
			throw $this->stored_invalid();
		} try {
			return new Submission_Feedback_Work_Item( $this->positive_integer( $row['evaluation_pk'] ?? null ), $this->positive_integer( $row['run_pk'] ?? null ), (string) ( $row['evaluation_id'] ?? '' ), (string) ( $row['run_id'] ?? '' ), $this->positive_integer( $row['actor_user_id'] ?? null ), (string) ( $object['id'] ?? '' ), (string) ( $context['type'] ?? '' ), (string) ( $context['id'] ?? '' ), (string) ( $context['revision'] ?? '' ), $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ), (string) ( $row['evaluation_state'] ?? '' ), (string) ( $row['contract_version'] ), (string) ( $row['input_digest'] ?? '' ), (string) ( $row['run_idempotency_key'] ?? '' ), new Submission_Evaluation_Routing_Snapshot( (string) ( $row['task_key'] ), (string) ( $row['provider_key'] ?? '' ), (string) ( $row['model_key'] ?? '' ), (string) ( $row['profile_fingerprint'] ?? '' ), (string) ( $row['routing_revision'] ?? '' ) ), $lease_token, $lock_version, $this->non_negative_integer( $row['attempt_count'] ?? null ), 0, 'leased', (string) ( $row['queued_at_utc'] ?? '' ) );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
	}

	/**
	 * Success snapshot.
	 *
	 * @param int $run_pk Run pk.
	 * @return array<string,mixed>|null
	 */
	private function success_snapshot( int $run_pk ): ?array {
		$sql = 'SELECT r.state AS run_state, r.lock_version AS run_lock_version, r.lease_token, r.result_public_id, e.state AS evaluation_state, e.lock_version AS evaluation_lock_version, s.official_review_public_id AS official_review_id, s.official_review_hash, review.id AS review_pk, review.submission_evaluation_id AS review_evaluation_pk, (SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' counted WHERE counted.submission_evaluation_id = e.id) AS feedback_count, latest.id AS latest_feedback_pk, latest.public_id AS latest_feedback_id, latest.revision_no AS latest_feedback_no FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.public_id = r.evaluation_public_id INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id LEFT JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = s.official_review_public_id AND review.submission_evaluation_id = e.id LEFT JOIN ' . self::TABLE_FEEDBACK . ' latest ON latest.submission_evaluation_id = e.id AND latest.revision_no = (SELECT MAX(f.revision_no) FROM ' . self::TABLE_FEEDBACK . ' f WHERE f.submission_evaluation_id = e.id) WHERE r.id = %d LIMIT 1 FOR UPDATE';
		return $this->row( $this->prepare( $sql, array( $run_pk ) ) );
	}

	/**
	 * Locked run matches.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param array<string,mixed>           $row Row.
	 * @return bool
	 */
	private function locked_run_matches( Submission_Feedback_Work_Item $item, array $row ): bool {
		return 'running' === ( $row['run_state'] ?? null ) && $item->lock_version() === $this->non_negative_integer( $row['run_lock_version'] ?? null ) && hash_equals( $item->lease_token(), (string) ( $row['lease_token'] ?? '' ) ) && null === ( $row['result_public_id'] ?? null );
	}
	/**
	 * Success source matches.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param array<string,mixed>           $row Row.
	 * @return bool
	 */
	private function success_source_matches( Submission_Feedback_Work_Item $item, array $row ): bool {
		return 'reviewed' === ( $row['evaluation_state'] ?? null ) && $item->evaluation_lock_version() === $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ) && hash_equals( $item->official_review_id(), (string) ( $row['official_review_id'] ?? '' ) ) && hash_equals( $item->official_review_hash(), (string) ( $row['official_review_hash'] ?? '' ) ) && $item->evaluation_pk() === $this->positive_integer( $row['review_evaluation_pk'] ?? null );
	}

	/**
	 * Lock matches.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $state State.
	 * @return bool
	 */
	private function lock_matches( Submission_Feedback_Work_Item $item, string $state ): bool {
		$row = $this->row( $this->prepare( 'SELECT state, lock_version, lease_token, result_public_id FROM ' . self::TABLE_RUNS . ' WHERE id = %d LIMIT 1 FOR UPDATE', array( $item->run_pk() ) ) );
		return null !== $row && hash_equals( $state, (string) ( $row['state'] ?? '' ) ) && $item->lock_version() === $this->non_negative_integer( $row['lock_version'] ?? null ) && hash_equals( $item->lease_token(), (string) ( $row['lease_token'] ?? '' ) ) && null === ( $row['result_public_id'] ?? null );
	}

	/**
	 * Finish attempt.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $state State.
	 * @param string|null                   $error_code Error code.
	 * @param string|null                   $correlation Correlation.
	 * @param string|null                   $digest Digest.
	 * @param int|null                      $duration Duration.
	 * @param string                        $finished Finished.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finish_attempt( Submission_Feedback_Work_Item $item, string $state, ?string $error_code, ?string $correlation, ?string $digest, ?int $duration, string $finished ): void {
		if ( null === $correlation || null === $duration ) {
			throw new InvalidArgumentException( 'Submission feedback attempt completion is incomplete.' );
		} $usage     = $this->usage();
		$assignments = array( 'state = %s' );
		$values      = array( $state );
		if ( null === $digest ) {
			$assignments[] = 'provider_output_digest = NULL';
		} else {
			$assignments[] = 'provider_output_digest = %s';
			$values[]      = $digest;
		} $assignments = array_merge( $assignments, array( 'usage_schema = %s', 'usage_version = %d', 'usage_json = %s', 'usage_hash = %s', 'gateway_correlation_id = %s', 'duration_ms = %d' ) );
		$values        = array_merge( $values, array( self::USAGE_SCHEMA, self::PROFILE_VERSION, $usage['json'], $usage['hash'], $correlation, $duration ) );
		if ( null === $error_code ) {
			$assignments[] = 'error_code = NULL';
		} else {
			$assignments[] = 'error_code = %s';
			$values[]      = $error_code;
		} $assignments[] = 'finished_at_utc = %s';
		$values[]        = $finished;
		$values          = array_merge( $values, array( $item->run_pk(), $item->attempt_no(), 'started' ) );
		if ( 1 !== $this->query( $this->prepare( 'UPDATE ' . self::TABLE_ATTEMPTS . ' SET ' . implode( ', ', $assignments ) . ' WHERE evaluation_run_id = %d AND attempt_no = %d AND state = %s', $values ) ) ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Finish run.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $state State.
	 * @param string|null                   $error Error.
	 * @param string|null                   $result_type Result type.
	 * @param string|null                   $result_id Result id.
	 * @param string                        $available Available.
	 * @param string|null                   $finished Finished.
	 * @param string|null                   $error_message Error message.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finish_run( Submission_Feedback_Work_Item $item, string $state, ?string $error, ?string $result_type, ?string $result_id, string $available, ?string $finished, ?string $error_message = null ): void {
		$sql    = 'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, available_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL';
		$values = array( $state, $item->actor_user_id(), $available, $available );
		if ( null === $finished ) {
			$sql .= ', finished_at_utc = NULL';
		} else {
			$sql     .= ', finished_at_utc = %s';
			$values[] = $finished;
		} if ( null === $error ) {
			$sql .= ', last_error_code = NULL, last_error_message = NULL';
		} else {
			$sql     .= ', last_error_code = %s, last_error_message = %s';
			$values[] = $error;
			$values[] = $error_message;
		} if ( null === $result_id ) {
			$sql .= ', result_type = NULL, result_public_id = NULL';
		} else {
			$sql     .= ', result_type = %s, result_public_id = %s';
			$values[] = $result_type;
			$values[] = $result_id;
		} $sql .= ' WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s AND result_public_id IS NULL';
		$values = array_merge( $values, array( $item->run_pk(), $item->lock_version(), $item->state(), $item->lease_token() ) );
		if ( 1 !== $this->query( $this->prepare( $sql, $values ) ) ) {
			throw $this->storage_failure();
		}
	}
	/**
	 * Error message.
	 *
	 * @param string|null $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function error_message( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( '' === trim( $value ) || strlen( $value ) > 500 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback worker error message is invalid.' );
		} return $value;
	}

	/**
	 * Insert worker audit.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $public_id Public id.
	 * @param string                        $event Event.
	 * @param string|null                   $error Error.
	 * @param string|null                   $feedback_id Feedback id.
	 * @param string                        $occurred Occurred.
	 * @return void
	 */
	private function insert_worker_audit( Submission_Feedback_Work_Item $item, string $public_id, string $event, ?string $error, ?string $feedback_id, string $occurred ): void {
		$metadata = array(
			'error_code'           => $error,
			'feedback_revision_id' => $feedback_id,
			'input_digest'         => $item->input_digest(),
			'official_review_id'   => $item->is_prepared() ? $item->official_review_id() : null,
			'run_id'               => $item->run_id(),
		);
		$this->insert_audit_row( $public_id, $item->actor_user_id(), $event, $item->evaluation_id(), $item->context_type(), $item->context_id(), $item->context_revision(), 'worker:' . substr( hash( 'sha256', $item->run_id() . ':' . $item->lease_token() ), 0, 32 ), null, hash( 'sha256', $this->canonical->encode( $metadata ) ), $metadata, $occurred );
	}

	/**
	 * Insert worker outbox.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $public_id Public id.
	 * @param string                        $event Event.
	 * @param string|null                   $error Error.
	 * @param string|null                   $feedback_id Feedback id.
	 * @param string                        $created Created.
	 * @param string                        $available Available.
	 * @return void
	 */
	private function insert_worker_outbox( Submission_Feedback_Work_Item $item, string $public_id, string $event, ?string $error, ?string $feedback_id, string $created, string $available ): void {
		$payload = array(
			'error_code'           => $error,
			'evaluation_id'        => $item->evaluation_id(),
			'feedback_revision_id' => $feedback_id,
			'run_id'               => $item->run_id(),
		);
		$json    = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $item->actor_user_id(),
				'created_at_utc'       => $created,
				'updated_by_user_id'   => $item->actor_user_id(),
				'updated_at_utc'       => $created,
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $item->evaluation_id(),
				'event_type'           => $event,
				'idempotency_key'      => 'event:' . hash( 'sha256', $event . ':' . $item->run_id() . ':' . $item->attempt_no() . ':' . $available ),
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $json,
				'payload_hash'         => hash( 'sha256', $json ),
				'available_at_utc'     => $available,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Insert audit row.
	 *
	 * @param string              $public_id Public id.
	 * @param int                 $actor Actor.
	 * @param string              $event Event.
	 * @param string              $object_id Object id.
	 * @param string              $context_type Context type.
	 * @param string              $context_id Context id.
	 * @param string              $context_revision Context revision.
	 * @param string              $correlation Correlation.
	 * @param string|null         $before Before.
	 * @param string              $after After.
	 * @param array<string,mixed> $metadata Metadata.
	 * @param string              $occurred Occurred.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit_row( string $public_id, int $actor, string $event, string $object_id, string $context_type, string $context_id, string $context_revision, string $correlation, ?string $before, string $after, array $metadata, string $occurred ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous ) ) {
			throw $this->stored_invalid();
		} $json     = $this->canonical->encode( $metadata );
		$hash       = hash( 'sha256', $json );
		$event_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $actor,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation,
					'metadata_hash'       => $hash,
					'object_public_id'    => $object_id,
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $occurred,
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $occurred,
				'actor_user_id'       => $actor,
				'action_key'          => $event,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $object_id,
				'context_type'        => $context_type,
				'context_id'          => $context_id,
				'context_revision'    => $context_revision,
				'correlation_id'      => $correlation,
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $json,
				'metadata_hash'       => $hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Expire started attempt.
	 *
	 * @param int    $run_pk Run pk.
	 * @param string $finished Finished.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function expire_started_attempt( int $run_pk, string $finished ): void {
		$changed = $this->query( $this->prepare( 'UPDATE ' . self::TABLE_ATTEMPTS . ' SET state = %s, error_code = %s, finished_at_utc = %s WHERE evaluation_run_id = %d AND state = %s', array( 'gateway_failed', 'worker_lost', $finished, $run_pk, 'started' ) ) );
		if ( $changed < 0 || $changed > 1 ) {
			throw $this->storage_failure();
		}
	}
	/**
	 * Usage.
	 *
	 * @return array{json:string,hash:string}
	 */
	private function usage(): array {
		$json = $this->canonical->encode(
			array(
				'cost'        => null,
				'measurement' => 'unavailable',
				'tokens'      => null,
			)
		);
		return array(
			'json' => $json,
			'hash' => hash( 'sha256', $json ),
		);
	}

	/**
	 * Decode object.
	 *
	 * @param mixed  $value Value.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_object( $value, string $hash ): array {
		if ( ! is_string( $value ) || ! hash_equals( $hash, hash( 'sha256', $value ) ) ) {
			throw $this->stored_invalid();
		} try {
			$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw $this->stored_invalid();
		} if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $value, $this->canonical->encode( $decoded ) ) ) {
			throw $this->stored_invalid();
		} return $decoded;
	}
	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			throw $this->stored_invalid();
		} try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		} throw $this->stored_invalid();
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		} throw $this->stored_invalid();
	}
	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $m ) ) {
			throw $this->stored_invalid();
		} return $m[1] . '.' . str_pad( $m[2] ?? '', 4, '0' );
	}
	/**
	 * Decimal units.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function decimal_units( string $value ): int {
		[ $whole, $fraction ] = explode( '.', $value );
		return ( (int) $whole * 10000 ) + (int) $fraction;
	}
	/**
	 * Nullable text.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_text( $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 2000 ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Context token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $slash Slash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( $value, int $maximum, bool $slash = false ): string {
		if ( ! is_string( $value ) ) {
			throw $this->stored_invalid();
		} $pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 191 || 1 !== preg_match( '/^[a-z]+:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback storage key is invalid.' );
		} return $value;
	}
	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback UTC time is invalid.' );
		}
	}
	/**
	 * Lease token.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lease_token( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback lease token is invalid.' );
		}
	}
	/**
	 * Error code.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function error_code( string $value ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback error code is invalid.' );
		}
	}
	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw $this->stored_invalid();
		}
	}
	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw $this->storage_failure();
		} return $prepared;
	}
	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$database = $this->database();
		$row      = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== (string) ( $database->last_error ?? '' ) ) {
			throw $this->storage_failure();
		} if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw $this->storage_failure();
		} return $row;
	}
	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scalar( string $sql ) {
		$database = $this->database();
		$value    = $database->get_var( $sql );
		if ( '' !== (string) ( $database->last_error ?? '' ) ) {
			throw $this->storage_failure();
		} return $value;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( string $sql ): int {
		$result = $this->database()->query( $sql );
		if ( false === $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		} return (int) $result;
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates failures from the database query boundary.
	 */
	private function query_or_fail( string $sql ): void {
		$this->query( $sql );
	}
	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$result = $this->database()->insert( $table, $data );
		if ( false === $result || 1 !== (int) $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
	}
	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
	 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}
	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw $this->storage_failure();
		} foreach ( array( 'prepare', 'get_row', 'get_var', 'query', 'insert' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw $this->storage_failure();
			}
		} return $database;
	}
	/**
	 * Storage failure.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function storage_failure(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
	/**
	 * Stored invalid.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function stored_invalid(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
	}
}
