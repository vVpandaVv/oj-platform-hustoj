<?php
/**
 * Atomic worksheet self-evaluation enqueue repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Repository_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists evaluation root, P04 input, first run, audit, and outbox together.
 */
final class WordPress_Worksheet_Evaluation_Repository implements Worksheet_Evaluation_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_worksheet_evaluations';
	private const TABLE_INPUTS      = 'oj_ai_worksheet_evaluation_inputs';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_TYPE         = 'worksheet_evaluation.queued';
	private const RECEIPT_EVENT_TYPE = 'worksheet_evaluation.enqueue_replayed';
	private const OUTBOX_SCHEMA      = 'oj_ai.worksheet_evaluation_queued_event';
	private const AUDIT_SCHEMA       = 'oj_ai.worksheet_evaluation_audit_metadata';
	private const INPUT_REDACTION    = 'worksheet_quality_redaction.v1';
	private const PROFILE_VERSION    = 1;

	/**
	 * Initialize WordPress worksheet evaluation repository dependencies and state.
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
	 * @return Enqueue_Worksheet_Evaluation_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Enqueue_Worksheet_Evaluation_Result {
		$this->assert_hash( $request_digest, 'request_digest' );
		$this->assert_storage_key( $command_storage_key, 'command_storage_key' );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Worksheet_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Evaluation_Result {
		$this->assert_record( $record );
		$command_replay = $this->command_receipt(
			(string) $record['command_storage_key'],
			(string) $record['request_digest'],
			false
		);
		if ( $command_replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
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
			if ( $command_replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $command_replay;
			}
			$semantic_replay = $this->evaluation_receipt( $record, true );
			if ( $semantic_replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
				$this->insert_outbox( $record, $semantic_replay, true );
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $semantic_replay;
			}

			$this->insert_evaluation( $record );
			$evaluation_pk = $this->insert_id();
			$this->insert_input( $evaluation_pk, $record );
			$this->insert_run( $record );
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record );
			$this->insert_outbox( $record, $result, false );
			$this->assert_postcondition( $evaluation_pk, $record );

			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			$replay = $this->recover_concurrent_receipt( $record );
			if ( $replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
				'public_id'                      => $record['evaluation_public_id'],
				'lock_version'                   => 0,
				'created_by_user_id'             => $record['actor_user_id'],
				'created_at_utc'                 => $record['queued_at_utc'],
				'updated_by_user_id'             => $record['actor_user_id'],
				'updated_at_utc'                 => $record['queued_at_utc'],
				'worksheet_revision_public_id'   => $record['worksheet_revision_public_id'],
				'worksheet_revision_hash'        => $record['worksheet_revision_hash'],
				'policy_revision_public_id'      => $config['policy']['revision_id'],
				'policy_hash'                    => $config['policy']['content_hash'],
				'rubric_revision_public_id'      => $config['rubric']['revision_id'],
				'rubric_hash'                    => $config['rubric']['content_hash'],
				'review_rule_revision_public_id' => $config['review_rule']['revision_id'],
				'review_rule_hash'               => $config['review_rule']['content_hash'],
				'prompt_contract_version'        => $config['prompt']['contract_version'],
				'input_schema_version'           => $config['input_schema']['schema_version'],
				'output_schema_version'          => $config['output_schema']['schema_version'],
				'state'                          => 'queued',
				'idempotency_key'                => $record['evaluation_idempotency_key'],
				'requested_at_utc'               => $record['queued_at_utc'],
				'authorization_envelope_version' => '1.0.0',
				'authorization_envelope_json'    => $record['authorization_envelope_json'],
				'authorization_envelope_hash'    => $record['authorization_envelope_hash'],
				'enqueue_correlation_id'         => $record['correlation_id'],
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
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_public_id' => $record['evaluation_public_id'],
					'input_hash'           => $record['input_hash'],
					'input_byte_size'      => $record['input_byte_size'],
					'projection_hash'      => $record['projection_hash'],
					'redaction_version'    => self::INPUT_REDACTION,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_INPUTS,
			array(
				'public_id'                 => $record['input_public_id'],
				'content_hash'              => $content_hash,
				'created_by_user_id'        => $record['actor_user_id'],
				'created_at_utc'            => $record['queued_at_utc'],
				'worksheet_evaluation_id'   => $evaluation_pk,
				'projection_schema_version' => Worksheet_Evaluation_Input_Builder::P04_VERSION,
				'input_schema'              => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
				'input_version'             => self::PROFILE_VERSION,
				'input_json'                => $record['input_json'],
				'input_hash'                => $record['input_hash'],
				'input_byte_size'           => $record['input_byte_size'],
				'redaction_version'         => self::INPUT_REDACTION,
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
				'subject_type'          => 'worksheet_evaluation',
				'evaluation_public_id'  => $record['evaluation_public_id'],
				'run_no'                => 1,
				'state'                 => 'queued',
				'task_key'              => $record['task_key'],
				'contract_version'      => Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT,
				'operation_key'         => Worksheet_Evaluation_Input_Builder::OPERATION,
				'input_schema_version'  => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
				'output_schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
				'input_digest'          => $record['input_hash'],
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
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $record ): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                 => $record['command_id'],
				'configuration_hash'         => $record['configuration_hash'],
				'evaluation_idempotency_key' => $record['evaluation_idempotency_key'],
				'input_digest'               => $record['input_hash'],
				'projection_hash'            => $record['projection_hash'],
				'request_digest'             => $record['request_digest'],
				'routing_revision'           => $record['routing_revision'],
				'profile_fingerprint'        => $record['profile_fingerprint'],
				'worksheet_revision_id'      => $record['worksheet_revision_public_id'],
				'worksheet_revision_hash'    => $record['worksheet_revision_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$after_digest  = substr( (string) $record['evaluation_idempotency_key'], 5 );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'worksheet_evaluation',
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
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'worksheet_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
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
	 * @param array<string,mixed>                 $record Record.
	 * @param Enqueue_Worksheet_Evaluation_Result $result Result.
	 * @param bool                                $receipt_only Receipt only.
	 * @return void
	 */
	private function insert_outbox( array $record, Enqueue_Worksheet_Evaluation_Result $result, bool $receipt_only ): void {
		$event_type   = $receipt_only ? self::RECEIPT_EVENT_TYPE : self::EVENT_TYPE;
		$payload_json = $this->canonical->encode(
			array(
				'command_id'                 => $record['command_id'],
				'evaluation_idempotency_key' => $record['evaluation_idempotency_key'],
				'event'                      => $event_type,
				'input_digest'               => $record['input_hash'],
				'owner_user_id'              => $record['actor_user_id'],
				'request_digest'             => $record['request_digest'],
				'receipt'                    => array(
					'evaluation_id'         => $result->evaluation_id(),
					'evaluation_state'      => $result->evaluation_state(),
					'queued_at_utc'         => $result->queued_at_utc(),
					'run_id'                => $result->run_id(),
					'run_no'                => $result->run_no(),
					'run_state'             => $result->run_state(),
					'source_hash'           => $result->source_hash(),
					'worksheet_revision_id' => $result->worksheet_revision_id(),
				),
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
				'aggregate_type'       => 'worksheet_evaluation',
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
	 * @return Enqueue_Worksheet_Evaluation_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt(
		string $command_storage_key,
		string $request_digest,
		bool $for_update
	): ?Enqueue_Worksheet_Evaluation_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $command_storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			! in_array( (string) ( $row['event_type'] ?? '' ), array( self::EVENT_TYPE, self::RECEIPT_EVENT_TYPE ), true )
			|| self::OUTBOX_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		if ( ! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt ) || array_is_list( $receipt )
			|| ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['evaluation_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Evaluation receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $for_update For update.
	 * @return Enqueue_Worksheet_Evaluation_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_receipt( array $record, bool $for_update ): ?Enqueue_Worksheet_Evaluation_Result {
		$sql = 'SELECT e.id, e.public_id AS evaluation_public_id, e.state AS evaluation_state, e.worksheet_revision_public_id, e.worksheet_revision_hash, e.requested_at_utc, i.input_hash, r.public_id AS run_public_id, r.run_no, r.state AS run_state FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.worksheet_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' r ON r.evaluation_public_id = e.public_id '
			. 'AND r.subject_type = %s AND r.run_no = 1 '
			. 'WHERE e.idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row(
			$this->prepare(
				$sql,
				array( 'worksheet_evaluation', $record['evaluation_idempotency_key'] )
			)
		);
		if ( null === $row ) {
			return null;
		}
		if (
			! hash_equals( (string) $record['worksheet_revision_public_id'], (string) ( $row['worksheet_revision_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['worksheet_revision_hash'], (string) ( $row['worksheet_revision_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['input_hash'], (string) ( $row['input_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->result_from_receipt(
			array(
				'evaluation_id'         => $row['evaluation_public_id'] ?? null,
				'evaluation_state'      => $row['evaluation_state'] ?? null,
				'worksheet_revision_id' => $row['worksheet_revision_public_id'] ?? null,
				'source_hash'           => $row['worksheet_revision_hash'] ?? null,
				'run_id'                => $row['run_public_id'] ?? null,
				'run_no'                => $row['run_no'] ?? null,
				'run_state'             => $row['run_state'] ?? null,
				'queued_at_utc'         => $row['requested_at_utc'] ?? null,
			),
			true
		);
	}

	/**
	 * Recover concurrent receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Worksheet_Evaluation_Result|null
	 */
	private function recover_concurrent_receipt( array $record ): ?Enqueue_Worksheet_Evaluation_Result {
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$command_replay      = $this->command_receipt(
				(string) $record['command_storage_key'],
				(string) $record['request_digest'],
				true
			);
			if ( $command_replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $command_replay;
			}
			$semantic_replay = $this->evaluation_receipt( $record, true );
			if ( ! $semantic_replay instanceof Enqueue_Worksheet_Evaluation_Result ) {
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
			. 'INNER JOIN ' . self::TABLE_INPUTS . ' i ON i.worksheet_evaluation_id = e.id '
			. 'INNER JOIN ' . self::TABLE_RUNS . ' r ON r.evaluation_public_id = e.public_id '
			. 'AND r.subject_type = %s AND r.run_no = 1 WHERE e.id = %d LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( 'worksheet_evaluation', $evaluation_pk ) ) );
		if (
			null === $row
			|| ! hash_equals( (string) $record['evaluation_public_id'], (string) ( $row['evaluation_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['input_public_id'], (string) ( $row['input_public_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['run_public_id'], (string) ( $row['run_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Enqueue_Worksheet_Evaluation_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Enqueue_Worksheet_Evaluation_Result {
		return new Enqueue_Worksheet_Evaluation_Result(
			(string) $record['evaluation_public_id'],
			'queued',
			(string) $record['worksheet_revision_public_id'],
			(string) $record['worksheet_revision_hash'],
			(string) $record['run_public_id'],
			1,
			'queued',
			(string) $record['queued_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Enqueue_Worksheet_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Enqueue_Worksheet_Evaluation_Result {
		try {
			return new Enqueue_Worksheet_Evaluation_Result(
				(string) ( $receipt['evaluation_id'] ?? '' ),
				(string) ( $receipt['evaluation_state'] ?? '' ),
				(string) ( $receipt['worksheet_revision_id'] ?? '' ),
				(string) ( $receipt['source_hash'] ?? '' ),
				(string) ( $receipt['run_id'] ?? '' ),
				$this->positive_integer( $receipt['run_no'] ?? null ),
				(string) ( $receipt['run_state'] ?? '' ),
				(string) ( $receipt['queued_at_utc'] ?? '' ),
				$replayed
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			'command_id',
			'command_storage_key',
			'request_digest',
			'correlation_id',
			'queued_at_utc',
			'evaluation_public_id',
			'input_public_id',
			'run_public_id',
			'audit_public_id',
			'outbox_public_id',
			'evaluation_idempotency_key',
			'run_idempotency_key',
			'worksheet_public_id',
			'worksheet_revision_public_id',
			'worksheet_revision_hash',
			'configuration',
			'configuration_hash',
			'input_json',
			'input_hash',
			'input_byte_size',
			'projection_hash',
			'authorization_envelope_json',
			'authorization_envelope_hash',
			'task_key',
			'provider_key',
			'model_key',
			'profile_fingerprint',
			'routing_revision',
		);
		$actual   = array_keys( $record );
		sort( $required, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $required !== $actual || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Worksheet evaluation enqueue record is incomplete.' );
		}
		foreach ( array( 'evaluation_public_id', 'input_public_id', 'run_public_id', 'audit_public_id', 'outbox_public_id', 'worksheet_public_id', 'worksheet_revision_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'request_digest', 'worksheet_revision_hash', 'configuration_hash', 'input_hash', 'projection_hash', 'authorization_envelope_hash', 'profile_fingerprint' ) as $key ) {
			$this->assert_hash( (string) $record[ $key ], $key );
		}
		$this->assert_storage_key( (string) $record['command_storage_key'], 'command_storage_key' );
		$this->assert_storage_key( (string) $record['evaluation_idempotency_key'], 'evaluation_idempotency_key' );
		$this->assert_storage_key( (string) $record['run_idempotency_key'], 'run_idempotency_key' );
		if (
			! is_array( $record['configuration'] ) || array_is_list( $record['configuration'] )
			|| ! is_string( $record['input_json'] )
			|| ! hash_equals( (string) $record['input_hash'], hash( 'sha256', $record['input_json'] ) )
			|| ! is_int( $record['input_byte_size'] )
			|| $record['input_byte_size'] !== strlen( $record['input_json'] )
			|| $record['input_byte_size'] > Worksheet_Evaluation_Input_Builder::MAXIMUM_INPUT_BYTES
			|| ! is_string( $record['authorization_envelope_json'] )
			|| ! hash_equals( (string) $record['authorization_envelope_hash'], hash( 'sha256', $record['authorization_envelope_json'] ) )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $record['queued_at_utc'] )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation enqueue record values are invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
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
		if (
			strlen( $value ) > 191
			|| 1 !== preg_match( '/^[a-z]+:[a-f0-9]{64}$/D', $value )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( string $json, string $hash ): array {
		$this->assert_hash( $hash, 'json_hash' );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
