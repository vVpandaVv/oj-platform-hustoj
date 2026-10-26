<?php
/**
 * Durable outbox receipt for C30 assignment evaluation pages.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Result;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Receipt_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses a published receipt-only outbox row, never an assignment batch master.
 */
final class WordPress_Assignment_Evaluation_Batch_Receipt implements Assignment_Evaluation_Batch_Receipt_Port_Interface {
	private const TABLE_OUTBOX   = 'oj_ai_outbox_events';
	private const EVENT_TYPE     = 'assignment_evaluations.enqueue_receipt';
	private const PAYLOAD_SCHEMA = 'oj_ai.assignment_evaluation_batch_receipt';
	private const VERSION        = 1;

	/**
	 * Initialize WordPress assignment evaluation batch receipt dependencies and state.
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
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Assignment_Evaluations_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Enqueue_Assignment_Evaluations_Result {
		$this->storage_key( $idempotency_storage_key );
		$this->hash_value( $request_digest );
		return $this->receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Store receipt.
	 *
	 * @param array<string,mixed>                   $record Record.
	 * @param Enqueue_Assignment_Evaluations_Result $result Result.
	 * @return Enqueue_Assignment_Evaluations_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function store_receipt( array $record, Enqueue_Assignment_Evaluations_Result $result ): Enqueue_Assignment_Evaluations_Result {
		$this->assert_record( $record, $result );
		$existing = $this->receipt( (string) $record['idempotency_storage_key'], (string) $record['request_digest'], false );
		if ( $existing instanceof Enqueue_Assignment_Evaluations_Result ) {
			return $existing;
		}

		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$existing            = $this->receipt( (string) $record['idempotency_storage_key'], (string) $record['request_digest'], true );
			if ( $existing instanceof Enqueue_Assignment_Evaluations_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $existing;
			}
			$this->insert_receipt( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			return $result;
		} catch ( Throwable $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			try {
				$winner = $this->receipt( (string) $record['idempotency_storage_key'], (string) $record['request_digest'], false );
				if ( $winner instanceof Enqueue_Assignment_Evaluations_Result ) {
					return $winner;
				}
			} catch ( Assignment_Evaluation_Failure $recovery_failure ) {
				if ( Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT === $recovery_failure->reason() ) {
					throw $recovery_failure;
				}
			}
			if ( $failure instanceof Assignment_Evaluation_Failure && Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT === $failure->reason() ) {
				throw $failure;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert receipt.
	 *
	 * @param array<string,mixed>                   $record Record.
	 * @param Enqueue_Assignment_Evaluations_Result $result Result.
	 * @return void
	 */
	private function insert_receipt( array $record, Enqueue_Assignment_Evaluations_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_TYPE,
				'owner_user_id'  => $record['actor_user_id'],
				'receipt'        => $result->receipt(),
				'request_digest' => $record['request_digest'],
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['processed_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['processed_at_utc'],
				'aggregate_type'       => 'assignment',
				'aggregate_public_id'  => $record['assignment_public_id'],
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['processed_at_utc'],
				'published_at_utc'     => $record['processed_at_utc'],
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Enqueue_Assignment_Evaluations_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Enqueue_Assignment_Evaluations_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'assignment' !== ( $row['aggregate_type'] ?? null )
			|| self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_object( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->exact_keys( $payload, array( 'command_id', 'event', 'owner_user_id', 'receipt', 'request_digest' ) );
		if ( ! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		try {
			$result = Enqueue_Assignment_Evaluations_Result::from_receipt( $receipt, true );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), $result->assignment_id() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$material = $receipt;
		unset( $material['receipt_digest'] );
		if ( ! hash_equals( $result->receipt_digest(), hash( 'sha256', $this->canonical->encode( $material ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $result;
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed>                   $record Record.
	 * @param Enqueue_Assignment_Evaluations_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record, Enqueue_Assignment_Evaluations_Result $result ): void {
		$this->exact_keys(
			$record,
			array( 'actor_user_id', 'assignment_public_id', 'command_id', 'correlation_id', 'idempotency_storage_key', 'outbox_public_id', 'processed_at_utc', 'request_digest' )
		);
		if ( ! is_int( $record['actor_user_id'] ?? null ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt actor is invalid.' );
		}
		foreach ( array( 'assignment_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) ( $record[ $field ] ?? '' ) );
		}
		if ( ! hash_equals( (string) $record['assignment_public_id'], $result->assignment_id() ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt assignment drifted.' );
		}
		$this->storage_key( (string) ( $record['idempotency_storage_key'] ?? '' ) );
		$this->hash_value( (string) ( $record['request_digest'] ?? '' ) );
		$this->utc( (string) ( $record['processed_at_utc'] ?? '' ) );
		foreach ( array(
			'command_id'     => 128,
			'correlation_id' => 100,
		) as $field => $maximum ) {
			$value = $record[ $field ] ?? null;
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
				throw new InvalidArgumentException( 'Assignment evaluation receipt token is invalid.' );
			}
		}
	}

	/**
	 * Decode object.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_object( string $json, string $hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			$this->stored_invalid();
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			$this->stored_invalid();
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				$this->stored_invalid();
			}
			return $value;
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^command:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt storage key is invalid.' );
		}
	}
	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt hash is invalid.' );
		}
	}
	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation receipt time is invalid.' );
		}
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
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
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->query( $sql ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid read.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
