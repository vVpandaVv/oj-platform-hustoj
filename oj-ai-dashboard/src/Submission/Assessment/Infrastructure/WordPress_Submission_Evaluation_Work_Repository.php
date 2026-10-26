<?php
/**
 * Conditional lease repository for student submission evaluation workers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional lease repository for student submission evaluation workers.
 */
final class WordPress_Submission_Evaluation_Work_Repository implements Submission_Evaluation_Work_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results';
	private const TABLE_ITEMS       = 'oj_ai_submission_evaluation_items';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const AUDIT_SCHEMA    = 'oj_ai.submission_evaluation_worker_audit';
	private const OUTBOX_SCHEMA   = 'oj_ai.submission_evaluation_worker_event';
	private const USAGE_SCHEMA    = 'oj_ai.ai_usage';
	private const PROFILE_VERSION = 1;

	/**
	 * Initialize WordPress submission evaluation work repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due(
		string $now_utc,
		string $lease_token,
		string $lease_expires_at_utc
	): ?Submission_Evaluation_Work_Item {
		$this->datetime( $now_utc );
		$this->datetime( $lease_expires_at_utc );
		$this->lease_token( $lease_token );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$sql         = 'SELECT r.id AS run_pk, r.public_id AS run_public_id, r.lock_version AS run_lock_version, r.state AS run_state, r.task_key, r.contract_version, r.operation_key, r.input_schema_version, r.output_schema_version, r.input_digest, r.idempotency_key AS run_idempotency_key, r.provider_key, r.model_key, r.profile_fingerprint, r.routing_revision, r.queued_at_utc, r.lease_expires_at_utc, e.id AS evaluation_pk, e.public_id AS evaluation_public_id, e.created_by_user_id, e.state AS evaluation_state, e.submission_revision_public_id, e.submission_revision_hash, e.worksheet_publication_public_id, e.publication_hash, e.authorization_envelope_json, e.authorization_envelope_hash, i.input_json, i.input_hash, i.input_byte_size, (SELECT COUNT(*) FROM ' . self::TABLE_ATTEMPTS . ' a WHERE a.evaluation_run_id = r.id) AS attempt_count '
				. 'FROM ' . self::TABLE_RUNS . ' r '
				. 'INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.public_id = r.evaluation_public_id '
				. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.submission_evaluation_id = e.id '
				. 'WHERE r.subject_type = %s AND r.operation_key = %s AND e.state IN (%s,%s) AND ( '
				. '(r.state IN (%s,%s) AND r.available_at_utc <= %s) '
				. 'OR (r.state IN (%s,%s) AND r.lease_expires_at_utc IS NOT NULL AND r.lease_expires_at_utc <= %s) '
				. ') ORDER BY r.available_at_utc ASC, r.id ASC LIMIT 1 FOR UPDATE';
			$row         = $this->row(
				$this->prepare(
					$sql,
					array( 'submission_evaluation', Submission_Score_Input_Builder::OPERATION, 'queued', 'running', 'queued', 'retry_wait', $now_utc, 'leased', 'running', $now_utc )
				)
			);
			if ( null === $row ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return null;
			}

			$run_pk         = $this->positive_integer( $row['run_pk'] ?? null );
			$lock_version   = $this->non_negative_integer( $row['run_lock_version'] ?? null );
			$previous_state = (string) ( $row['run_state'] ?? '' );
			if ( in_array( $previous_state, array( 'leased', 'running' ), true ) ) {
				$this->expire_started_attempt( $run_pk, $now_utc );
			}
			$updated = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lease_token = %s, lease_expires_at_utc = %s, '
						. 'lock_version = lock_version + 1, updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND state = %s',
					array( 'leased', $lease_token, $lease_expires_at_utc, $now_utc, $run_pk, $lock_version, $previous_state )
				)
			);
			if ( 1 !== $updated ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return null;
			}

			$item = $this->work_item( $row, $lease_token, $lock_version + 1 );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Evaluation_Worker_Failure ) {
				throw $error;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Start attempt.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $attempt_public_id Attempt public id.
	 * @param string                          $started_at_utc Started at utc.
	 * @param string                          $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start_attempt(
		Submission_Evaluation_Work_Item $item,
		string $attempt_public_id,
		string $started_at_utc,
		string $lease_expires_at_utc
	): ?Submission_Evaluation_Work_Item {
		$this->datetime( $started_at_utc );
		$this->datetime( $lease_expires_at_utc );
		if ( 'leased' !== $item->state() ) {
			throw $this->invalid_work_item();
		}
		try {
			new Public_Id( $attempt_public_id );
		} catch ( InvalidArgumentException ) {
			throw $this->invalid_work_item();
		}

		$attempt_no  = $item->attempt_count() + 1;
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->lock_matches( $item, 'leased' ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return null;
			}
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
			$updated = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, '
						. 'lease_expires_at_utc = %s, started_at_utc = COALESCE(started_at_utc, %s), updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					array(
						'running',
						$lease_expires_at_utc,
						$started_at_utc,
						$started_at_utc,
						$item->run_pk(),
						$item->lock_version(),
						'leased',
						$item->lease_token(),
					)
				)
			);
			if ( 1 !== $updated ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return null;
			}
			$this->update_evaluation_state( $item, $started_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item->started( $attempt_no, $item->lock_version() + 1 );
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Evaluation_Worker_Failure ) {
				throw $error;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Complete success.
	 *
	 * @param Submission_Evaluation_Work_Item         $item Item.
	 * @param Submission_Evaluation_Gateway_Result    $gateway Gateway.
	 * @param Submission_Evaluation_Normalized_Result $result Result.
	 * @param string                                  $result_public_id Result public id.
	 * @param string                                  $audit_public_id Audit public id.
	 * @param string                                  $outbox_public_id Outbox public id.
	 * @param string                                  $finished_at_utc Finished at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_success(
		Submission_Evaluation_Work_Item $item,
		Submission_Evaluation_Gateway_Result $gateway,
		Submission_Evaluation_Normalized_Result $result,
		string $result_public_id,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc
	): bool {
		$this->datetime( $finished_at_utc );
		$this->public_ids( array( $result_public_id, $audit_public_id, $outbox_public_id ) );
		$routing = $gateway->routing();
		if ( ! $gateway->is_successful() || null === $routing || $item->attempt_no() < 1 ) {
			throw $this->invalid_work_item();
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->lock_matches( $item, 'running' ) || $this->result_exists( $item->run_pk() ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return false;
			}

			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'          => $item->evaluation_id(),
						'normalized_result_hash' => $result->normalized_hash(),
						'provider_output_digest' => $result->provider_output_digest(),
						'run_id'                 => $item->run_id(),
					)
				)
			);
			$this->insert_or_fail(
				self::TABLE_RESULTS,
				array(
					'public_id'                 => $result_public_id,
					'content_hash'              => $content_hash,
					'created_by_user_id'        => $item->actor_user_id(),
					'created_at_utc'            => $finished_at_utc,
					'submission_evaluation_id'  => $item->evaluation_pk(),
					'evaluation_run_id'         => $item->run_pk(),
					'output_schema_version'     => Submission_Score_Input_Builder::OUTPUT_VERSION,
					'candidate_score'           => $result->candidate_score(),
					'maximum_score'             => $result->maximum_score(),
					'overall_confidence'        => $result->overall_confidence(),
					'abstained'                 => $result->abstained() ? 1 : 0,
					'safety_flagged'            => $result->safety_flagged() ? 1 : 0,
					'summary'                   => $result->summary(),
					'normalized_result_schema'  => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
					'normalized_result_version' => self::PROFILE_VERSION,
					'normalized_result_json'    => $result->normalized_json(),
					'normalized_result_hash'    => $result->normalized_hash(),
					'provider_output_digest'    => $result->provider_output_digest(),
				)
			);
			$result_pk = $this->insert_id();
			$this->insert_items( $item, $result_pk, $result->items(), $finished_at_utc );
			$this->finish_attempt(
				$item,
				'gateway_succeeded',
				null,
				$gateway->correlation_id(),
				$result->provider_output_digest(),
				$gateway->duration_ms(),
				$finished_at_utc
			);

			$updated = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, '
						. 'result_type = %s, result_public_id = %s, provider_key = %s, model_key = %s, '
						. 'profile_fingerprint = %s, routing_revision = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
						. 'finished_at_utc = %s, last_error_code = NULL, updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					array(
						'succeeded',
						'submission_evaluation_result',
						$result_public_id,
						$routing->provider_key(),
						$routing->model_key(),
						$routing->profile_fingerprint(),
						$routing->routing_revision(),
						$finished_at_utc,
						$finished_at_utc,
						$item->run_pk(),
						$item->lock_version(),
						'running',
						$item->lease_token(),
					)
				)
			);
			if ( 1 !== $updated ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return false;
			}
			$this->transition_evaluation_state( $item, array( 'running' ), 'review_required', $finished_at_utc );
			$metadata = array(
				'attempt_no'             => $item->attempt_no(),
				'gateway_correlation_id' => $gateway->correlation_id(),
				'normalized_result_hash' => $result->normalized_hash(),
				'provider_output_digest' => $result->provider_output_digest(),
				'result_id'              => $result_public_id,
				'run_id'                 => $item->run_id(),
			);
			$this->insert_audit( $item, $audit_public_id, 'submission_evaluation.succeeded', $metadata, $finished_at_utc );
			$this->insert_outbox( $item, $outbox_public_id, 'submission_evaluation.review_required', $metadata, $finished_at_utc, $finished_at_utc );
			$this->assert_success_postcondition( $item, $result_public_id );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Evaluation_Worker_Failure ) {
				throw $error;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Complete failure.
	 *
	 * @param Submission_Evaluation_Work_Item             $item Item.
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
	public function complete_failure(
		Submission_Evaluation_Work_Item $item,
		string $attempt_state,
		string $error_code,
		?string $gateway_correlation_id,
		?string $provider_output_digest,
		?int $duration_ms,
		?Submission_Evaluation_Routing_Snapshot $actual_routing,
		bool $retry,
		string $available_at_utc,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc,
		?string $error_message = null
	): bool {
		$this->datetime( $available_at_utc );
		$this->datetime( $finished_at_utc );
		$this->error_code( $error_code );
		$error_message = $this->error_message( $error_message );
		$this->public_ids( array( $audit_public_id, $outbox_public_id ) );
		if (
			( 0 === $item->attempt_no() && '' !== $attempt_state )
			|| ( $item->attempt_no() > 0 && ! in_array( $attempt_state, array( 'gateway_failed', 'output_invalid', 'gateway_succeeded' ), true ) )
			|| ( $retry && $item->attempt_no() < 1 )
		) {
			throw $this->invalid_work_item();
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			if ( ! $this->lock_matches( $item, $item->state() ) || $this->result_exists( $item->run_pk() ) ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return false;
			}
			if ( $item->attempt_no() > 0 ) {
				$this->finish_attempt(
					$item,
					$attempt_state,
					$error_code,
					$gateway_correlation_id,
					$provider_output_digest,
					$duration_ms,
					$finished_at_utc
				);
			}

			$routing      = $actual_routing ?? $item->planned_routing();
			$next_state   = $retry ? 'retry_wait' : 'failed';
			$finished_sql = $retry ? 'finished_at_utc = NULL' : 'finished_at_utc = %s';
			$values       = array(
				$next_state,
				$routing->provider_key(),
				$routing->model_key(),
				$routing->profile_fingerprint(),
				$routing->routing_revision(),
				$available_at_utc,
				$error_code,
				$error_message,
			);
			if ( ! $retry ) {
				$values[] = $finished_at_utc;
			}
			$values  = array_merge( $values, array( $finished_at_utc, $item->run_pk(), $item->lock_version(), $item->state(), $item->lease_token() ) );
			$updated = $this->query(
				$this->prepare(
					'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, '
						. 'provider_key = %s, model_key = %s, profile_fingerprint = %s, routing_revision = %s, '
						. 'available_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, last_error_code = %s, '
						. 'last_error_message = %s, '
						. $finished_sql . ', updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND state = %s AND lease_token = %s',
					$values
				)
			);
			if ( 1 !== $updated ) {
				$this->query_or_fail( 'ROLLBACK' );
				$transaction = false;
				return false;
			}
			$this->transition_evaluation_state( $item, array( 'queued', 'running' ), $retry ? 'queued' : 'failed', $finished_at_utc );
			$event    = $retry ? 'submission_evaluation.retry_wait' : 'submission_evaluation.failed';
			$metadata = array(
				'attempt_no'             => $item->attempt_no(),
				'error_code'             => $error_code,
				'gateway_correlation_id' => $gateway_correlation_id,
				'provider_output_digest' => $provider_output_digest,
				'run_id'                 => $item->run_id(),
				'available_at_utc'       => $available_at_utc,
			);
			$this->insert_audit( $item, $audit_public_id, $event, $metadata, $finished_at_utc );
			$this->insert_outbox( $item, $outbox_public_id, $event, $metadata, $finished_at_utc, $available_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $error instanceof Submission_Evaluation_Worker_Failure ) {
				throw $error;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Work item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $lease_token Lease token.
	 * @param int                 $lock_version Lock version.
	 * @return Submission_Evaluation_Work_Item
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function work_item( array $row, string $lease_token, int $lock_version ): Submission_Evaluation_Work_Item {
		$input_json       = (string) ( $row['input_json'] ?? '' );
		$input_byte_size  = $this->positive_integer( $row['input_byte_size'] ?? null );
		$authorization    = $this->decode_json(
			(string) ( $row['authorization_envelope_json'] ?? '' ),
			(string) ( $row['authorization_envelope_hash'] ?? '' )
		);
		$input            = $this->decode_json( $input_json, (string) ( $row['input_hash'] ?? '' ) );
		$actor            = $this->positive_integer( $row['created_by_user_id'] ?? null );
		$revision_id      = (string) ( $row['submission_revision_public_id'] ?? '' );
		$source_hash      = (string) ( $row['submission_revision_hash'] ?? '' );
		$publication_id   = (string) ( $row['worksheet_publication_public_id'] ?? '' );
		$publication_hash = (string) ( $row['publication_hash'] ?? '' );
		$context          = $authorization['context'] ?? null;
		$object           = $authorization['object'] ?? null;
		$payload          = $input['operation_payload'] ?? null;
		$subject          = is_array( $payload ) ? ( $payload['subject'] ?? null ) : null;
		$output_contract  = is_array( $payload ) ? ( $payload['output_contract'] ?? null ) : null;

		$authorization_keys          = array_keys( $authorization );
		$expected_authorization_keys = array(
			'action_key',
			'actor_user_id',
			'checked_at_utc',
			'context',
			'object',
			'required_capabilities',
			'schema_id',
			'schema_version',
			'shell',
			'surface',
		);
		sort( $authorization_keys, SORT_STRING );
		sort( $expected_authorization_keys, SORT_STRING );
		$context_keys = is_array( $context ) ? array_keys( $context ) : array();
		$object_keys  = is_array( $object ) ? array_keys( $object ) : array();
		$input_keys   = array_keys( $input );
		sort( $context_keys, SORT_STRING );
		sort( $object_keys, SORT_STRING );
		sort( $input_keys, SORT_STRING );
		$payload_hash = is_array( $payload ) && ! array_is_list( $payload )
			? hash( 'sha256', $this->canonical_encode( $payload ) )
			: '';
		if (
			$authorization_keys !== $expected_authorization_keys
			|| 'oj_ai.authorization_envelope' !== ( $authorization['schema_id'] ?? null )
			|| '1.0.0' !== ( $authorization['schema_version'] ?? null )
			|| $actor !== ( $authorization['actor_user_id'] ?? null )
			|| 'console' !== ( $authorization['shell'] ?? null )
			|| 'rest' !== ( $authorization['surface'] ?? null )
			|| 'submission.evaluation.enqueue' !== ( $authorization['action_key'] ?? null )
			|| array( Addon_Capabilities::ASSESS_SUBMISSIONS ) !== ( $authorization['required_capabilities'] ?? null )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) ( $authorization['checked_at_utc'] ?? '' ) )
			|| ! is_array( $context ) || array_is_list( $context )
			|| array( 'id', 'operation', 'revision', 'type' ) !== $context_keys
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| 'evaluate' !== ( $context['operation'] ?? null )
			|| ! is_array( $object ) || array_is_list( $object )
			|| array( 'action', 'actor_relation', 'authorized_revision', 'authorized_state', 'id', 'official_revision_hash', 'official_revision_id', 'type' ) !== $object_keys
			|| 'submission' !== ( $object['type'] ?? null )
			|| 'evaluate' !== ( $object['action'] ?? null )
			|| 'context_staff' !== ( $object['actor_relation'] ?? null )
			|| ! hash_equals( $revision_id, (string) ( $object['official_revision_id'] ?? '' ) )
			|| ! hash_equals( $source_hash, (string) ( $object['official_revision_hash'] ?? '' ) )
			|| Addon_Manifest::TASK_STUDENT_ASSESSMENT !== ( $row['task_key'] ?? null )
			|| Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT !== ( $row['contract_version'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION !== ( $row['operation_key'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_VERSION !== ( $row['input_schema_version'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $row['output_schema_version'] ?? null )
			|| ! hash_equals( (string) ( $row['input_hash'] ?? '' ), (string) ( $row['input_digest'] ?? '' ) )
			|| $input_byte_size !== strlen( $input_json )
			|| $input_byte_size > Submission_Score_Input_Builder::MAXIMUM_INPUT_BYTES
			|| array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) !== $input_keys
			|| Submission_Score_Input_Builder::OPERATION !== ( $input['operation'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $input['operation_contract'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_SCHEMA !== ( $input['operation_schema'] ?? null )
			|| ! hash_equals( $payload_hash, (string) ( $input['payload_hash'] ?? '' ) )
			|| ! is_array( $subject ) || array_is_list( $subject )
			|| ! hash_equals( $source_hash, (string) ( $subject['source_hash'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $subject['publication_hash'] ?? '' ) )
			|| ! is_array( $output_contract ) || array_is_list( $output_contract )
			|| Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $output_contract['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $output_contract['schema_version'] ?? null )
			|| ! in_array( (string) ( $row['evaluation_state'] ?? '' ), array( 'queued', 'running' ), true )
		) {
			throw $this->invalid_work_item();
		}

		try {
			return new Submission_Evaluation_Work_Item(
				$this->positive_integer( $row['evaluation_pk'] ?? null ),
				$this->positive_integer( $row['run_pk'] ?? null ),
				(string) ( $row['evaluation_public_id'] ?? '' ),
				(string) ( $row['run_public_id'] ?? '' ),
				$actor,
				(string) ( $object['id'] ?? '' ),
				$this->non_negative_integer( $object['authorized_revision'] ?? null ),
				$revision_id,
				$source_hash,
				(string) ( $object['authorized_state'] ?? '' ),
				$publication_id,
				$publication_hash,
				(string) ( $context['type'] ),
				(string) ( $context['id'] ?? '' ),
				(string) ( $context['revision'] ?? '' ),
				(string) ( $row['contract_version'] ),
				(string) ( $row['input_digest'] ?? '' ),
				(string) ( $row['run_idempotency_key'] ?? '' ),
				$input,
				new Submission_Evaluation_Routing_Snapshot(
					(string) ( $row['task_key'] ),
					(string) ( $row['provider_key'] ?? '' ),
					(string) ( $row['model_key'] ?? '' ),
					(string) ( $row['profile_fingerprint'] ?? '' ),
					(string) ( $row['routing_revision'] ?? '' )
				),
				$lease_token,
				$lock_version,
				$this->non_negative_integer( $row['attempt_count'] ?? null ),
				0,
				'leased',
				(string) ( $row['queued_at_utc'] ?? '' )
			);
		} catch ( InvalidArgumentException ) {
			throw $this->invalid_work_item();
		}
	}

	/**
	 * Result exists.
	 *
	 * @param int $run_pk Run pk.
	 * @return bool
	 */
	private function result_exists( int $run_pk ): bool {
		return null !== $this->row(
			$this->prepare( 'SELECT id FROM ' . self::TABLE_RESULTS . ' WHERE evaluation_run_id = %d LIMIT 1 FOR UPDATE', array( $run_pk ) )
		);
	}

	/**
	 * Insert items.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param int                             $result_pk Result pk.
	 * @param array<int,array<string,mixed>>  $items Items.
	 * @param string                          $created_at_utc Created at utc.
	 * @return void
	 */
	private function insert_items( Submission_Evaluation_Work_Item $item, int $result_pk, array $items, string $created_at_utc ): void {
		foreach ( $items as $stored ) {
			$evidence_json = $this->canonical->encode( $stored['evidence'] );
			$flags         = array() === $stored['flags'] ? null : implode( ',', $stored['flags'] );
			$content_hash  = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'candidate_points' => $stored['candidate_points'],
						'confidence'       => $stored['confidence'],
						'criterion_key'    => $stored['criterion_key'],
						'evidence_hash'    => hash( 'sha256', $evidence_json ),
						'flags'            => $flags,
						'maximum_points'   => $stored['maximum_points'],
						'position'         => $stored['position'],
						'question_key'     => $stored['question_key'],
					)
				)
			);
			$this->insert_or_fail(
				self::TABLE_ITEMS,
				array(
					'content_hash'                    => $content_hash,
					'created_by_user_id'              => $item->actor_user_id(),
					'created_at_utc'                  => $created_at_utc,
					'submission_evaluation_result_id' => $result_pk,
					'criterion_key'                   => $stored['criterion_key'],
					'question_key'                    => $stored['question_key'],
					'position'                        => $stored['position'],
					'candidate_points'                => $stored['candidate_points'],
					'maximum_points'                  => $stored['maximum_points'],
					'confidence'                      => $stored['confidence'],
					'evidence_schema'                 => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
					'evidence_version'                => self::PROFILE_VERSION,
					'evidence_json'                   => $evidence_json,
					'evidence_hash'                   => hash( 'sha256', $evidence_json ),
					'flags'                           => $flags,
				)
			);
		}
	}

	/**
	 * Finish attempt.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $state State.
	 * @param string|null                     $error_code Error code.
	 * @param string|null                     $gateway_correlation_id Gateway correlation id.
	 * @param string|null                     $provider_output_digest Provider output digest.
	 * @param int|null                        $duration_ms Duration ms.
	 * @param string                          $finished_at_utc Finished at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finish_attempt(
		Submission_Evaluation_Work_Item $item,
		string $state,
		?string $error_code,
		?string $gateway_correlation_id,
		?string $provider_output_digest,
		?int $duration_ms,
		string $finished_at_utc
	): void {
		if ( null === $gateway_correlation_id || null === $duration_ms ) {
			throw $this->invalid_work_item();
		}
		$usage       = $this->usage();
		$assignments = array( 'state = %s' );
		$values      = array( $state );
		if ( null === $provider_output_digest ) {
			$assignments[] = 'provider_output_digest = NULL'; } else {
			$assignments[] = 'provider_output_digest = %s';
			$values[]      = $provider_output_digest;
			}
			$assignments   = array_merge( $assignments, array( 'usage_schema = %s', 'usage_version = %d', 'usage_json = %s', 'usage_hash = %s' ) );
			$values        = array_merge( $values, array( self::USAGE_SCHEMA, self::PROFILE_VERSION, $usage['json'], $usage['hash'] ) );
			$assignments[] = 'gateway_correlation_id = %s';
			$values[]      = $gateway_correlation_id;
			$assignments[] = 'duration_ms = %d';
			$values[]      = $duration_ms;
			if ( null === $error_code ) {
				$assignments[] = 'error_code = NULL'; } else {
						$assignments[] = 'error_code = %s';
						$values[]      = $error_code;
				}
				$assignments[] = 'finished_at_utc = %s';
				$values[]      = $finished_at_utc;
				$values        = array_merge( $values, array( $item->run_pk(), $item->attempt_no(), 'started' ) );
				$updated       = $this->query(
					$this->prepare(
						'UPDATE ' . self::TABLE_ATTEMPTS . ' SET ' . implode( ', ', $assignments )
						. ' WHERE evaluation_run_id = %d AND attempt_no = %d AND state = %s',
						$values
					)
				);
		if ( 1 !== $updated ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Transition evaluation state.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param array<int,string>               $from From.
	 * @param string                          $to To.
	 * @param string                          $updated_at_utc Updated at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function transition_evaluation_state( Submission_Evaluation_Work_Item $item, array $from, string $to, string $updated_at_utc ): void {
		$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$values       = array_merge( array( $to, $item->actor_user_id(), $updated_at_utc, $item->evaluation_pk() ), $from );
		$updated      = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_EVALUATIONS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND state IN (' . $placeholders . ')',
				$values
			)
		);
		if ( 1 !== $updated ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $public_id Public id.
	 * @param string                          $event Event.
	 * @param array<string,mixed>             $metadata Metadata.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( Submission_Evaluation_Work_Item $item, string $public_id, string $event, array $metadata, string $occurred_at_utc ): void {
		$previous_hash = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw $this->storage_failure();
		}
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$after_digest  = hash( 'sha256', $event . ':' . $item->run_id() . ':' . $metadata_hash );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $item->actor_user_id(),
					'after_digest'        => $after_digest,
					'before_digest'       => null,
					'correlation_id'      => $this->worker_correlation( $item ),
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $item->evaluation_id(),
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $occurred_at_utc,
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $occurred_at_utc,
				'actor_user_id'       => $item->actor_user_id(),
				'action_key'          => $event,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $item->evaluation_id(),
				'context_type'        => $item->context_type(),
				'context_id'          => $item->context_id(),
				'context_revision'    => $item->context_revision(),
				'correlation_id'      => $this->worker_correlation( $item ),
				'before_digest'       => null,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $public_id Public id.
	 * @param string                          $event Event.
	 * @param array<string,mixed>             $metadata Metadata.
	 * @param string                          $created_at_utc Created at utc.
	 * @param string                          $available_at_utc Available at utc.
	 * @return void
	 */
	private function insert_outbox(
		Submission_Evaluation_Work_Item $item,
		string $public_id,
		string $event,
		array $metadata,
		string $created_at_utc,
		string $available_at_utc
	): void {
		$payload      = array(
			'event'         => $event,
			'evaluation_id' => $item->evaluation_id(),
			'run_id'        => $item->run_id(),
			'metadata'      => $metadata,
		);
		$payload_json = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $item->actor_user_id(),
				'created_at_utc'       => $created_at_utc,
				'updated_by_user_id'   => $item->actor_user_id(),
				'updated_at_utc'       => $created_at_utc,
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $item->evaluation_id(),
				'event_type'           => $event,
				'idempotency_key'      => 'event:' . hash( 'sha256', $event . ':' . $item->run_id() . ':' . $item->attempt_no() . ':' . $available_at_utc ),
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $available_at_utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid success postcondition.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $result_public_id Result public id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_success_postcondition( Submission_Evaluation_Work_Item $item, string $result_public_id ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT r.state AS run_state, r.result_public_id, e.state AS evaluation_state, x.public_id AS stored_result_id FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.public_id = r.evaluation_public_id '
					. 'INNER JOIN ' . self::TABLE_RESULTS . ' x ON x.evaluation_run_id = r.id WHERE r.id = %d LIMIT 1',
				array( $item->run_pk() )
			)
		);
		if (
			null === $row || 'succeeded' !== ( $row['run_state'] ?? null ) || 'review_required' !== ( $row['evaluation_state'] ?? null )
			|| ! hash_equals( $result_public_id, (string) ( $row['result_public_id'] ?? '' ) )
			|| ! hash_equals( $result_public_id, (string) ( $row['stored_result_id'] ?? '' ) )
		) {
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
	 * Worker correlation.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return string
	 */
	private function worker_correlation( Submission_Evaluation_Work_Item $item ): string {
		return 'worker:' . substr( hash( 'sha256', $item->run_id() . ':' . $item->lease_token() ), 0, 32 );
	}

	/**
	 * Expire started attempt.
	 *
	 * @param int    $run_pk Run pk.
	 * @param string $finished_at_utc Finished at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function expire_started_attempt( int $run_pk, string $finished_at_utc ): void {
		$result = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_ATTEMPTS . ' SET state = %s, error_code = %s, finished_at_utc = %s '
					. 'WHERE evaluation_run_id = %d AND state = %s',
				array( 'gateway_failed', 'worker_lost', $finished_at_utc, $run_pk, 'started' )
			)
		);
		if ( $result < 0 || $result > 1 ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Lock matches.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $state State.
	 * @return bool
	 */
	private function lock_matches( Submission_Evaluation_Work_Item $item, string $state ): bool {
		$row = $this->row(
			$this->prepare(
				'SELECT r.id, r.state, r.lock_version, r.lease_token, r.result_public_id, e.state AS evaluation_state FROM ' . self::TABLE_RUNS . ' r INNER JOIN ' . self::TABLE_EVALUATIONS . ' e '
					. 'ON e.public_id = r.evaluation_public_id WHERE r.id = %d LIMIT 1 FOR UPDATE',
				array( $item->run_pk() )
			)
		);
		return null !== $row
			&& hash_equals( $state, (string) ( $row['state'] ?? '' ) )
			&& $item->lock_version() === $this->non_negative_integer( $row['lock_version'] ?? null )
			&& hash_equals( $item->lease_token(), (string) ( $row['lease_token'] ?? '' ) )
			&& in_array( (string) ( $row['evaluation_state'] ?? '' ), array( 'queued', 'running' ), true )
			&& null === ( $row['result_public_id'] ?? null );
	}

	/**
	 * Update evaluation state.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $updated_at_utc Updated at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_evaluation_state( Submission_Evaluation_Work_Item $item, string $updated_at_utc ): void {
		$updated = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_EVALUATIONS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND state IN (%s,%s)',
				array( 'running', $item->actor_user_id(), $updated_at_utc, $item->evaluation_pk(), 'queued', 'running' )
			)
		);
		if ( 1 !== $updated ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( string $json, string $expected_hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw $this->invalid_work_item();
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw $this->invalid_work_item();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical_encode( $value ) ) ) {
			throw $this->invalid_work_item();
		}
		return $value;
	}

	/**
	 * Canonical encode.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_encode( array $value ): string {
		try {
			return $this->canonical->encode( $value );
		} catch ( Throwable ) {
			throw $this->invalid_work_item();
		}
	}

	/**
	 * Datetime.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw $this->invalid_work_item();
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
			throw $this->invalid_work_item();
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
			throw $this->invalid_work_item();
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
		}
		if ( '' === trim( $value ) || strlen( $value ) > 500 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw $this->invalid_work_item();
		}
		return $value;
	}

	/**
	 * Public ids.
	 *
	 * @param array<int,string> $values Values.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_ids( array $values ): void {
		try {
			foreach ( $values as $value ) {
				new Public_Id( $value );
			}
		} catch ( InvalidArgumentException ) {
			throw $this->invalid_work_item();
		}
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
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw $this->invalid_work_item();
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
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw $this->invalid_work_item();
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
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$row = $this->database()->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
		return $row;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scalar( string $sql ) {
		if ( ! method_exists( $this->database(), 'get_var' ) ) {
			throw $this->storage_failure();
		}
		$value = $this->database()->get_var( $sql );
		if ( '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
		return $value;
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
		}
		return (int) $result;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$result = $this->database()->query( $sql );
		if ( false === $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			throw $this->storage_failure();
		}
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
	 * Insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id(): int {
		$id = (int) ( $this->database()->insert_id ?? 0 );
		if ( $id < 1 ) {
			throw $this->storage_failure();
		}
		return $id;
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
		if (
			! is_object( $database )
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_row' )
			|| ! method_exists( $database, 'query' )
			|| ! method_exists( $database, 'insert' )
		) {
			throw $this->storage_failure();
		}
		return $database;
	}

	/**
	 * Invalid work item.
	 *
	 * @return Submission_Evaluation_Worker_Failure
	 */
	private function invalid_work_item(): Submission_Evaluation_Worker_Failure {
		return Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
	}

	/**
	 * Storage failure.
	 *
	 * @return Submission_Evaluation_Worker_Failure
	 */
	private function storage_failure(): Submission_Evaluation_Worker_Failure {
		return Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::STORAGE_UNAVAILABLE );
	}
}
