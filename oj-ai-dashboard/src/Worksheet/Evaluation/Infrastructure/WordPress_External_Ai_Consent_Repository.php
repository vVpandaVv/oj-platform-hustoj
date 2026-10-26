<?php
/**
 * Literal-table external AI consent command ledger.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Application\External_Ai_Consent_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\External_Ai_Consent_Record;
use OJAI\Dashboard\Worksheet\Evaluation\Port\External_Ai_Consent_Repository_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table external AI consent command ledger.
 */
final class WordPress_External_Ai_Consent_Repository implements External_Ai_Consent_Repository_Port_Interface {
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const AGGREGATE_TYPE      = 'external_ai_consent';
	private const AGGREGATE_PUBLIC_ID = '00000000000000000000000001';
	private const EVENT_APPROVED      = 'external_ai_consent.approved';
	private const EVENT_REVOKED       = 'external_ai_consent.revoked';
	private const PAYLOAD_SCHEMA      = 'oj_ai.external_ai_consent_command_receipt';
	private const AUDIT_SCHEMA        = 'oj_ai.external_ai_consent_audit';
	private const VERSION             = 1;
	private const LOCK_OPTION         = 'oj_ai_dashboard_external_ai_consent_lock';

	/**
	 * Initialize WordPress external ai consent repository dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator $ids Ids.
	 * @param object|null         $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Public_Id_Generator $ids,
		private ?object $database = null
	) {
	}

	/**
	 * Current record.
	 *
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function current_record(): ?array {
		try {
			$state = $this->current_state( false );
			return $state['record'];
		} catch ( External_Ai_Consent_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Replay.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return array{record:array<string,mixed>|null,occurred_at_utc:string,replayed:bool}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function replay( string $idempotency_storage_key, string $request_digest ): ?array {
		if (
			1 !== preg_match( '/^consent:[a-f0-9]{64}$/D', $idempotency_storage_key )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_digest )
		) {
			throw new InvalidArgumentException( 'External AI consent replay key is invalid.' );
		}
		try {
			$receipt = $this->receipt( $idempotency_storage_key, $request_digest, false );
			if ( null === $receipt ) {
				return null;
			}
			return array(
				'record'          => $receipt['record'],
				'occurred_at_utc' => $receipt['occurred_at_utc'],
				'replayed'        => true,
			);
		} catch ( External_Ai_Consent_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Commit.
	 *
	 * @param string                   $operation Operation.
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $idempotency_storage_key Idempotency storage key.
	 * @param string                   $request_digest Request digest.
	 * @param string|null              $expected_consent_hash Expected consent hash.
	 * @param array<string,mixed>|null $record Record.
	 * @param string                   $occurred_at_utc Occurred at utc.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @return array{record:array<string,mixed>|null,occurred_at_utc:string,replayed:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function commit(
		string $operation,
		int $actor_user_id,
		string $command_id,
		string $idempotency_storage_key,
		string $request_digest,
		?string $expected_consent_hash,
		?array $record,
		string $occurred_at_utc,
		string $correlation_id
	): array {
		$this->assert_command(
			$operation,
			$actor_user_id,
			$command_id,
			$idempotency_storage_key,
			$request_digest,
			$expected_consent_hash,
			$record,
			$occurred_at_utc,
			$correlation_id
		);
		$lock_token          = $this->acquire_lock();
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$receipt             = $this->receipt( $idempotency_storage_key, $request_digest, true );
			if ( null !== $receipt ) {
				$this->query_or_fail( 'COMMIT' );
				return array(
					'record'          => $receipt['record'],
					'occurred_at_utc' => $receipt['occurred_at_utc'],
					'replayed'        => true,
				);
			}

			$current      = $this->current_state( true )['record'];
			$current_hash = null === $current ? null : (string) $current['consent_hash'];
			if (
				( null === $expected_consent_hash ) !== ( null === $current_hash )
				|| ( null !== $expected_consent_hash && ! hash_equals( $expected_consent_hash, (string) $current_hash ) )
			) {
				throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STATE_CONFLICT );
			}

			$payload = array(
				'command_id'      => $command_id,
				'occurred_at_utc' => $occurred_at_utc,
				'operation'       => $operation,
				'record'          => $record,
				'request_digest'  => $request_digest,
			);
			$this->insert_event(
				$operation,
				$actor_user_id,
				$idempotency_storage_key,
				$payload,
				$occurred_at_utc
			);
			$this->insert_audit(
				$operation,
				$actor_user_id,
				$command_id,
				$request_digest,
				$current,
				$record,
				$occurred_at_utc,
				$correlation_id
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			$this->synchronize_legacy_cache( $record );

			return array(
				'record'          => $record,
				'occurred_at_utc' => $occurred_at_utc,
				'replayed'        => false,
			);
		} catch ( External_Ai_Consent_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
		} finally {
			$this->release_lock( $lock_token );
		}
	}

	/**
	 * Current state.
	 *
	 * @param bool $for_update For update.
	 * @return array{found:bool,record:array<string,mixed>|null}
	 */
	private function current_state( bool $for_update ): array {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX
			. ' WHERE aggregate_type = %s AND aggregate_public_id = %s ORDER BY id DESC LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row(
			$this->prepare( $sql, array( self::AGGREGATE_TYPE, self::AGGREGATE_PUBLIC_ID ) )
		);
		if ( null === $row ) {
			return array(
				'found'  => false,
				'record' => $this->legacy_record(),
			);
		}
		$payload = $this->payload_from_row( $row );
		return array(
			'found'  => true,
			'record' => $payload['record'],
		);
	}

	/**
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return array{record:array<string,mixed>|null,occurred_at_utc:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?array {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		$payload = $this->payload_from_row( $row );
		if ( ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::IDEMPOTENCY_CONFLICT );
		}
		return array(
			'record'          => $payload['record'],
			'occurred_at_utc' => $payload['occurred_at_utc'],
		);
	}

	/**
	 * Payload from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{command_id:string,occurred_at_utc:string,operation:string,record:array<string,mixed>|null,request_digest:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function payload_from_row( array $row ): array {
		if (
			self::AGGREGATE_TYPE !== ( $row['aggregate_type'] ?? null )
			|| self::AGGREGATE_PUBLIC_ID !== ( $row['aggregate_public_id'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
			|| ! in_array( $row['event_type'] ?? null, array( self::EVENT_APPROVED, self::EVENT_REVOKED ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
		$payload = $this->decode_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		$this->exact_keys( $payload, array( 'command_id', 'occurred_at_utc', 'operation', 'record', 'request_digest' ) );
		$operation = $payload['operation'] ?? null;
		$record    = $payload['record'] ?? null;
		if (
			! is_string( $payload['command_id'] ?? null )
			|| ! is_string( $operation )
			|| ! in_array( $operation, array( 'approve', 'revoke' ), true )
			|| ( 'approve' === $operation ) !== ( self::EVENT_APPROVED === $row['event_type'] )
			|| ! is_string( $payload['request_digest'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['request_digest'] )
			|| ! is_string( $payload['occurred_at_utc'] ?? null )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $payload['occurred_at_utc'] )
			|| ( 'revoke' === $operation && null !== $record )
			|| ( 'approve' === $operation && ! is_array( $record ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
		if ( is_array( $record ) ) {
			try {
				$record = External_Ai_Consent_Record::from_array( $record, $this->canonical )->to_array();
			} catch ( InvalidArgumentException ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
			}
		}
		return array(
			'command_id'      => $payload['command_id'],
			'occurred_at_utc' => $payload['occurred_at_utc'],
			'operation'       => $operation,
			'record'          => $record,
			'request_digest'  => $payload['request_digest'],
		);
	}

	/**
	 * Legacy record.
	 *
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_record(): ?array {
		$value = function_exists( 'get_option' )
			? get_option( WordPress_Worksheet_Evaluation_Execution_Consent::OPTION, null )
			: null;
		if ( null === $value || false === $value ) {
			return null;
		}
		try {
			return External_Ai_Consent_Record::from_array( $value, $this->canonical )->to_array();
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Insert event.
	 *
	 * @param string              $operation Operation.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $storage_key Storage key.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_event(
		string $operation,
		int $actor_user_id,
		string $storage_key,
		array $payload,
		string $occurred_at_utc
	): void {
		$payload_json = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $this->ids->create()->value(),
				'lock_version'         => 0,
				'created_by_user_id'   => $actor_user_id,
				'created_at_utc'       => $occurred_at_utc,
				'updated_by_user_id'   => $actor_user_id,
				'updated_at_utc'       => $occurred_at_utc,
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => self::AGGREGATE_PUBLIC_ID,
				'event_type'           => 'approve' === $operation ? self::EVENT_APPROVED : self::EVENT_REVOKED,
				'idempotency_key'      => $storage_key,
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $occurred_at_utc,
				'published_at_utc'     => $occurred_at_utc,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param string                   $operation Operation.
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $request_digest Request digest.
	 * @param array<string,mixed>|null $before Before.
	 * @param array<string,mixed>|null $after After.
	 * @param string                   $occurred_at_utc Occurred at utc.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit(
		string $operation,
		int $actor_user_id,
		string $command_id,
		string $request_digest,
		?array $before,
		?array $after,
		string $occurred_at_utc,
		string $correlation_id
	): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
		$before_digest = null === $before ? null : (string) $before['consent_hash'];
		$after_digest  = null === $after
			? hash( 'sha256', $this->canonical->encode( array( 'state' => 'revoked' ) ) )
			: (string) $after['consent_hash'];
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'     => $command_id,
				'operation'      => $operation,
				'request_digest' => $request_digest,
				'task_key'       => null === $after ? ( $before['task_key'] ?? null ) : $after['task_key'],
				'destination'    => null === $after ? ( $before['destination'] ?? null ) : $after['destination'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$action_key    = 'external_ai.consent.' . ( 'approve' === $operation ? 'approved' : 'revoked' );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $action_key,
					'actor_user_id'       => $actor_user_id,
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => self::AGGREGATE_PUBLIC_ID,
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $occurred_at_utc,
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $this->ids->create()->value(),
				'occurred_at_utc'     => $occurred_at_utc,
				'actor_user_id'       => $actor_user_id,
				'action_key'          => $action_key,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => self::AGGREGATE_PUBLIC_ID,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation_id,
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Synchronize legacy cache.
	 *
	 * @param array<string,mixed>|null $record Record.
	 * @return void
	 */
	private function synchronize_legacy_cache( ?array $record ): void {
		try {
			if ( null === $record ) {
				if ( function_exists( 'delete_option' ) ) {
					delete_option( WordPress_Worksheet_Evaluation_Execution_Consent::OPTION );
				}
			} elseif ( function_exists( 'update_option' ) ) {
				update_option( WordPress_Worksheet_Evaluation_Execution_Consent::OPTION, $record, false );
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The literal-table ledger remains authoritative when the cache is unavailable.
		}
	}

	/**
	 * Acquire lock.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function acquire_lock(): string {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::LOCK_UNAVAILABLE );
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::LOCK_UNAVAILABLE );
		}
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$lock = array(
				'token'      => $token,
				'expires_at' => time() + 30,
			);
			if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
				return $token;
			}
			$current = get_option( self::LOCK_OPTION, null );
			if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) < time() ) {
				delete_option( self::LOCK_OPTION );
				continue;
			}
			usleep( 20000 );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::LOCK_UNAVAILABLE );
	}

	/**
	 * Release lock.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function release_lock( string $token ): void {
		try {
			$current = function_exists( 'get_option' ) ? get_option( self::LOCK_OPTION, null ) : null;
			if (
				is_array( $current )
				&& is_string( $current['token'] ?? null )
				&& hash_equals( $token, $current['token'] )
				&& function_exists( 'delete_option' )
			) {
				delete_option( self::LOCK_OPTION );
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The short TTL prevents a failed cleanup from becoming a permanent lock.
		}
	}

	/**
	 * Reject invalid command.
	 *
	 * @param string                   $operation Operation.
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $storage_key Storage key.
	 * @param string                   $request_digest Request digest.
	 * @param string|null              $expected_hash Expected hash.
	 * @param array<string,mixed>|null $record Record.
	 * @param string                   $occurred_at_utc Occurred at utc.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_command(
		string $operation,
		int $actor_user_id,
		string $command_id,
		string $storage_key,
		string $request_digest,
		?string $expected_hash,
		?array $record,
		string $occurred_at_utc,
		string $correlation_id
	): void {
		if (
			! in_array( $operation, array( 'approve', 'revoke' ), true )
			|| $actor_user_id < 1
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $command_id )
			|| 1 !== preg_match( '/^consent:[a-f0-9]{64}$/D', $storage_key )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_digest )
			|| ( null !== $expected_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $correlation_id )
			|| ( 'approve' === $operation && null === $record )
			|| ( 'revoke' === $operation && null !== $record )
		) {
			throw new InvalidArgumentException( 'External AI consent repository command is invalid.' );
		}
		if ( is_array( $record ) ) {
			External_Ai_Consent_Record::from_array( $record, $this->canonical );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
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
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
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
		throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORED_DATA_INVALID );
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
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
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
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
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
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
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
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
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
			throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw External_Ai_Consent_Failure::because( External_Ai_Consent_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
