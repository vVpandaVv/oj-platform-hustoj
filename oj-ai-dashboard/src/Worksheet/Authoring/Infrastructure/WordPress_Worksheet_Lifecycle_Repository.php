<?php
/**
 * WordPress worksheet root lifecycle repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Lifecycle_Repository_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress worksheet root lifecycle repository.
 */
final class WordPress_Worksheet_Lifecycle_Repository implements Worksheet_Lifecycle_Repository_Port_Interface {
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_REVISIONS    = 'oj_ai_worksheet_revisions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';
	private const TABLE_AUDIT        = 'oj_ai_audit_events';
	private const TABLE_OUTBOX       = 'oj_ai_outbox_events';

	private const AUDIT_SCHEMA    = 'oj_ai.audit_metadata';
	private const PAYLOAD_SCHEMA  = 'oj_ai.worksheet_lifecycle_event';
	private const PROFILE_VERSION = 1;

	/**
	 * Initialize WordPress worksheet lifecycle repository dependencies and state.
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
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $operation Operation.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Worksheet_Lifecycle_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $operation,
		string $idempotency_storage_key,
		string $request_digest
	): ?Worksheet_Lifecycle_Result {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle receipt actor is invalid.' );
		}
		$this->assert_operation( $operation );
		$this->assert_hash( $idempotency_storage_key );
		$this->assert_hash( $request_digest );

		return $this->find_receipt(
			$actor_user_id,
			$operation,
			$idempotency_storage_key,
			$request_digest,
			false
		);
	}

	/**
	 * Change state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Lifecycle_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function change_state( array $record ): Worksheet_Lifecycle_Result {
		$this->assert_record( $record );
		$receipt = $this->find_receipt(
			$record['actor_user_id'],
			$record['operation'],
			$record['idempotency_storage_key'],
			$record['request_digest'],
			false
		);
		if ( $receipt instanceof Worksheet_Lifecycle_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->find_receipt(
				$record['actor_user_id'],
				$record['operation'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				true
			);
			if ( $receipt instanceof Worksheet_Lifecycle_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->lock_source( $record );
			$result   = $this->transition_result( $record, $snapshot, false );
			$this->update_root( $record, $snapshot, $result );
			$this->insert_audit( $record, $snapshot, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $record, $snapshot, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;

			return $result;
		} catch ( Worksheet_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->find_receipt(
				$record['actor_user_id'],
				$record['operation'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				false
			);
			if ( $receipt instanceof Worksheet_Lifecycle_Result ) {
				return $receipt;
			}
			throw Worksheet_Failure::storage_unavailable();
		}
	}

	/**
	 * Lock source.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_source( array $record ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT id, state, current_revision_public_id, latest_publication_public_id, lock_version, archived_at_utc FROM ' . self::TABLE_WORKSHEETS
					. ' WHERE public_id = %s AND owner_user_id = %d LIMIT 1 FOR UPDATE',
				array( $record['worksheet_public_id'], $record['actor_user_id'] )
			)
		);
		if ( null === $row ) {
			throw Worksheet_Failure::not_found();
		}

		$worksheet_pk        = $this->positive_integer( $row['id'] ?? null );
		$state               = $this->root_state( $row['state'] ?? null );
		$current_revision_id = (string) ( $row['current_revision_public_id'] ?? '' );
		new Public_Id( $current_revision_id );
		$latest_publication_id = $this->optional_public_id( $row['latest_publication_public_id'] ?? null );
		$lock_version          = $this->non_negative_integer( $row['lock_version'] ?? null );
		$archived_at           = $this->optional_utc( $row['archived_at_utc'] ?? null );
		$this->assert_root_invariant( $state, $latest_publication_id, $archived_at );

		$revision = $this->row(
			$this->prepare(
				'SELECT public_id FROM ' . self::TABLE_REVISIONS
					. ' WHERE worksheet_id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
				array( $worksheet_pk, $current_revision_id )
			)
		);
		if ( null === $revision || ! hash_equals( $current_revision_id, (string) ( $revision['public_id'] ?? '' ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if ( null !== $latest_publication_id ) {
			$publication = $this->row(
				$this->prepare(
					'SELECT public_id FROM ' . self::TABLE_PUBLICATIONS
						. ' WHERE worksheet_id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
					array( $worksheet_pk, $latest_publication_id )
				)
			);
			if ( null === $publication || ! hash_equals( $latest_publication_id, (string) ( $publication['public_id'] ?? '' ) ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
		}

		if (
			! hash_equals( $record['expected_state'], $state )
			|| ! hash_equals( $record['expected_revision_id'], $current_revision_id )
			|| $record['expected_lock_version'] !== $lock_version
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::lifecycle_conflict( $state, $current_revision_id, $lock_version );
		}

		return array(
			'worksheet_pk'          => $worksheet_pk,
			'state'                 => $state,
			'current_revision_id'   => $current_revision_id,
			'latest_publication_id' => $latest_publication_id,
			'lock_version'          => $lock_version,
			'archived_at_utc'       => $archived_at,
		);
	}

	/**
	 * Transition result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param bool                $replayed Replayed.
	 * @return Worksheet_Lifecycle_Result
	 */
	private function transition_result( array $record, array $snapshot, bool $replayed ): Worksheet_Lifecycle_Result {
		$is_archive   = Worksheet_Lifecycle_Command::ARCHIVE === $record['operation'];
		$target_state = $is_archive
			? 'archived'
			: ( null === $snapshot['latest_publication_id'] ? 'draft' : 'active' );

		return new Worksheet_Lifecycle_Result(
			$record['operation'],
			$record['worksheet_public_id'],
			$snapshot['state'],
			$target_state,
			$snapshot['current_revision_id'],
			$snapshot['latest_publication_id'],
			$snapshot['lock_version'] + 1,
			$is_archive ? $record['changed_at_utc'] : null,
			$record['actor_user_id'],
			$record['changed_at_utc'],
			$replayed
		);
	}

	/**
	 * Update root.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param array<string,mixed>        $snapshot Snapshot.
	 * @param Worksheet_Lifecycle_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_root(
		array $record,
		array $snapshot,
		Worksheet_Lifecycle_Result $result
	): void {
		$archive_guard = Worksheet_Lifecycle_Command::ARCHIVE === $record['operation']
			? 'archived_at_utc IS NULL'
			: 'archived_at_utc IS NOT NULL';
		$sql           = 'UPDATE ' . self::TABLE_WORKSHEETS
			. ' SET state = %s, archived_at_utc = ';
		$values        = array( $result->state() );
		if ( null === $result->archived_at_utc() ) {
			$sql .= 'NULL';
		} else {
			$sql     .= '%s';
			$values[] = $result->archived_at_utc();
		}
		$sql     .= ', lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND state = %s AND current_revision_public_id = %s AND lock_version = %d AND '
			. $archive_guard;
		$values[] = $record['actor_user_id'];
		$values[] = $record['changed_at_utc'];
		$values[] = $snapshot['worksheet_pk'];
		$values[] = $snapshot['state'];
		$values[] = $snapshot['current_revision_id'];
		$values[] = $snapshot['lock_version'];

		$query_result = $this->database()->query( $this->prepare( $sql, $values ) );
		if ( false === $query_result ) {
			throw new RuntimeException( 'Worksheet lifecycle compare-and-swap failed.' );
		}
		if ( 1 !== $query_result ) {
			throw Worksheet_Failure::lifecycle_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$snapshot['state'],
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$snapshot['current_revision_id'],
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$snapshot['lock_version']
			);
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param array<string,mixed>        $snapshot Snapshot.
	 * @param Worksheet_Lifecycle_Result $result Result.
	 * @return void
	 */
	private function insert_audit(
		array $record,
		array $snapshot,
		Worksheet_Lifecycle_Result $result
	): void {
		$previous_event_hash = $this->scalar(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_event_hash ) {
			$this->assert_hash( (string) $previous_event_hash );
		}
		$before        = $this->state_projection(
			$snapshot['state'],
			$snapshot['current_revision_id'],
			$snapshot['latest_publication_id'],
			$snapshot['lock_version'],
			$snapshot['archived_at_utc']
		);
		$after         = $this->state_projection(
			$result->state(),
			$result->current_revision_id(),
			$result->latest_publication_id(),
			$result->lock_version(),
			$result->archived_at_utc()
		);
		$before_digest = hash( 'sha256', $this->canonical->encode( $before ) );
		$after_digest  = hash( 'sha256', $this->canonical->encode( $after ) );
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'            => $record['command_id'],
				'current_revision_id'   => $result->current_revision_id(),
				'latest_publication_id' => $result->latest_publication_id(),
				'operation'             => $record['operation'],
				'source_lock_version'   => $snapshot['lock_version'],
				'source_state'          => $snapshot['state'],
				'target_lock_version'   => $result->lock_version(),
				'target_state'          => $result->state(),
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $this->event_type( $record['operation'] ),
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['worksheet_public_id'],
					'object_type'         => 'worksheet',
					'occurred_at_utc'     => $record['changed_at_utc'],
					'previous_event_hash' => null === $previous_event_hash ? null : (string) $previous_event_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['changed_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $this->event_type( $record['operation'] ),
				'object_type'         => 'worksheet',
				'object_public_id'    => $record['worksheet_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_event_hash ? null : (string) $previous_event_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param Worksheet_Lifecycle_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Worksheet_Lifecycle_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'operation'      => $record['operation'],
				'owner_user_id'  => $record['actor_user_id'],
				'receipt'        => $this->receipt_payload( $result ),
				'request_digest' => $record['request_digest'],
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['changed_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['changed_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => $this->event_type( $record['operation'] ),
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['changed_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param array<string,mixed>        $snapshot Snapshot.
	 * @param Worksheet_Lifecycle_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition(
		array $record,
		array $snapshot,
		Worksheet_Lifecycle_Result $result
	): void {
		$row = $this->row(
			$this->prepare(
				'SELECT state, current_revision_public_id, latest_publication_public_id, lock_version, archived_at_utc FROM ' . self::TABLE_WORKSHEETS . ' WHERE id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
				array( $snapshot['worksheet_pk'], $record['worksheet_public_id'] )
			)
		);
		if (
			null === $row
			|| ! hash_equals( $result->state(), (string) ( $row['state'] ?? '' ) )
			|| ! hash_equals( $result->current_revision_id(), (string) ( $row['current_revision_public_id'] ?? '' ) )
			|| $result->latest_publication_id() !== $this->optional_public_id( $row['latest_publication_public_id'] ?? null )
			|| $result->lock_version() !== $this->non_negative_integer( $row['lock_version'] ?? null )
			|| $result->archived_at_utc() !== $this->optional_utc( $row['archived_at_utc'] ?? null )
		) {
			throw new RuntimeException( 'Worksheet lifecycle postcondition failed.' );
		}
	}

	/**
	 * Find receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $operation Operation.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Worksheet_Lifecycle_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_receipt(
		int $actor_user_id,
		string $operation,
		string $key,
		string $digest,
		bool $for_update
	): ?Worksheet_Lifecycle_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			$this->event_type( $operation ) !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$payload = $this->decode( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->assert_stored_exact_keys(
			$payload,
			array( 'operation', 'owner_user_id', 'receipt', 'request_digest' )
		);
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt ) || array_is_list( $receipt )
			|| ! is_int( $payload['owner_user_id'] ) || $actor_user_id !== $payload['owner_user_id']
			|| ! hash_equals( $operation, (string) ( $payload['operation'] ?? '' ) )
			|| ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['worksheet_id'] ?? '' ) )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$this->assert_stored_exact_keys(
			$receipt,
			array(
				'archived_at_utc',
				'changed_at_utc',
				'changed_by_user_id',
				'current_revision_id',
				'latest_publication_id',
				'lock_version',
				'operation',
				'previous_state',
				'state',
				'worksheet_id',
			)
		);
		try {
			return $this->result_from_receipt( $receipt, true );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Worksheet_Lifecycle_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Worksheet_Lifecycle_Result {
		return new Worksheet_Lifecycle_Result(
			(string) ( $receipt['operation'] ?? '' ),
			(string) ( $receipt['worksheet_id'] ?? '' ),
			(string) ( $receipt['previous_state'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['current_revision_id'] ?? '' ),
			null === ( $receipt['latest_publication_id'] ?? null ) ? null : (string) $receipt['latest_publication_id'],
			$this->positive_integer( $receipt['lock_version'] ?? null ),
			null === ( $receipt['archived_at_utc'] ?? null ) ? null : (string) $receipt['archived_at_utc'],
			$this->positive_integer( $receipt['changed_by_user_id'] ?? null ),
			(string) ( $receipt['changed_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Worksheet_Lifecycle_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Worksheet_Lifecycle_Result $result ): array {
		return array(
			'archived_at_utc'       => $result->archived_at_utc(),
			'changed_at_utc'        => $result->changed_at_utc(),
			'changed_by_user_id'    => $result->changed_by_user_id(),
			'current_revision_id'   => $result->current_revision_id(),
			'latest_publication_id' => $result->latest_publication_id(),
			'lock_version'          => $result->lock_version(),
			'operation'             => $result->operation(),
			'previous_state'        => $result->previous_state(),
			'state'                 => $result->state(),
			'worksheet_id'          => $result->worksheet_id(),
		);
	}

	/**
	 * State projection.
	 *
	 * @param string      $state State.
	 * @param string      $revision_id Revision id.
	 * @param string|null $publication_id Publication id.
	 * @param int         $lock_version Lock version.
	 * @param string|null $archived_at Archived at.
	 * @return array<string,mixed>
	 */
	private function state_projection(
		string $state,
		string $revision_id,
		?string $publication_id,
		int $lock_version,
		?string $archived_at
	): array {
		return array(
			'archived_at_utc'       => $archived_at,
			'current_revision_id'   => $revision_id,
			'latest_publication_id' => $publication_id,
			'lock_version'          => $lock_version,
			'state'                 => $state,
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
		$expected = array(
			'actor_user_id',
			'operation',
			'worksheet_public_id',
			'command_id',
			'expected_state',
			'expected_revision_id',
			'expected_lock_version',
			'idempotency_storage_key',
			'request_digest',
			'correlation_id',
			'changed_at_utc',
			'audit_public_id',
			'outbox_public_id',
		);
		$this->assert_exact_keys( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle record actor is invalid.' );
		}
		$this->assert_operation( $record['operation'] );
		foreach ( array( 'worksheet_public_id', 'expected_revision_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if (
			( Worksheet_Lifecycle_Command::ARCHIVE === $record['operation']
				&& ! in_array( $record['expected_state'], array( 'draft', 'active' ), true ) )
			|| ( Worksheet_Lifecycle_Command::RESTORE === $record['operation'] && 'archived' !== $record['expected_state'] )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle record expected state is invalid.' );
		}
		foreach ( array( 'idempotency_storage_key', 'request_digest' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		if (
			! is_string( $record['command_id'] ) || strlen( $record['command_id'] ) < 8 || strlen( $record['command_id'] ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['command_id'] )
			|| ! is_string( $record['correlation_id'] ) || strlen( $record['correlation_id'] ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['correlation_id'] )
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle record command identity is invalid.' );
		}
		$this->assert_utc( (string) $record['changed_at_utc'] );
	}

	/**
	 * Event type.
	 *
	 * @param string $operation Operation.
	 * @return string
	 */
	private function event_type( string $operation ): string {
		$this->assert_operation( $operation );
		return Worksheet_Lifecycle_Command::ARCHIVE === $operation ? 'worksheet.archived' : 'worksheet.restored';
	}

	/**
	 * Reject invalid operation.
	 *
	 * @param mixed $operation Operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_operation( $operation ): void {
		if ( ! is_string( $operation ) || ! in_array( $operation, array( Worksheet_Lifecycle_Command::ARCHIVE, Worksheet_Lifecycle_Command::RESTORE ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle operation is invalid.' );
		}
	}

	/**
	 * Reject invalid root invariant.
	 *
	 * @param string      $state State.
	 * @param string|null $publication_id Publication id.
	 * @param string|null $archived_at Archived at.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_root_invariant( string $state, ?string $publication_id, ?string $archived_at ): void {
		if (
			( 'draft' === $state && ( null !== $publication_id || null !== $archived_at ) )
			|| ( 'active' === $state && ( null === $publication_id || null !== $archived_at ) )
			|| ( 'archived' === $state && null === $archived_at )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle fields are invalid.' );
		}
	}

	/**
	 * Decode WordPress worksheet lifecycle repository.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $json, string $hash ): array {
		$this->assert_hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( Throwable ) {
			throw Worksheet_Failure::stored_data_invalid();
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			return $value;
	}

	/**
	 * Reject invalid stored exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_stored_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_public_id( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$id = (string) $value;
		new Public_Id( $id );
		return $id;
	}

	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$utc = (string) $value;
		$this->assert_utc( $utc );
		return $utc;
	}

	/**
	 * Root state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function root_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'active', 'archived' ), true ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $value;
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
			throw new InvalidArgumentException( 'Worksheet lifecycle SHA-256 value is invalid.' );
		}
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle UTC value is invalid.' );
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
		throw Worksheet_Failure::stored_data_invalid();
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
		throw Worksheet_Failure::stored_data_invalid();
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
			throw Worksheet_Failure::storage_unavailable();
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
		if ( '' !== trim( (string) ( $this->database()->last_error ?? '' ) ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw Worksheet_Failure::storage_unavailable();
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
		if ( '' !== trim( (string) ( $this->database()->last_error ?? '' ) ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
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
		if ( false === $this->database()->insert( $table, $data ) ) {
			throw new RuntimeException( 'Worksheet lifecycle insert failed.' );
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
		if ( false === $this->database()->query( $sql ) ) {
			throw new RuntimeException( 'Worksheet lifecycle transaction query failed.' );
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
			|| ! method_exists( $database, 'get_var' ) || ! method_exists( $database, 'query' )
			|| ! method_exists( $database, 'insert' )
		) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $database;
	}
}
