<?php
/**
 * Bootstrap-safe C75 idempotency receipt and canonical audit writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Schema_Repair_Failure;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_Receipt_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap-safe C75 idempotency receipt and canonical audit writer.
 */
final class WordPress_Schema_Repair_Receipt implements Schema_Repair_Receipt_Port_Interface {
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';
	private const OPTION_PREFIX       = 'oj_ai_dashboard_schema_repair_';
	private const FINALIZE_LOCK       = 'oj_ai_dashboard_schema_repair_finalize_lock';
	private const AGGREGATE_TYPE      = 'addon_schema';
	private const AGGREGATE_PUBLIC_ID = '00000000000000000000000075';
	private const EVENT_REPAIRED      = 'operations.schema.repaired';
	private const RECEIPT_SCHEMA      = 'oj_ai.schema_repair_receipt';
	private const AUDIT_SCHEMA        = 'oj_ai.schema_repair_audit';
	private const VERSION             = 1;

	/**
	 * Initialize WordPress schema repair receipt dependencies and state.
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
	 * Claim.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Repair_Addon_Schema_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim( array $record ): ?Repair_Addon_Schema_Result {
		$this->assert_record( $record );
		$durable = $this->durable_receipt( (string) $record['storage_key'], (string) $record['request_digest'], false );
		if ( $durable instanceof Repair_Addon_Schema_Result ) {
			$this->delete_bootstrap( $record );
			return $durable->as_replay();
		}
		$this->assert_option_api();
		$name    = $this->option_name( (string) $record['storage_key'] );
		$current = get_option( $name, null );
		if ( is_array( $current ) ) {
			return $this->bootstrap_result( $current, $record );
		}
		if ( null !== $current ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		if ( add_option( $name, $record, '', false ) ) {
			return null;
		}
		$current = get_option( $name, null );
		if ( ! is_array( $current ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}

		return $this->bootstrap_result( $current, $record );
	}

	/**
	 * Complete.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param Schema_Installation_Result $repair_result Repair result.
	 * @param string                     $completed_at_utc Completed at utc.
	 * @return Repair_Addon_Schema_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete(
		array $record,
		Schema_Installation_Result $repair_result,
		string $completed_at_utc
	): Repair_Addon_Schema_Result {
		$this->assert_record( $record );
		$this->assert_datetime( $completed_at_utc );
		if ( ! $repair_result->succeeded() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::POSTCONDITION_FAILED );
		}
		$existing = $this->durable_receipt( (string) $record['storage_key'], (string) $record['request_digest'], false );
		if ( $existing instanceof Repair_Addon_Schema_Result ) {
			$this->delete_bootstrap( $record );
			return $existing->as_replay();
		}

		$lock        = $this->acquire_finalize_lock();
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$existing    = $this->durable_receipt( (string) $record['storage_key'], (string) $record['request_digest'], true );
			if ( $existing instanceof Repair_Addon_Schema_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				$this->delete_bootstrap( $record );
				return $existing->as_replay();
			}

			$code           = 'schema_repair_converged' === $repair_result->code()
				? 'schema_repair_converged'
				: 'schema_repaired';
			$mutation_count = count( $repair_result->completed_tables() );
			$result         = new Repair_Addon_Schema_Result(
				$code,
				(string) $record['expected_catalog_fingerprint'],
				$completed_at_utc,
				false,
				$mutation_count
			);
			$this->insert_audit( $record, $result );
			$this->insert_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			$this->delete_bootstrap( $record );

			return $result;
		} catch ( Schema_Repair_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->durable_receipt( (string) $record['storage_key'], (string) $record['request_digest'], false );
			if ( $winner instanceof Repair_Addon_Schema_Result ) {
				$this->delete_bootstrap( $record );
				return $winner->as_replay();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		} finally {
			$this->release_finalize_lock( $lock );
		}
	}

	/**
	 * Block.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $reason Reason.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function block( array $record, string $reason, string $occurred_at_utc ): void {
		$this->assert_record( $record );
		$this->assert_datetime( $occurred_at_utc );
		Schema_Repair_Failure::because( $reason );
		$this->assert_option_api();
		$name    = $this->option_name( (string) $record['storage_key'] );
		$current = get_option( $name, null );
		if ( ! is_array( $current ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_same_request( $current, $record );
		$blocked = array_merge(
			$current,
			array(
				'state'               => 'blocked',
				'failure_reason'      => $reason,
				'completed_at_utc'    => $occurred_at_utc,
				'retention_until_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $occurred_at_utc . ' UTC' ) + ( 30 * 86400 ) ),
			)
		);
		if ( ! update_option( $name, $blocked, false ) && get_option( $name, null ) !== $blocked ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Bootstrap result.
	 *
	 * @param array<string,mixed> $stored Stored.
	 * @param array<string,mixed> $requested Requested.
	 * @return Repair_Addon_Schema_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bootstrap_result( array $stored, array $requested ): ?Repair_Addon_Schema_Result {
		$this->assert_same_request( $stored, $requested );
		$state = (string) ( $stored['state'] ?? '' );
		if ( 'pending' === $state ) {
			return null;
		}
		if ( 'blocked' === $state ) {
			$reason = (string) ( $stored['failure_reason'] ?? Schema_Repair_Failure::BLOCKED );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( $reason );
		}
		if ( 'completed' === $state ) {
			return $this->result_from_array( $stored )->as_replay();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
	}

	/**
	 * Durable receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Repair_Addon_Schema_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function durable_receipt( string $storage_key, string $request_digest, bool $for_update ): ?Repair_Addon_Schema_Result {
		if ( ! $this->table_exists( self::TABLE_OUTBOX ) ) {
			return null;
		}
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::AGGREGATE_TYPE !== (string) ( $row['aggregate_type'] ?? '' )
			|| self::AGGREGATE_PUBLIC_ID !== (string) ( $row['aggregate_public_id'] ?? '' )
			|| self::EVENT_REPAIRED !== (string) ( $row['event_type'] ?? '' )
			|| self::RECEIPT_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::VERSION !== (int) ( $row['payload_version'] ?? 0 )
			|| ! is_string( $row['payload_json'] ?? null )
			|| ! is_string( $row['payload_hash'] ?? null )
			|| ! hash_equals( (string) $row['payload_hash'], hash( 'sha256', (string) $row['payload_json'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$payload = json_decode( (string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_array( $payload ) || ! hash_equals( $this->canonical->encode( $payload ), (string) $row['payload_json'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! hash_equals( (string) ( $payload['request_digest'] ?? '' ), $request_digest ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::IDEMPOTENCY_CONFLICT );
		}

		return $this->result_from_array( is_array( $payload['result'] ?? null ) ? $payload['result'] : array() )->as_replay();
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param Repair_Addon_Schema_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Repair_Addon_Schema_Result $result ): void {
		$payload = array(
			'profile'        => Repair_Addon_Schema_Command::PROFILE,
			'request_digest' => $record['request_digest'],
			'result'         => $this->result_payload( $result ),
		);
		$json    = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $this->ids->create()->value(),
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $result->completed_at_utc(),
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $result->completed_at_utc(),
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => self::AGGREGATE_PUBLIC_ID,
				'event_type'           => self::EVENT_REPAIRED,
				'idempotency_key'      => $record['storage_key'],
				'payload_schema'       => self::RECEIPT_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $json,
				'payload_hash'         => hash( 'sha256', $json ),
				'available_at_utc'     => $result->completed_at_utc(),
				'published_at_utc'     => $result->completed_at_utc(),
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
	 * @param array<string,mixed>        $record Record.
	 * @param Repair_Addon_Schema_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $record, Repair_Addon_Schema_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		$metadata      = array(
			'approval_token_hash'          => $record['approval_token_hash'],
			'backup_evidence_hash'         => $record['backup_evidence_hash'],
			'backup_verified_at_utc'       => $record['backup_verified_at_utc'],
			'command_id'                   => $record['command_id'],
			'completed_table_count'        => $result->mutation_count(),
			'expected_catalog_fingerprint' => $record['expected_catalog_fingerprint'],
			'expected_installation_state'  => $record['expected_installation_state'],
			'expected_repair_state_hash'   => $record['expected_repair_state_hash'],
			'request_digest'               => $record['request_digest'],
			'result_code'                  => $result->code(),
		);
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_REPAIRED,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $result->catalog_fingerprint(),
					'before_digest'       => $record['expected_repair_state_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => self::AGGREGATE_PUBLIC_ID,
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $result->completed_at_utc(),
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $this->ids->create()->value(),
				'occurred_at_utc'     => $result->completed_at_utc(),
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_REPAIRED,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => self::AGGREGATE_PUBLIC_ID,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $record['expected_repair_state_hash'],
				'after_digest'        => $result->catalog_fingerprint(),
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Result payload.
	 *
	 * @param Repair_Addon_Schema_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function result_payload( Repair_Addon_Schema_Result $result ): array {
		return array(
			'catalog_fingerprint' => $result->catalog_fingerprint(),
			'code'                => $result->code(),
			'completed_at_utc'    => $result->completed_at_utc(),
			'mutation_count'      => $result->mutation_count(),
		);
	}

	/**
	 * Result from array.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return Repair_Addon_Schema_Result
	 */
	private function result_from_array( array $value ): Repair_Addon_Schema_Result {
		return new Repair_Addon_Schema_Result(
			(string) ( $value['code'] ?? '' ),
			(string) ( $value['catalog_fingerprint'] ?? '' ),
			(string) ( $value['completed_at_utc'] ?? '' ),
			true,
			(int) ( $value['mutation_count'] ?? 0 )
		);
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
			'profile',
			'state',
			'actor_user_id',
			'command_id',
			'storage_key',
			'request_digest',
			'approval_token_hash',
			'backup_evidence_hash',
			'backup_verified_at_utc',
			'expected_installation_state',
			'expected_catalog_fingerprint',
			'expected_repair_state_hash',
			'correlation_id',
			'claimed_at_utc',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $record ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
			}
		}
		if (
			Repair_Addon_Schema_Command::PROFILE !== $record['profile']
			|| 'pending' !== $record['state']
			|| (int) $record['actor_user_id'] < 1
			|| 1 !== preg_match( '/^schema-repair:[a-f0-9]{64}$/D', (string) $record['storage_key'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'request_digest', 'approval_token_hash', 'backup_evidence_hash', 'expected_catalog_fingerprint', 'expected_repair_state_hash' ) as $key ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
			}
		}
		$this->assert_datetime( (string) $record['backup_verified_at_utc'] );
		$this->assert_datetime( (string) $record['claimed_at_utc'] );
	}

	/**
	 * Reject invalid same request.
	 *
	 * @param array<string,mixed> $stored Stored.
	 * @param array<string,mixed> $requested Requested.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_same_request( array $stored, array $requested ): void {
		if (
			! is_string( $stored['request_digest'] ?? null )
			|| ! hash_equals( (string) $stored['request_digest'], (string) $requested['request_digest'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::IDEMPOTENCY_CONFLICT );
		}
	}

	/**
	 * Option name.
	 *
	 * @param string $storage_key Storage key.
	 * @return string
	 */
	private function option_name( string $storage_key ): string {
		return self::OPTION_PREFIX . substr( $storage_key, strlen( 'schema-repair:' ) );
	}

	/**
	 * Delete bootstrap.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function delete_bootstrap( array $record ): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( $this->option_name( (string) $record['storage_key'] ) );
		}
	}

	/**
	 * Reject invalid option api.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_option_api(): void {
		foreach ( array( 'add_option', 'get_option', 'update_option', 'delete_option' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
			}
		}
	}

	/**
	 * Acquire finalize lock.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function acquire_finalize_lock(): string {
		$this->assert_option_api();
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::LOCK_UNAVAILABLE );
		}
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$lock = array(
				'token'      => $token,
				'expires_at' => time() + 30,
			);
			if ( add_option( self::FINALIZE_LOCK, $lock, '', false ) ) {
				return $token;
			}
			$current = get_option( self::FINALIZE_LOCK, null );
			if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) < time() ) {
				delete_option( self::FINALIZE_LOCK );
				continue;
			}
			usleep( 20000 );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Schema_Repair_Failure::because( Schema_Repair_Failure::LOCK_UNAVAILABLE );
	}

	/**
	 * Release finalize lock.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function release_finalize_lock( string $token ): void {
		try {
			$current = function_exists( 'get_option' ) ? get_option( self::FINALIZE_LOCK, null ) : null;
			if ( is_array( $current ) && hash_equals( $token, (string) ( $current['token'] ?? '' ) ) && function_exists( 'delete_option' ) ) {
				delete_option( self::FINALIZE_LOCK );
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
		}
	}

	/**
	 * Table exists.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		$sql = $this->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			array( $table )
		);

		return $table === (string) $this->get_var( $sql );
	}

	/**
	 * Reject invalid datetime.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_datetime( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof DateTimeImmutable || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
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
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
			}
		}

		return $database;
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
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
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
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
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
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
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
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->query( $sql ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
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
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
