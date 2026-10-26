<?php
/**
 * Atomic C31 retry and C32 cooperative cancellation repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic C31 retry and C32 cooperative cancellation repository.
 */
final class WordPress_Submission_Evaluation_Control_Repository implements Submission_Evaluation_Control_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_ATTEMPTS    = 'oj_ai_evaluation_attempts';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_RETRY     = 'submission_evaluation.retry_queued';
	private const EVENT_CANCEL    = 'submission_evaluation.cancelled';
	private const AUDIT_SCHEMA    = 'oj_ai.submission_evaluation_control_audit';
	private const OUTBOX_SCHEMA   = 'oj_ai.submission_evaluation_control_event';
	private const PROFILE_VERSION = 1;

	/**
	 * Initialize WordPress submission evaluation control repository dependencies and state.
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
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @return Submission_Evaluation_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest,
		string $operation
	): ?Submission_Evaluation_Control_Result {
		$this->assert_storage_key( $command_storage_key );
		$this->assert_hash( $request_digest );
		$this->assert_operation( $operation );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, $operation, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Find snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_snapshot( string $evaluation_id ): ?array {
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $evaluation_id, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Retry.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function retry( array $record ): Submission_Evaluation_Control_Result {
		$this->assert_retry_record( $record );
		$receipt = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			Submission_Evaluation_Control_Result::OPERATION_RETRY,
			false
		);
		if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				Submission_Evaluation_Control_Result::OPERATION_RETRY,
				true
			);
			if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot( (string) $record['evaluation_public_id'], true );
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_retry_transition( $snapshot, $record );
			$this->insert_retry_run( $snapshot, $record );
			$this->update_evaluation( $snapshot, $record, 'queued' );
			$result = $this->result_from_record( $record, Submission_Evaluation_Control_Result::OPERATION_RETRY, false );
			$this->insert_audit( $snapshot, $record, $result, self::EVENT_RETRY );
			$this->insert_outbox( $record, $result, self::EVENT_RETRY );
			$this->assert_retry_postcondition( $record );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record, Submission_Evaluation_Control_Result::OPERATION_RETRY );
			if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cancel( array $record ): Submission_Evaluation_Control_Result {
		$this->assert_cancel_record( $record );
		$receipt = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			Submission_Evaluation_Control_Result::OPERATION_CANCEL,
			false
		);
		if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				Submission_Evaluation_Control_Result::OPERATION_CANCEL,
				true
			);
			if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot( (string) $record['evaluation_public_id'], true );
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_cancel_transition( $snapshot, $record );
			$this->cancel_started_attempt( (int) $snapshot['latest_run_pk'], (string) $record['occurred_at_utc'] );
			$this->cancel_run( $snapshot, $record );
			$this->update_evaluation( $snapshot, $record, 'cancelled' );
			$result = $this->result_from_record( $record, Submission_Evaluation_Control_Result::OPERATION_CANCEL, false );
			$this->insert_audit( $snapshot, $record, $result, self::EVENT_CANCEL );
			$this->insert_outbox( $record, $result, self::EVENT_CANCEL );
			$this->assert_cancel_postcondition( $record );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record, Submission_Evaluation_Control_Result::OPERATION_CANCEL );
			if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $evaluation_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash AS source_hash, e.worksheet_publication_public_id AS publication_id, e.publication_hash, e.policy_revision_public_id, e.policy_hash, e.rubric_revision_public_id, e.rubric_hash, e.review_rule_revision_public_id, e.review_rule_hash, e.prompt_contract_version, e.input_schema_version, e.output_schema_version, e.idempotency_key AS evaluation_idempotency_key, e.authorization_envelope_json, e.authorization_envelope_hash, revision.content_hash AS source_content_hash, revision.state AS source_revision_state, s.public_id AS submission_id, s.state AS submission_state, s.official_revision_public_id, a.public_id AS assignment_id, a.state AS assignment_state, a.context_type, a.context_id, a.context_revision, a.worksheet_publication_public_id AS assignment_publication_id, a.publication_hash AS assignment_publication_hash, target.state AS target_state, input.input_json, input.input_hash, input.input_byte_size, input.artifact_references_json, input.artifact_references_hash, run.id AS latest_run_pk, run.public_id AS latest_run_id, run.lock_version AS latest_run_lock_version, run.run_no AS latest_run_no, run.state AS latest_run_state, run.task_key AS latest_run_task_key, run.contract_version AS latest_run_contract_version, run.operation_key AS latest_run_operation_key, run.input_schema_version AS latest_run_input_schema_version, run.output_schema_version AS latest_run_output_schema_version, run.input_digest AS latest_run_input_digest, run.result_public_id AS latest_run_result_id, (SELECT result.public_id FROM ' . self::TABLE_RESULTS . ' result WHERE result.submission_evaluation_id = e.id '
			. 'ORDER BY result.created_at_utc DESC, result.id DESC LIMIT 1) AS latest_result_id, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' result WHERE result.submission_evaluation_id = e.id) AS result_count '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_REVISIONS . ' revision '
			. 'ON revision.public_id = e.submission_revision_public_id INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s '
			. 'ON s.id = revision.submission_id INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = a.id AND target.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' input ON input.submission_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' run ON run.subject_type = %s AND run.evaluation_public_id = e.public_id '
			. 'AND run.run_no = (SELECT MAX(latest.run_no) FROM ' . self::TABLE_RUNS . ' latest '
			. 'WHERE latest.subject_type = %s AND latest.evaluation_public_id = e.public_id) '
			. 'WHERE e.public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( 'submission_evaluation', 'submission_evaluation', $evaluation_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$input_json          = (string) ( $row['input_json'] ?? '' );
		$input_hash          = $this->hash_value( $row['input_hash'] ?? null );
		$input               = $this->object( $this->decode_canonical( $input_json, $input_hash ) );
		$artifact_references = $this->decode_canonical(
			(string) ( $row['artifact_references_json'] ?? '' ),
			$this->hash_value( $row['artifact_references_hash'] ?? null )
		);
		if ( ! is_array( $artifact_references ) || ! array_is_list( $artifact_references ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}

		$state            = $this->evaluation_state( $row['evaluation_state'] ?? null );
		$run_state        = $this->run_state( $row['latest_run_state'] ?? null );
		$latest_result_id = $this->nullable_public_id( $row['latest_result_id'] ?? null );
		$result_count     = $this->non_negative_integer( $row['result_count'] ?? null );
		$snapshot         = array(
			'evaluation_pk'              => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'              => $this->public_id( $row['evaluation_id'] ?? null ),
			'evaluation_lock_version'    => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'           => $state,
			'submission_id'              => $this->public_id( $row['submission_id'] ?? null ),
			'submission_revision_id'     => $this->public_id( $row['submission_revision_id'] ?? null ),
			'source_hash'                => $this->hash_value( $row['source_hash'] ?? null ),
			'publication_id'             => $this->public_id( $row['publication_id'] ?? null ),
			'publication_hash'           => $this->hash_value( $row['publication_hash'] ?? null ),
			'context_type'               => $this->context_type( $row['context_type'] ?? null ),
			'context_id'                 => $this->context_token( $row['context_id'] ?? null ),
			'context_revision'           => $this->context_token( $row['context_revision'] ?? null ),
			'input_hash'                 => $input_hash,
			'evaluation_identity_digest' => $this->evaluation_identity_digest( $row['evaluation_idempotency_key'] ?? null ),
			'latest_run_pk'              => $this->positive_integer( $row['latest_run_pk'] ?? null ),
			'latest_run_id'              => $this->public_id( $row['latest_run_id'] ?? null ),
			'latest_run_lock_version'    => $this->non_negative_integer( $row['latest_run_lock_version'] ?? null ),
			'latest_run_no'              => $this->positive_integer( $row['latest_run_no'] ?? null ),
			'latest_run_state'           => $run_state,
			'latest_run_result_id'       => $this->nullable_public_id( $row['latest_run_result_id'] ?? null ),
			'latest_result_id'           => $latest_result_id,
			'result_count'               => $result_count,
			'source_is_current'          => $this->source_is_current( $row ),
		);
		$this->assert_snapshot_integrity( $snapshot, $row, $input_json, $input, $artifact_references );
		return $snapshot;
	}

	/**
	 * Reject invalid snapshot integrity.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $row Row.
	 * @param string              $input_json Input json.
	 * @param array<string,mixed> $input Input.
	 * @param array<int,mixed>    $artifact_references Artifact references.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_snapshot_integrity( array $snapshot, array $row, string $input_json, array $input, array $artifact_references ): void {
		$this->exact_keys( $input, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) );
		$payload                 = $this->object( $input['operation_payload'] ?? null );
		$subject                 = $this->object( $payload['subject'] ?? null );
		$policy                  = $this->object( $payload['policy'] ?? null );
		$policy_rules            = $this->object( $policy['rules'] ?? null );
		$rubric                  = $this->object( $payload['rubric'] ?? null );
		$review_rule_revision_id = $this->public_id( $row['review_rule_revision_public_id'] ?? null );
		$this->hash_value( $row['review_rule_hash'] ?? null );
		$instruction           = $this->object( $payload['instruction'] ?? null );
		$output                = $this->object( $payload['output_contract'] ?? null );
		$authorization         = $this->object(
			$this->decode_canonical(
				(string) ( $row['authorization_envelope_json'] ?? '' ),
				$this->hash_value( $row['authorization_envelope_hash'] ?? null )
			)
		);
		$authorization_context = $this->object( $authorization['context'] ?? null );
		$authorization_object  = $this->object( $authorization['object'] ?? null );
		$capabilities          = $authorization['required_capabilities'] ?? null;
		$payload_json          = $this->canonical->encode( $payload );
		$latest_has_result     = null !== $snapshot['latest_run_result_id'];
		if (
			strlen( $input_json ) !== $this->positive_integer( $row['input_byte_size'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION !== ( $input['operation'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $input['operation_contract'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_SCHEMA !== ( $input['operation_schema'] ?? null )
			|| ! hash_equals( hash( 'sha256', $payload_json ), $this->hash_value( $input['payload_hash'] ?? null ) )
			|| Submission_Score_Input_Builder::OPERATION !== ( $payload['operation'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_VERSION !== ( $payload['schema_version'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null )
			|| ! is_string( $subject['evaluation_ref'] ?? null )
			|| 1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', (string) $subject['evaluation_ref'] )
			|| ! hash_equals( $snapshot['source_hash'], (string) ( $subject['source_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot['publication_hash'], (string) ( $subject['publication_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot['source_hash'], $this->hash_value( $row['source_content_hash'] ?? null ) )
			|| ! hash_equals( $snapshot['submission_revision_id'], $this->public_id( $row['official_revision_public_id'] ?? null ) )
			|| ! hash_equals( $snapshot['publication_id'], $this->public_id( $row['assignment_publication_id'] ?? null ) )
			|| ! hash_equals( $snapshot['publication_hash'], $this->hash_value( $row['assignment_publication_hash'] ?? null ) )
			|| ! hash_equals( $this->public_id( $row['policy_revision_public_id'] ?? null ), (string) ( $policy['revision_ref'] ?? '' ) )
			|| ! hash_equals( $this->hash_value( $row['policy_hash'] ?? null ), (string) ( $policy['content_hash'] ?? '' ) )
			|| ! hash_equals( $this->public_id( $row['rubric_revision_public_id'] ?? null ), (string) ( $rubric['revision_ref'] ?? '' ) )
			|| ! hash_equals( $this->hash_value( $row['rubric_hash'] ?? null ), (string) ( $rubric['content_hash'] ?? '' ) )
			|| true !== ( $policy_rules['assessment_enabled'] ?? null )
			|| ! hash_equals( $this->public_id( $row['rubric_revision_public_id'] ?? null ), (string) ( $policy_rules['rubric_revision_id'] ?? '' ) )
			|| ! hash_equals( $review_rule_revision_id, (string) ( $policy_rules['review_rule_revision_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $row['prompt_contract_version'] ?? '' ), (string) ( $instruction['contract_version'] ?? '' ) )
			|| ! hash_equals( (string) ( $row['input_schema_version'] ?? '' ), (string) ( $payload['schema_version'] ) )
			|| ! hash_equals( (string) ( $row['output_schema_version'] ?? '' ), (string) ( $output['schema_version'] ) )
			|| Addon_Manifest::TASK_STUDENT_ASSESSMENT !== ( $row['latest_run_task_key'] ?? null )
			|| Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT !== ( $row['latest_run_contract_version'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION !== ( $row['latest_run_operation_key'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_VERSION !== ( $row['latest_run_input_schema_version'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $row['latest_run_output_schema_version'] ?? null )
			|| ! hash_equals( $snapshot['input_hash'], (string) ( $row['latest_run_input_digest'] ?? '' ) )
			|| ! is_array( $capabilities ) || array( Addon_Capabilities::ASSESS_SUBMISSIONS ) !== $capabilities
			|| 'console' !== ( $authorization['shell'] ?? null )
			|| 'rest' !== ( $authorization['surface'] ?? null )
			|| 'submission.evaluation.enqueue' !== ( $authorization['action_key'] ?? null )
			|| $snapshot['context_type'] !== ( $authorization_context['type'] ?? null )
			|| $snapshot['context_id'] !== ( $authorization_context['id'] ?? null )
			|| $snapshot['context_revision'] !== ( $authorization_context['revision'] ?? null )
			|| $snapshot['submission_id'] !== ( $authorization_object['id'] ?? null )
			|| $snapshot['submission_revision_id'] !== ( $authorization_object['official_revision_id'] ?? null )
			|| $snapshot['source_hash'] !== ( $authorization_object['official_revision_hash'] ?? null )
			|| ( 0 === $snapshot['result_count'] ) !== ( null === $snapshot['latest_result_id'] )
			|| ( 'succeeded' === $snapshot['latest_run_state'] ) !== $latest_has_result
			|| ( $latest_has_result && $snapshot['latest_run_result_id'] !== $snapshot['latest_result_id'] )
			|| ! $this->state_pair_is_valid( $snapshot['evaluation_state'], $snapshot['latest_run_state'] )
			|| ! in_array( $row['source_revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| ! in_array( $row['assignment_state'] ?? null, array( 'scheduled', 'open', 'closed' ), true )
			|| 'active' !== ( $row['target_state'] ?? null )
			|| count( $artifact_references ) > Submission_Score_Input_Builder::MAXIMUM_ARTIFACTS
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Source is current.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return bool
	 */
	private function source_is_current( array $row ): bool {
		return isset( $row['submission_revision_id'], $row['official_revision_public_id'], $row['source_hash'], $row['source_content_hash'] )
			&& is_string( $row['submission_revision_id'] ) && is_string( $row['official_revision_public_id'] )
			&& is_string( $row['source_hash'] ) && is_string( $row['source_content_hash'] )
			&& hash_equals( $row['submission_revision_id'], $row['official_revision_public_id'] )
			&& hash_equals( $row['source_hash'], $row['source_content_hash'] )
			&& 'active' === ( $row['target_state'] ?? null )
			&& 'withdrawn' !== ( $row['assignment_state'] ?? null );
	}

	/**
	 * State pair is valid.
	 *
	 * @param string $evaluation_state Evaluation state.
	 * @param string $run_state Run state.
	 * @return bool
	 */
	private function state_pair_is_valid( string $evaluation_state, string $run_state ): bool {
		return match ( $evaluation_state ) {
			'requested', 'queued'       => in_array( $run_state, array( 'queued', 'retry_wait', 'leased' ), true ),
			'running'                   => 'running' === $run_state,
			'review_required', 'reviewed'=> 'succeeded' === $run_state,
			'failed'                    => 'failed' === $run_state,
			'cancelled'                 => 'cancelled' === $run_state,
			default                     => false,
		};
	}

	/**
	 * Reject invalid retry transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_retry_transition( array $snapshot, array $record ): void {
		$this->assert_common_transition( $snapshot, $record );
		$terminal_pair =
			( in_array( $snapshot['evaluation_state'], array( 'review_required', 'reviewed' ), true ) && 'succeeded' === $snapshot['latest_run_state'] )
			|| ( 'failed' === $snapshot['evaluation_state'] && 'failed' === $snapshot['latest_run_state'] )
			|| ( 'cancelled' === $snapshot['evaluation_state'] && 'cancelled' === $snapshot['latest_run_state'] );
		if ( ! $terminal_pair || (int) $record['run_no'] !== (int) $snapshot['latest_run_no'] + 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Reject invalid cancel transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_transition( array $snapshot, array $record ): void {
		$this->assert_common_transition( $snapshot, $record );
		if (
			! in_array( $snapshot['evaluation_state'], array( 'queued', 'running' ), true )
			|| ! in_array( $snapshot['latest_run_state'], array( 'queued', 'retry_wait', 'leased', 'running' ), true )
			|| null !== $snapshot['latest_run_result_id']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Reject invalid common transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_common_transition( array $snapshot, array $record ): void {
		if (
			! hash_equals( $snapshot['evaluation_id'], (string) $record['evaluation_public_id'] )
			|| ! hash_equals( $snapshot['submission_id'], (string) $record['submission_public_id'] )
			|| ! hash_equals( $snapshot['submission_revision_id'], (string) $record['submission_revision_public_id'] )
			|| ! hash_equals( $snapshot['source_hash'], (string) $record['submission_revision_hash'] )
			|| ! hash_equals( $snapshot['publication_id'], (string) $record['worksheet_publication_public_id'] )
			|| ! hash_equals( $snapshot['publication_hash'], (string) $record['publication_hash'] )
			|| ! hash_equals( $snapshot['context_type'], (string) $record['context_type'] )
			|| ! hash_equals( $snapshot['context_id'], (string) $record['context_id'] )
			|| ! hash_equals( $snapshot['context_revision'], (string) $record['context_revision'] )
			|| ! hash_equals( $snapshot['evaluation_state'], (string) $record['expected_evaluation_state'] )
			|| (int) $snapshot['evaluation_lock_version'] !== (int) $record['expected_evaluation_lock_version']
			|| ! hash_equals( $snapshot['latest_run_id'], (string) $record['expected_run_public_id'] )
			|| (int) $snapshot['latest_run_no'] !== (int) $record['expected_run_no']
			|| ! hash_equals( $snapshot['latest_run_state'], (string) $record['expected_run_state'] )
			|| (int) $snapshot['latest_run_lock_version'] !== (int) $record['expected_run_lock_version']
			|| $snapshot['latest_result_id'] !== $record['expected_latest_result_id']
			|| (int) $snapshot['result_count'] !== (int) $record['expected_result_count']
			|| true !== $snapshot['source_is_current']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert retry run.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_retry_run( array $snapshot, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_RUNS,
			array(
				'public_id'             => $record['run_public_id'],
				'lock_version'          => 0,
				'created_by_user_id'    => $record['actor_user_id'],
				'created_at_utc'        => $record['occurred_at_utc'],
				'updated_by_user_id'    => $record['actor_user_id'],
				'updated_at_utc'        => $record['occurred_at_utc'],
				'subject_type'          => 'submission_evaluation',
				'evaluation_public_id'  => $record['evaluation_public_id'],
				'run_no'                => $record['run_no'],
				'state'                 => 'queued',
				'task_key'              => $record['task_key'],
				'contract_version'      => Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
				'operation_key'         => Submission_Score_Input_Builder::OPERATION,
				'input_schema_version'  => Submission_Score_Input_Builder::REQUEST_VERSION,
				'output_schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'input_digest'          => $snapshot['input_hash'],
				'result_type'           => null,
				'result_public_id'      => null,
				'idempotency_key'       => $record['run_idempotency_key'],
				'correlation_id'        => $record['correlation_id'],
				'provider_key'          => $record['provider_key'],
				'model_key'             => $record['model_key'],
				'profile_fingerprint'   => $record['profile_fingerprint'],
				'routing_revision'      => $record['routing_revision'],
				'available_at_utc'      => $record['occurred_at_utc'],
				'queued_at_utc'         => $record['occurred_at_utc'],
				'lease_token'           => null,
				'lease_expires_at_utc'  => null,
				'started_at_utc'        => null,
				'finished_at_utc'       => null,
				'last_error_code'       => null,
			)
		);
	}

	/**
	 * Cancel run.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cancel_run( array $snapshot, array $record ): void {
		$updated = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
					. 'finished_at_utc = %s, last_error_code = %s WHERE id = %d AND lock_version = %d '
					. 'AND state = %s AND result_public_id IS NULL',
				array(
					'cancelled',
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$record['occurred_at_utc'],
					'cancelled_by_user',
					$snapshot['latest_run_pk'],
					$snapshot['latest_run_lock_version'],
					$snapshot['latest_run_state'],
				)
			)
		);
		if ( 1 !== $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Cancel started attempt.
	 *
	 * @param int    $run_pk Run pk.
	 * @param string $finished_at_utc Finished at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cancel_started_attempt( int $run_pk, string $finished_at_utc ): void {
		$updated = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_ATTEMPTS . ' SET state = %s, error_code = %s, finished_at_utc = %s '
					. 'WHERE evaluation_run_id = %d AND state = %s',
				array( 'cancelled', 'cancelled_by_user', $finished_at_utc, $run_pk, 'started' )
			)
		);
		if ( $updated < 0 || $updated > 1 ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Update evaluation.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @param string              $state State.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_evaluation( array $snapshot, array $record, string $state ): void {
		$updated = $this->query(
			$this->prepare(
				'UPDATE ' . self::TABLE_EVALUATIONS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s',
				array(
					$state,
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$snapshot['evaluation_pk'],
					$snapshot['evaluation_lock_version'],
					$snapshot['evaluation_state'],
				)
			)
		);
		if ( 1 !== $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STATE_CONFLICT, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>                  $snapshot Snapshot.
	 * @param array<string,mixed>                  $record Record.
	 * @param Submission_Evaluation_Control_Result $result Result.
	 * @param string                               $event Event.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $snapshot, array $record, Submission_Evaluation_Control_Result $result, string $event ): void {
		$previous_hash = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw $this->storage_failure();
		}
		$before_digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_state' => $snapshot['evaluation_state'],
					'latest_result_id' => $snapshot['latest_result_id'],
					'result_count'     => $snapshot['result_count'],
					'run_id'           => $snapshot['latest_run_id'],
					'run_no'           => $snapshot['latest_run_no'],
					'run_state'        => $snapshot['latest_run_state'],
				)
			)
		);
		$after_digest  = hash( 'sha256', $this->canonical->encode( $this->receipt_payload( $result ) ) );
		$metadata      = array(
			'command_id'      => $record['command_id'],
			'operation'       => $result->operation(),
			'previous_run_id' => $snapshot['latest_run_id'],
			'previous_run_no' => $snapshot['latest_run_no'],
			'reason'          => $record['reason'],
			'request_digest'  => $record['request_digest'],
			'run_id'          => $result->run_id(),
			'run_no'          => $result->run_no(),
		);
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before_digest,
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
	 * @param array<string,mixed>                  $record Record.
	 * @param Submission_Evaluation_Control_Result $result Result.
	 * @param string                               $event Event.
	 * @return void
	 */
	private function insert_outbox( array $record, Submission_Evaluation_Control_Result $result, string $event ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => $event,
				'operation'      => $result->operation(),
				'reason_digest'  => hash( 'sha256', (string) $record['reason'] ),
				'request_digest' => $record['request_digest'],
				'receipt'        => $this->receipt_payload( $result ),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $record['evaluation_public_id'],
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['occurred_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @param bool   $for_update For update.
	 * @return Submission_Evaluation_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $command_storage_key, string $request_digest, string $operation, bool $for_update ): ?Submission_Evaluation_Control_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $command_storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		$expected_event = Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation ? self::EVENT_RETRY : self::EVENT_CANCEL;
		if (
			$expected_event !== ( $row['event_type'] ?? null )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw $this->storage_failure();
		}
		$payload = $this->object(
			$this->decode_canonical( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) )
		);
		if (
			! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) )
			|| ! hash_equals( $operation, (string) ( $payload['operation'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $this->object( $payload['receipt'] ?? null );
		if ( ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['evaluation_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @return Submission_Evaluation_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record, string $operation ): ?Submission_Evaluation_Control_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], $operation, false );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Evaluation_Control_Result
	 */
	private function result_from_record( array $record, string $operation, bool $replayed ): Submission_Evaluation_Control_Result {
		$is_retry = Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation;
		return new Submission_Evaluation_Control_Result(
			$operation,
			(string) $record['evaluation_public_id'],
			$is_retry ? 'queued' : 'cancelled',
			(string) $record['submission_public_id'],
			(string) $record['submission_revision_public_id'],
			(string) $record['submission_revision_hash'],
			(string) $record['worksheet_publication_public_id'],
			(string) $record['publication_hash'],
			(string) ( $is_retry ? $record['run_public_id'] : $record['expected_run_public_id'] ),
			(int) ( $is_retry ? $record['run_no'] : $record['expected_run_no'] ),
			$is_retry ? 'queued' : 'cancelled',
			$record['expected_latest_result_id'],
			(string) $record['occurred_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Submission_Evaluation_Control_Result {
		try {
			return new Submission_Evaluation_Control_Result(
				(string) ( $receipt['operation'] ?? '' ),
				(string) ( $receipt['evaluation_id'] ?? '' ),
				(string) ( $receipt['evaluation_state'] ?? '' ),
				(string) ( $receipt['submission_id'] ?? '' ),
				(string) ( $receipt['submission_revision_id'] ?? '' ),
				(string) ( $receipt['source_hash'] ?? '' ),
				(string) ( $receipt['publication_id'] ?? '' ),
				(string) ( $receipt['publication_hash'] ?? '' ),
				(string) ( $receipt['run_id'] ?? '' ),
				$this->positive_integer( $receipt['run_no'] ?? null ),
				(string) ( $receipt['run_state'] ?? '' ),
				$this->nullable_public_id( $receipt['latest_result_id'] ?? null ),
				(string) ( $receipt['occurred_at_utc'] ?? '' ),
				$replayed
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Receipt payload.
	 *
	 * @param Submission_Evaluation_Control_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Submission_Evaluation_Control_Result $result ): array {
		return array(
			'operation'              => $result->operation(),
			'evaluation_id'          => $result->evaluation_id(),
			'evaluation_state'       => $result->evaluation_state(),
			'submission_id'          => $result->submission_id(),
			'submission_revision_id' => $result->submission_revision_id(),
			'source_hash'            => $result->source_hash(),
			'publication_id'         => $result->publication_id(),
			'publication_hash'       => $result->publication_hash(),
			'run_id'                 => $result->run_id(),
			'run_no'                 => $result->run_no(),
			'run_state'              => $result->run_state(),
			'latest_result_id'       => $result->latest_result_id(),
			'occurred_at_utc'        => $result->occurred_at_utc(),
		);
	}

	/**
	 * Reject invalid retry postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_retry_postcondition( array $record ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT e.state AS evaluation_state, run.state AS run_state, run.run_no, run.input_digest, run.result_public_id, (SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' result WHERE result.submission_evaluation_id = e.id) AS result_count '
					. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_RUNS . ' run '
					. 'ON run.evaluation_public_id = e.public_id AND run.subject_type = %s '
					. 'WHERE e.public_id = %s AND run.public_id = %s LIMIT 1',
				array( 'submission_evaluation', $record['evaluation_public_id'], $record['run_public_id'] )
			)
		);
		if (
			null === $row || 'queued' !== ( $row['evaluation_state'] ?? null ) || 'queued' !== ( $row['run_state'] ?? null )
			|| (int) $record['run_no'] !== $this->positive_integer( $row['run_no'] ?? null )
			|| ! hash_equals( (string) $record['input_hash'], (string) ( $row['input_digest'] ?? '' ) )
			|| null !== ( $row['result_public_id'] ?? null )
			|| (int) $record['expected_result_count'] !== $this->non_negative_integer( $row['result_count'] ?? null )
		) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid cancel postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_postcondition( array $record ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT e.state AS evaluation_state, run.state AS run_state, run.lease_token, run.result_public_id, (SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' result WHERE result.submission_evaluation_id = e.id) AS result_count '
					. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_RUNS . ' run '
					. 'ON run.evaluation_public_id = e.public_id AND run.subject_type = %s '
					. 'WHERE e.public_id = %s AND run.public_id = %s LIMIT 1',
				array( 'submission_evaluation', $record['evaluation_public_id'], $record['expected_run_public_id'] )
			)
		);
		if (
			null === $row || 'cancelled' !== ( $row['evaluation_state'] ?? null ) || 'cancelled' !== ( $row['run_state'] ?? null )
			|| null !== ( $row['lease_token'] ?? null ) || null !== ( $row['result_public_id'] ?? null )
			|| (int) $record['expected_result_count'] !== $this->non_negative_integer( $row['result_count'] ?? null )
		) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid retry record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_retry_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'command_id',
			'command_storage_key',
			'request_digest',
			'reason',
			'correlation_id',
			'occurred_at_utc',
			'context_type',
			'context_id',
			'context_revision',
			'evaluation_public_id',
			'submission_public_id',
			'submission_revision_public_id',
			'submission_revision_hash',
			'worksheet_publication_public_id',
			'publication_hash',
			'input_hash',
			'expected_evaluation_state',
			'expected_evaluation_lock_version',
			'expected_run_public_id',
			'expected_run_no',
			'expected_run_state',
			'expected_run_lock_version',
			'expected_latest_result_id',
			'expected_result_count',
			'run_public_id',
			'run_no',
			'run_idempotency_key',
			'audit_public_id',
			'outbox_public_id',
			'task_key',
			'provider_key',
			'model_key',
			'profile_fingerprint',
			'routing_revision',
		);
		$this->exact_keys( $record, $expected );
		$this->assert_common_record( $record );
		if (
			! in_array( $record['expected_evaluation_state'], array( 'review_required', 'reviewed', 'failed', 'cancelled' ), true )
			|| ! in_array( $record['expected_run_state'], array( 'succeeded', 'failed', 'cancelled' ), true )
			|| ! is_int( $record['run_no'] ) || $record['run_no'] !== $record['expected_run_no'] + 1
			|| Addon_Manifest::TASK_STUDENT_ASSESSMENT !== $record['task_key']
		) {
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation record is invalid.' );
		}
		foreach ( array(
			'provider_key'     => 100,
			'routing_revision' => 191,
		) as $key => $maximum ) {
			$this->safe_token( $record[ $key ] ?? null, $key, 1, $maximum );
		}
		$this->model_token( $record['model_key'] ?? null, 'model_key', 1, 100 );
		$this->assert_hash( (string) $record['profile_fingerprint'] );
		$this->assert_hash( (string) $record['input_hash'] );
		$this->assert_prefixed_hash( $record['run_idempotency_key'] ?? null, 'run:' );
		$this->public_id( $record['run_public_id'] ?? null );
	}

	/**
	 * Reject invalid cancel record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'command_id',
			'command_storage_key',
			'request_digest',
			'reason',
			'correlation_id',
			'occurred_at_utc',
			'context_type',
			'context_id',
			'context_revision',
			'evaluation_public_id',
			'submission_public_id',
			'submission_revision_public_id',
			'submission_revision_hash',
			'worksheet_publication_public_id',
			'publication_hash',
			'expected_evaluation_state',
			'expected_evaluation_lock_version',
			'expected_run_public_id',
			'expected_run_no',
			'expected_run_state',
			'expected_run_lock_version',
			'expected_latest_result_id',
			'expected_result_count',
			'audit_public_id',
			'outbox_public_id',
		);
		$this->exact_keys( $record, $expected );
		$this->assert_common_record( $record );
		if (
			! in_array( $record['expected_evaluation_state'], array( 'queued', 'running' ), true )
			|| ! in_array( $record['expected_run_state'], array( 'queued', 'retry_wait', 'leased', 'running' ), true )
		) {
			throw new InvalidArgumentException( 'CancelSubmissionEvaluation record is invalid.' );
		}
	}

	/**
	 * Reject invalid common record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_common_record( array $record ): void {
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0
			|| ! is_int( $record['expected_run_lock_version'] ) || $record['expected_run_lock_version'] < 0
			|| ! is_int( $record['expected_run_no'] ) || $record['expected_run_no'] < 1
			|| ! is_int( $record['expected_result_count'] ) || $record['expected_result_count'] < 0
			|| ( null !== $record['expected_latest_result_id'] && ! is_string( $record['expected_latest_result_id'] ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation control record identity is invalid.' );
		}
		foreach ( array( 'evaluation_public_id', 'submission_public_id', 'submission_revision_public_id', 'worksheet_publication_public_id', 'expected_run_public_id', 'audit_public_id', 'outbox_public_id' ) as $key ) {
			$this->public_id( $record[ $key ] ?? null );
		}
		if ( null !== $record['expected_latest_result_id'] ) {
			$this->public_id( $record['expected_latest_result_id'] );
		}
		$this->assert_hash( (string) $record['submission_revision_hash'] );
		$this->assert_hash( (string) $record['publication_hash'] );
		$this->assert_hash( (string) $record['request_digest'] );
		$this->assert_storage_key( (string) $record['command_storage_key'] );
		$this->safe_token( $record['command_id'] ?? null, 'command_id', 8, 128 );
		$this->safe_token( $record['correlation_id'] ?? null, 'correlation_id', 1, 100 );
		$this->assert_reason( $record['reason'] ?? null );
		$this->context_type( $record['context_type'] ?? null );
		$this->context_token( $record['context_id'] ?? null );
		$this->context_token( $record['context_revision'] ?? null );
		$this->datetime( $record['occurred_at_utc'] ?? null );
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_state'    => (string) ( $snapshot['evaluation_state'] ?? '' ),
			'current_run_id'   => (string) ( $snapshot['latest_run_id'] ?? '' ),
			'current_run_no'   => (string) ( $snapshot['latest_run_no'] ?? '' ),
			'latest_result_id' => (string) ( $snapshot['latest_result_id'] ?? '' ),
		);
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Decode canonical.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical( string $json, string $expected_hash ) {
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			return $value;
	}

	/**
	 * Evaluation identity digest.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_identity_digest( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^eval:([a-f0-9]{64})$/D', $value, $matches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $matches[1];
	}

	/**
	 * Evaluation state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Run state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'queued', 'leased', 'running', 'succeeded', 'retry_wait', 'failed', 'cancelled' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Reject invalid operation.
	 *
	 * @param string $operation Operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_operation( string $operation ): void {
		if ( ! in_array( $operation, array( Submission_Evaluation_Control_Result::OPERATION_RETRY, Submission_Evaluation_Control_Result::OPERATION_CANCEL ), true ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control operation is invalid.' );
		}
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function assert_storage_key( string $value ): void {
		$this->assert_prefixed_hash( $value, 'command:' );
	}

	/**
	 * Reject invalid prefixed hash.
	 *
	 * @param mixed  $value Value.
	 * @param string $prefix Prefix.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_prefixed_hash( $value, string $prefix ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation storage key is invalid.' );
		}
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation hash is invalid.' );
		}
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Nullable public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id( $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_type( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Context token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * Safe token.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( $value, string $field, int $minimum, int $maximum ): void {
		if ( ! is_string( $value ) || strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Model token.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function model_token( $value, string $field, int $minimum, int $maximum ): void {
		if ( ! is_string( $value ) || strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Reject invalid reason.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_reason( $value ): void {
		if ( ! is_string( $value ) || $value !== trim( $value ) || strlen( $value ) < 3 || strlen( $value ) > 1000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation reason is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Submission evaluation UTC timestamp is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation control value contains unknown or missing fields.' );
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
			! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' )
			|| ! method_exists( $database, 'get_var' ) || ! method_exists( $database, 'query' ) || ! method_exists( $database, 'insert' )
		) {
			throw $this->storage_failure();
		}
		return $database;
	}

	/**
	 * Storage failure.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function storage_failure(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
}
