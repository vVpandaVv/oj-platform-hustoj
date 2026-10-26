<?php
/**
 * Atomic student submission evaluation enqueue repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Manual_Submission_Score;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the evaluation root, immutable input, first run, audit, and outbox together.
 */
final class WordPress_Submission_Evaluation_Repository implements Submission_Evaluation_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS      = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_TYPE         = 'submission_evaluation.queued';
	private const MANUAL_EVENT_TYPE  = 'submission_evaluation.manual_prepared';
	private const RECEIPT_EVENT_TYPE = 'submission_evaluation.enqueue_replayed';
	private const OUTBOX_SCHEMA      = 'oj_ai.submission_evaluation_queued_event';
	private const AUDIT_SCHEMA       = 'oj_ai.submission_evaluation_audit_metadata';
	private const ARTIFACT_SCHEMA    = 'oj_ai.submission_artifact_references';
	private const PROFILE_VERSION    = 1;

	/**
	 * Initialize WordPress submission evaluation repository dependencies and state.
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
	 * @return Enqueue_Submission_Evaluation_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Enqueue_Submission_Evaluation_Result {
		$this->assert_hash( $request_digest, 'request_digest' );
		$this->assert_storage_key( $command_storage_key, 'command_storage_key' );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Submission_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Enqueue_Submission_Evaluation_Result {
		$this->assert_record( $record );
		$command_replay = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			false
		);
		if ( $command_replay instanceof Enqueue_Submission_Evaluation_Result ) {
			return $command_replay;
		}

		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$command_replay      = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				true
			);
			if ( $command_replay instanceof Enqueue_Submission_Evaluation_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $command_replay;
			}

			$semantic_replay = $this->evaluation_receipt( $record, true );
			if ( $semantic_replay instanceof Enqueue_Submission_Evaluation_Result ) {
				$this->insert_outbox( $record, $semantic_replay, true );
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $semantic_replay;
			}

			if ( isset( $record['manual_result'] ) ) {
				$this->assert_manual_source( $record );
			}
			$this->insert_evaluation( $record );
			$evaluation_pk = $this->insert_id();
			$this->insert_input( $evaluation_pk, $record );
			$this->insert_run( $record );
			if ( isset( $record['manual_result'] ) ) {
				$this->insert_manual_result( $evaluation_pk, $this->insert_id(), $record );
			}
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record );
			$this->insert_outbox( $record, $result, false );
			$this->assert_postcondition( $evaluation_pk, $record );

			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			$replay = $this->recover_concurrent_receipt( $record );
			if ( $replay instanceof Enqueue_Submission_Evaluation_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert evaluation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_evaluation( array $record ): void {
		$config = $record['configuration'];
		$this->insert_or_fail(
			self::TABLE_EVALUATIONS,
			array(
				'public_id'                       => $record['evaluation_public_id'],
				'lock_version'                    => 0,
				'created_by_user_id'              => $record['actor_user_id'],
				'created_at_utc'                  => $record['queued_at_utc'],
				'updated_by_user_id'              => $record['actor_user_id'],
				'updated_at_utc'                  => $record['queued_at_utc'],
				'submission_revision_public_id'   => $record['submission_revision_public_id'],
				'submission_revision_hash'        => $record['submission_revision_hash'],
				'worksheet_publication_public_id' => $record['worksheet_publication_public_id'],
				'publication_hash'                => $record['publication_hash'],
				'policy_revision_public_id'       => $config['policy']['revision_id'],
				'policy_hash'                     => $config['policy']['content_hash'],
				'rubric_revision_public_id'       => $config['rubric']['revision_id'],
				'rubric_hash'                     => $config['rubric']['content_hash'],
				'review_rule_revision_public_id'  => $config['review_rule']['revision_id'],
				'review_rule_hash'                => $config['review_rule']['content_hash'],
				'prompt_contract_version'         => $config['prompt']['contract_version'],
				'input_schema_version'            => $config['input_schema']['schema_version'],
				'output_schema_version'           => $config['output_schema']['schema_version'],
				'state'                           => isset( $record['manual_result'] ) ? 'review_required' : 'queued',
				'idempotency_key'                 => $record['evaluation_idempotency_key'],
				'requested_at_utc'                => $record['queued_at_utc'],
				'authorization_envelope_version'  => '1.0.0',
				'authorization_envelope_json'     => $record['authorization_envelope_json'],
				'authorization_envelope_hash'     => $record['authorization_envelope_hash'],
				'enqueue_correlation_id'          => $record['correlation_id'],
			)
		);
	}

	/**
	 * Insert input.
	 *
	 * @param int                 $evaluation_pk Evaluation pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_input( int $evaluation_pk, array $record ): void {
		$content_json = $this->canonical->encode(
			array(
				'artifact_references_hash' => $record['artifact_references_hash'],
				'binding_manifest_hash'    => $record['binding_manifest_hash'],
				'evaluation_public_id'     => $record['evaluation_public_id'],
				'input_byte_size'          => $record['input_byte_size'],
				'input_hash'               => $record['input_hash'],
				'redaction_version'        => $record['redaction_version'],
				'representation_set_hash'  => $record['representation_set_hash'],
				'source_snapshot_hash'     => $record['source_snapshot_hash'],
			)
		);
		$this->insert_or_fail(
			self::TABLE_INPUTS,
			array(
				'public_id'                   => $record['input_public_id'],
				'content_hash'                => hash( 'sha256', $content_json ),
				'created_by_user_id'          => $record['actor_user_id'],
				'created_at_utc'              => $record['queued_at_utc'],
				'submission_evaluation_id'    => $evaluation_pk,
				'projection_schema_version'   => Submission_Score_Input_Builder::OPERATION_CONTRACT,
				'input_schema'                => Submission_Score_Input_Builder::REQUEST_SCHEMA,
				'input_version'               => self::PROFILE_VERSION,
				'input_json'                  => $record['input_json'],
				'input_hash'                  => $record['input_hash'],
				'artifact_references_schema'  => self::ARTIFACT_SCHEMA,
				'artifact_references_version' => self::PROFILE_VERSION,
				'artifact_references_json'    => $record['artifact_references_json'],
				'artifact_references_hash'    => $record['artifact_references_hash'],
				'input_byte_size'             => $record['input_byte_size'],
				'redaction_version'           => $record['redaction_version'],
			)
		);
	}

	/**
	 * Insert run.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_run( array $record ): void {
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
				'run_no'                => 1,
				'state'                 => isset( $record['manual_result'] ) ? 'succeeded' : 'queued',
				'task_key'              => $record['task_key'],
				'contract_version'      => Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
				'operation_key'         => isset( $record['manual_result'] ) ? Manual_Submission_Score::OPERATION : Submission_Score_Input_Builder::OPERATION,
				'input_schema_version'  => Submission_Score_Input_Builder::REQUEST_VERSION,
				'output_schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'input_digest'          => $record['input_hash'],
				'result_type'           => isset( $record['manual_result'] ) ? 'submission_evaluation_result' : null,
				'result_public_id'      => $record['manual_result_public_id'] ?? null,
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
				'finished_at_utc'       => isset( $record['manual_result'] ) ? $record['queued_at_utc'] : null,
				'last_error_code'       => null,
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $record ): void {
		$event_type    = isset( $record['manual_result'] ) ? self::MANUAL_EVENT_TYPE : self::EVENT_TYPE;
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'artifact_references_hash'   => $record['artifact_references_hash'],
				'binding_manifest_hash'      => $record['binding_manifest_hash'],
				'command_id'                 => $record['command_id'],
				'configuration_hash'         => $record['configuration_hash'],
				'evaluation_idempotency_key' => $record['evaluation_idempotency_key'],
				'evaluation_reference'       => $record['evaluation_reference'],
				'input_digest'               => $record['input_hash'],
				'profile_fingerprint'        => $record['profile_fingerprint'],
				'publication_hash'           => $record['publication_hash'],
				'representation_set_hash'    => $record['representation_set_hash'],
				'request_digest'             => $record['request_digest'],
				'routing_revision'           => $record['routing_revision'],
				'source_snapshot_hash'       => $record['source_snapshot_hash'],
				'submission_revision_hash'   => $record['submission_revision_hash'],
				'submission_revision_id'     => $record['submission_revision_public_id'],
				'worksheet_publication_id'   => $record['worksheet_publication_public_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$after_digest  = substr( (string) $record['evaluation_idempotency_key'], 5 );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event_type,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $record['queued_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['queued_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event_type,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => $record['authorized_context_type'],
				'context_id'          => $record['authorized_context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
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
	 * @param array<string,mixed>                  $record Record.
	 * @param Enqueue_Submission_Evaluation_Result $result Result.
	 * @param bool                                 $receipt_only Receipt only.
	 * @return void
	 */
	private function insert_outbox( array $record, Enqueue_Submission_Evaluation_Result $result, bool $receipt_only ): void {
		$event_type   = $receipt_only ? self::RECEIPT_EVENT_TYPE : ( isset( $record['manual_result'] ) ? self::MANUAL_EVENT_TYPE : self::EVENT_TYPE );
		$payload_json = $this->canonical->encode(
			array(
				'command_id'                 => $record['command_id'],
				'evaluation_idempotency_key' => $record['evaluation_idempotency_key'],
				'event'                      => $event_type,
				'input_digest'               => $record['input_hash'],
				'owner_user_id'              => $record['actor_user_id'],
				'receipt'                    => array(
					'evaluation_id'          => $result->evaluation_id(),
					'evaluation_state'       => $result->evaluation_state(),
					'publication_hash'       => $result->publication_hash(),
					'publication_id'         => $result->publication_id(),
					'queued_at_utc'          => $result->queued_at_utc(),
					'run_id'                 => $result->run_id(),
					'run_no'                 => $result->run_no(),
					'run_state'              => $result->run_state(),
					'source_hash'            => $result->source_hash(),
					'submission_id'          => $result->submission_id(),
					'submission_revision_id' => $result->submission_revision_id(),
				),
				'request_digest'             => $record['request_digest'],
				'routing_revision'           => $record['routing_revision'],
				'task_key'                   => $record['task_key'],
			)
		);
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
				'aggregate_public_id'  => $result->evaluation_id(),
				'event_type'           => $event_type,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['queued_at_utc'],
				'published_at_utc'     => $receipt_only ? $record['queued_at_utc'] : null,
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
	 * @param bool   $for_update For update.
	 * @return Enqueue_Submission_Evaluation_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt(
		string $command_storage_key,
		string $request_digest,
		bool $for_update
	): ?Enqueue_Submission_Evaluation_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $command_storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			! in_array( (string) ( $row['event_type'] ?? '' ), array( self::EVENT_TYPE, self::MANUAL_EVENT_TYPE, self::RECEIPT_EVENT_TYPE ), true )
			|| self::OUTBOX_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_stored_object(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		if ( ! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt ) || array_is_list( $receipt )
			|| ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['evaluation_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Evaluation receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $for_update For update.
	 * @return Enqueue_Submission_Evaluation_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_receipt( array $record, bool $for_update ): ?Enqueue_Submission_Evaluation_Result {
		$sql = 'SELECT e.id, e.public_id AS evaluation_public_id, e.state AS evaluation_state, e.submission_revision_public_id, e.submission_revision_hash, e.worksheet_publication_public_id, e.publication_hash, e.requested_at_utc, i.input_hash, i.artifact_references_hash, r.public_id AS run_public_id, r.run_no, r.state AS run_state FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.submission_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' r ON r.evaluation_public_id = e.public_id '
			. 'AND r.subject_type = %s AND r.run_no = 1 '
			. 'WHERE e.idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row(
			$this->prepare( $sql, array( 'submission_evaluation', $record['evaluation_idempotency_key'] ) )
		);
		if ( null === $row ) {
			return null;
		}
		if (
			! hash_equals( (string) $record['submission_revision_public_id'], (string) ( $row['submission_revision_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['submission_revision_hash'], (string) ( $row['submission_revision_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['worksheet_publication_public_id'], (string) ( $row['worksheet_publication_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['publication_hash'], (string) ( $row['publication_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['input_hash'], (string) ( $row['input_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['artifact_references_hash'], (string) ( $row['artifact_references_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->result_from_receipt(
			array(
				'evaluation_id'          => $row['evaluation_public_id'] ?? null,
				'evaluation_state'       => $row['evaluation_state'] ?? null,
				'publication_hash'       => $row['publication_hash'] ?? null,
				'publication_id'         => $row['worksheet_publication_public_id'] ?? null,
				'queued_at_utc'          => $row['requested_at_utc'] ?? null,
				'run_id'                 => $row['run_public_id'] ?? null,
				'run_no'                 => $row['run_no'] ?? null,
				'run_state'              => $row['run_state'] ?? null,
				'source_hash'            => $row['submission_revision_hash'] ?? null,
				'submission_id'          => $record['submission_public_id'],
				'submission_revision_id' => $row['submission_revision_public_id'] ?? null,
			),
			true
		);
	}

	/**
	 * Recover concurrent receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Submission_Evaluation_Result|null
	 */
	private function recover_concurrent_receipt( array $record ): ?Enqueue_Submission_Evaluation_Result {
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$command_replay      = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				true
			);
			if ( $command_replay instanceof Enqueue_Submission_Evaluation_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $command_replay;
			}
			$semantic_replay = $this->evaluation_receipt( $record, true );
			if ( ! $semantic_replay instanceof Enqueue_Submission_Evaluation_Result ) {
				$this->rollback();
				return null;
			}
			$this->insert_outbox( $record, $semantic_replay, true );
			$this->query_or_fail( 'COMMIT' );
			return $semantic_replay;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			try {
				return $this->command_receipt(
					(string) $record['command_storage_key'],
					(string) $record['request_digest'],
					false
				);
			} catch ( Throwable ) {
				return null;
			}
		}
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param int                 $evaluation_pk Evaluation pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( int $evaluation_pk, array $record ): void {
		$sql = 'SELECT e.public_id AS evaluation_public_id, i.public_id AS input_public_id, r.public_id AS run_public_id FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.submission_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' r ON r.evaluation_public_id = e.public_id '
			. 'AND r.subject_type = %s AND r.run_no = 1 WHERE e.id = %d LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( 'submission_evaluation', $evaluation_pk ) ) );
		if (
			null === $row
			|| ! hash_equals( (string) $record['evaluation_public_id'], (string) ( $row['evaluation_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['input_public_id'], (string) ( $row['input_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['run_public_id'], (string) ( $row['run_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Enqueue_Submission_Evaluation_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Enqueue_Submission_Evaluation_Result {
		return new Enqueue_Submission_Evaluation_Result(
			(string) $record['evaluation_public_id'],
			isset( $record['manual_result'] ) ? 'review_required' : 'queued',
			(string) $record['submission_public_id'],
			(string) $record['submission_revision_public_id'],
			(string) $record['submission_revision_hash'],
			(string) $record['worksheet_publication_public_id'],
			(string) $record['publication_hash'],
			(string) $record['run_public_id'],
			1,
			isset( $record['manual_result'] ) ? 'succeeded' : 'queued',
			(string) $record['queued_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Enqueue_Submission_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Enqueue_Submission_Evaluation_Result {
		try {
			return new Enqueue_Submission_Evaluation_Result(
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
				(string) ( $receipt['queued_at_utc'] ?? '' ),
				$replayed
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$required = array(
			'actor_user_id',
			'artifact_references_hash',
			'artifact_references_json',
			'audit_public_id',
			'authorization_envelope_hash',
			'authorization_envelope_json',
			'authorized_context_id',
			'authorized_context_revision',
			'authorized_context_type',
			'binding_manifest_hash',
			'command_id',
			'command_storage_key',
			'configuration',
			'configuration_hash',
			'correlation_id',
			'evaluation_idempotency_key',
			'evaluation_public_id',
			'evaluation_reference',
			'input_byte_size',
			'input_hash',
			'input_json',
			'input_public_id',
			'model_key',
			'outbox_public_id',
			'profile_fingerprint',
			'provider_key',
			'publication_hash',
			'queued_at_utc',
			'redaction_version',
			'representation_set_hash',
			'request_digest',
			'routing_revision',
			'run_idempotency_key',
			'run_public_id',
			'source_snapshot_hash',
			'submission_public_id',
			'submission_revision_hash',
			'submission_revision_public_id',
			'task_key',
			'worksheet_publication_public_id',
		);
		$manual   = isset( $record['manual_result'] );
		if ( $manual ) {
			$required[] = 'manual_result';
			$required[] = 'manual_result_public_id';
			new Public_Id( (string) $record['manual_result_public_id'] );
			if ( Manual_Submission_Score::TASK !== $record['task_key'] || 'not_requested' !== ( $record['manual_result']['provider_status'] ?? null ) ) {
				throw new InvalidArgumentException( 'Manual score sheet contract is invalid.' );
			}
		}
		$actual = array_keys( $record );
		sort( $required, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $required !== $actual || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation enqueue record is incomplete.' );
		}
		foreach ( array( 'evaluation_public_id', 'input_public_id', 'run_public_id', 'audit_public_id', 'outbox_public_id', 'submission_public_id', 'submission_revision_public_id', 'worksheet_publication_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'artifact_references_hash', 'authorization_envelope_hash', 'binding_manifest_hash', 'configuration_hash', 'input_hash', 'profile_fingerprint', 'publication_hash', 'representation_set_hash', 'request_digest', 'source_snapshot_hash', 'submission_revision_hash' ) as $key ) {
			if ( $manual && 'profile_fingerprint' === $key && null === $record[ $key ] ) {
				continue;
			}
			$this->assert_hash( (string) $record[ $key ], $key );
		}
		foreach ( array( 'command_storage_key', 'evaluation_idempotency_key', 'run_idempotency_key' ) as $key ) {
			$this->assert_storage_key( (string) $record[ $key ], $key );
		}
		$this->assert_configuration( $record['configuration'], (string) $record['configuration_hash'] );
		$this->assert_input_json( $record );
		if (
			1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', (string) $record['evaluation_reference'] )
			|| Submission_Score_Input_Builder::REDACTION_VERSION !== $record['redaction_version']
			|| ! in_array( $record['authorized_context_type'], array( 'class', 'group' ), true )
			|| ! $this->safe_token( $record['authorized_context_id'], 191, true )
			|| ! $this->safe_token( $record['authorized_context_revision'], 191, true )
			|| ! $this->safe_token( $record['command_id'], 128 )
			|| ! $this->safe_token( $record['correlation_id'], 100 )
			|| ! $this->safe_token( $record['task_key'], 191 )
			|| ( ! $manual && ! $this->safe_token( $record['provider_key'], 100 ) )
			|| ( ! $manual && ! $this->safe_token( $record['model_key'], 100, true ) )
			|| ( ! $manual && ! $this->safe_token( $record['routing_revision'], 191 ) )
			|| ( $manual && ( null !== $record['provider_key'] || null !== $record['model_key'] || null !== $record['profile_fingerprint'] || null !== $record['routing_revision'] ) )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $record['queued_at_utc'] )
		) {
			throw new InvalidArgumentException( 'Submission evaluation enqueue record values are invalid.' );
		}
	}

	/**
	 * Recheck the official submission under a lock before creating a score sheet.
	 *
	 * @param array<string,mixed> $record Prepared command.
	 * @return void
	 * @throws Submission_Evaluation_Failure If the submission changed or was already approved.
	 */
	private function assert_manual_source( array $record ): void {
		$row = $this->get_row(
			$this->prepare(
				'SELECT s.official_revision_public_id, revision.content_hash, s.official_review_public_id FROM oj_ai_submissions s INNER JOIN oj_ai_submission_revisions revision ON revision.submission_id = s.id AND revision.public_id = s.official_revision_public_id INNER JOIN oj_ai_assignments a ON a.id = s.assignment_id INNER JOIN oj_ai_assignment_targets t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id WHERE s.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s AND t.state = %s AND a.state <> %s AND s.state IN (%s, %s, %s, %s) LIMIT 1 FOR UPDATE',
				array( $record['submission_public_id'], $record['authorized_context_type'], $record['authorized_context_id'], $record['authorized_context_revision'], 'active', 'withdrawn', 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' )
			)
		);
		if ( null === $row || $row['official_revision_public_id'] !== $record['submission_revision_public_id'] || $row['content_hash'] !== $record['submission_revision_hash'] || null !== $row['official_review_public_id'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable conflict mapped by the REST boundary.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Persist the unscored rubric and its local preparation provenance atomically.
	 *
	 * @param int                 $evaluation_pk Evaluation primary key.
	 * @param int                 $run_pk Local preparation primary key.
	 * @param array<string,mixed> $record Prepared command.
	 * @return void
	 */
	private function insert_manual_result( int $evaluation_pk, int $run_pk, array $record ): void {
		$result = $record['manual_result'];
		$json   = $this->canonical->encode( $result );
		$hash   = hash( 'sha256', $json );
		// No provider response exists. The digest identifies the local preparation contract.
		$digest       = hash( 'sha256', Manual_Submission_Score::OPERATION . ':' . $record['input_hash'] );
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'          => $record['evaluation_public_id'],
					'normalized_result_hash' => $hash,
					'provider_output_digest' => $digest,
					'run_id'                 => $record['run_public_id'],
				)
			)
		);
		$this->insert_or_fail(
			'oj_ai_submission_evaluation_results',
			array(
				'public_id'                 => $record['manual_result_public_id'],
				'content_hash'              => $content_hash,
				'created_by_user_id'        => $record['actor_user_id'],
				'created_at_utc'            => $record['queued_at_utc'],
				'submission_evaluation_id'  => $evaluation_pk,
				'evaluation_run_id'         => $run_pk,
				'output_schema_version'     => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'candidate_score'           => null,
				'maximum_score'             => $result['maximum_score'],
				'overall_confidence'        => '0.0000',
				'abstained'                 => 1,
				'safety_flagged'            => 0,
				'summary'                   => $result['summary'],
				'normalized_result_schema'  => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
				'normalized_result_version' => 1,
				'normalized_result_json'    => $json,
				'normalized_result_hash'    => $hash,
				'provider_output_digest'    => $digest,
			)
		);
		$result_pk = $this->insert_id();
		foreach ( $result['items'] as $item ) {
			$evidence      = array_merge(
				array(
					'schema_id'      => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
					'schema_version' => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
				),
				array_intersect_key( $item, array_flip( array( 'binding_key', 'decision', 'rationale', 'abstain_reason', 'evidence' ) ) )
			);
			$evidence_json = $this->canonical->encode( $evidence );
			$values        = array(
				'candidate_points' => null,
				'confidence'       => '0.0000',
				'criterion_key'    => $item['criterion_id'],
				'evidence_hash'    => hash( 'sha256', $evidence_json ),
				'flags'            => null,
				'maximum_points'   => $item['maximum_points'],
				'position'         => $item['position'],
				'question_key'     => $item['response_id'],
			);
			$this->insert_or_fail(
				'oj_ai_submission_evaluation_items',
				array_merge(
					$values,
					array(
						'content_hash'                    => hash( 'sha256', $this->canonical->encode( $values ) ),
						'created_by_user_id'              => $record['actor_user_id'],
						'created_at_utc'                  => $record['queued_at_utc'],
						'submission_evaluation_result_id' => $result_pk,
						'evidence_schema'                 => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
						'evidence_version'                => 1,
						'evidence_json'                   => $evidence_json,
					)
				)
			);
		}
	}

	/**
	 * Reject invalid configuration.
	 *
	 * @param mixed  $configuration Configuration.
	 * @param string $configuration_hash Configuration hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_configuration( $configuration, string $configuration_hash ): void {
		if ( ! is_array( $configuration ) || array_is_list( $configuration ) ) {
			throw new InvalidArgumentException( 'Submission evaluation configuration is invalid.' );
		}
		$expected = array( 'input_schema', 'output_schema', 'policy', 'prompt', 'review_rule', 'rubric' );
		$actual   = array_keys( $configuration );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected || ! hash_equals( $configuration_hash, hash( 'sha256', $this->canonical->encode( $configuration ) ) ) ) {
			throw new InvalidArgumentException( 'Submission evaluation configuration is invalid.' );
		}
		foreach ( array( 'policy', 'rubric', 'review_rule' ) as $name ) {
			$profile = $configuration[ $name ] ?? null;
			if ( ! is_array( $profile ) || array_is_list( $profile ) ) {
				throw new InvalidArgumentException( 'Submission evaluation configuration profile is invalid.' );
			}
			new Public_Id( (string) ( $profile['revision_id'] ?? '' ) );
			$this->assert_hash( (string) ( $profile['content_hash'] ?? '' ), $name . '_hash' );
		}
		if (
			Submission_Score_Input_Builder::REQUEST_SCHEMA !== ( $configuration['input_schema']['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_VERSION !== ( $configuration['input_schema']['schema_version'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $configuration['output_schema']['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $configuration['output_schema']['schema_version'] ?? null )
			|| Submission_Score_Input_Builder::PROMPT_CONTRACT !== ( $configuration['prompt']['contract_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Submission evaluation configuration contract is invalid.' );
		}
	}

	/**
	 * Reject invalid input json.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_input_json( array $record ): void {
		if (
			! is_string( $record['input_json'] )
			|| ! hash_equals( (string) $record['input_hash'], hash( 'sha256', $record['input_json'] ) )
			|| ! is_int( $record['input_byte_size'] )
			|| $record['input_byte_size'] !== strlen( $record['input_json'] )
			|| $record['input_byte_size'] < 1
			|| $record['input_byte_size'] > Submission_Score_Input_Builder::MAXIMUM_INPUT_BYTES
			|| ! is_string( $record['artifact_references_json'] )
			|| ! hash_equals( (string) $record['artifact_references_hash'], hash( 'sha256', $record['artifact_references_json'] ) )
			|| ! is_string( $record['authorization_envelope_json'] )
			|| ! hash_equals( (string) $record['authorization_envelope_hash'], hash( 'sha256', $record['authorization_envelope_json'] ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation input snapshot is invalid.' );
		}
		try {
			$input         = json_decode( $record['input_json'], true, 64, JSON_THROW_ON_ERROR );
			$artifacts     = json_decode( $record['artifact_references_json'], true, 64, JSON_THROW_ON_ERROR );
			$authorization = json_decode( $record['authorization_envelope_json'], true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'Submission evaluation JSON snapshot is invalid.' );
		}
		if (
			! is_array( $input ) || array_is_list( $input )
			|| ! is_array( $artifacts ) || ! array_is_list( $artifacts )
			|| ! is_array( $authorization ) || array_is_list( $authorization )
			|| ! hash_equals( $record['input_json'], $this->canonical->encode( $input ) )
			|| ! hash_equals( $record['artifact_references_json'], $this->canonical->encode( $artifacts ) )
			|| ! hash_equals( $record['authorization_envelope_json'], $this->canonical->encode( $authorization ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation JSON snapshot is not canonical.' );
		}
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $value, string $field ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value, string $field ): void {
		if ( strlen( $value ) > 191 || 1 !== preg_match( '/^[a-z]+:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Safe token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_slash Allow slash.
	 * @return bool
	 */
	private function safe_token( $value, int $maximum, bool $allow_slash = false ): bool {
		$pattern = $allow_slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( $pattern, $value );
	}

	/**
	 * Decode stored object.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored_object( string $json, string $hash ): array {
		try {
			$this->assert_hash( $hash, 'json_hash' );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
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
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id(): int {
		$value = $this->database()->insert_id ?? null;
		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return (int) $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_read( $database );
		return $value;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->query( $sql ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
		}
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
