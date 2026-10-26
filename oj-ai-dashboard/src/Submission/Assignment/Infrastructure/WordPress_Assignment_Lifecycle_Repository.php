<?php
/**
 * WordPress assignment schedule and lifecycle repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment_Command;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment_Result;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Lifecycle_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs C14-C18 with lock-version and schedule-version CAS.
 */
final class WordPress_Assignment_Lifecycle_Repository implements Assignment_Lifecycle_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS  = 'oj_ai_assignments';
	private const TABLE_AUDIT        = 'oj_ai_audit_events';
	private const TABLE_OUTBOX       = 'oj_ai_outbox_events';
	private const PAYLOAD_SCHEMA     = 'oj_ai.assignment_lifecycle_event';
	private const AUDIT_SCHEMA       = 'oj_ai.assignment_audit';
	private const AUTO_SUBMIT_SCHEMA = 'oj_ai.assignment_auto_submit_due';
	private const VERSION            = 1;

	/**
	 * Initialize WordPress assignment lifecycle repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, private ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @return Change_Assignment_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest, string $action ): ?Change_Assignment_Result {
		$this->assert_storage_key( $idempotency_storage_key );
		$this->assert_hash( $request_digest );
		$this->event_type( $action );
		return $this->receipt( $idempotency_storage_key, $request_digest, $action, false );
	}

	/**
	 * Change.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Change_Assignment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function change( array $record ): Change_Assignment_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], false );
		if ( $replay instanceof Change_Assignment_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], true );
			if ( $replay instanceof Change_Assignment_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$current = $this->lock_assignment( $record['assignment_public_id'] );
			if ( null === $current ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_expected( $current, $record );
			$mutation         = $this->derive_mutation( $current, $record );
			$new_lock_version = $record['expected_lock_version'] + 1;
			$this->update_or_fail(
				array(
					'lock_version'                 => $new_lock_version,
					'updated_by_user_id'           => $record['actor_user_id'],
					'updated_at_utc'               => $record['occurred_at_utc'],
					'state'                        => $mutation['state'],
					'opens_at_utc'                 => $mutation['schedule']['opens_at_utc'],
					'due_at_utc'                   => $mutation['schedule']['due_at_utc'],
					'closes_at_utc'                => $mutation['schedule']['closes_at_utc'],
					'schedule_version'             => $mutation['schedule_version'],
					'schedule_hash'                => $mutation['schedule_hash'],
					'auto_submit_enabled'          => $mutation['schedule']['auto_submit_enabled'] ? 1 : 0,
					'auto_submit_completed_at_utc' => $mutation['schedule_changed'] ? null : ( $current['auto_submit_completed_at_utc'] ?? null ),
				),
				array(
					'id'           => $this->positive_integer( $current['id'] ?? null ),
					'lock_version' => $record['expected_lock_version'],
				)
			);

			$result = new Change_Assignment_Result(
				array(
					'id'               => $record['assignment_public_id'],
					'state'            => $mutation['state'],
					'lock_version'     => $new_lock_version,
					'schedule_version' => $mutation['schedule_version'],
					'schedule_hash'    => $mutation['schedule_hash'],
					'schedule'         => $mutation['schedule'],
					'updated_at_utc'   => $record['occurred_at_utc'],
				),
				$record['action'],
				false
			);
			$this->assert_postcondition( $current, $result );
			$this->insert_audit( $current, $record, $result );
			$this->insert_outbox( $record, $result );
			if (
				Change_Assignment_Command::CLOSE === $record['action'] && $mutation['schedule']['auto_submit_enabled']
				&& ( $mutation['schedule_changed'] || null === ( $current['auto_submit_completed_at_utc'] ?? null ) )
			) {
				$this->insert_auto_submit_outbox( $record, $result );
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], false );
			if ( $replay instanceof Change_Assignment_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lock assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return array<string,mixed>|null
	 */
	private function lock_assignment( string $assignment_id ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT id, public_id, lock_version, context_type, context_id, context_revision, state, opens_at_utc, due_at_utc, closes_at_utc, schedule_version, schedule_hash, auto_submit_enabled, auto_submit_completed_at_utc FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $assignment_id )
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( array $current, array $record ): void {
		$id = (string) ( $current['public_id'] ?? '' );
		new Public_Id( $id );
		$state            = $this->state( $current['state'] ?? null );
		$lock_version     = $this->non_negative_integer( $current['lock_version'] ?? null );
		$schedule_version = $this->positive_integer( $current['schedule_version'] ?? null );
		$schedule         = $this->schedule_from_row( $current );
		$stored_hash      = (string) ( $current['schedule_hash'] ?? '' );
		$this->assert_hash( $stored_hash );
		if ( ! hash_equals( $stored_hash, $this->schedule_hash( $schedule, $schedule_version ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if (
			! hash_equals( $record['assignment_public_id'], $id ) || $record['expected_state'] !== $state
			|| $record['expected_lock_version'] !== $lock_version || $record['expected_schedule_version'] !== $schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $stored_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Derive mutation.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return array{state:string,schedule:array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool},schedule_version:int,schedule_hash:string,schedule_changed:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function derive_mutation( array $current, array $record ): array {
		$state      = $this->state( $current['state'] ?? null );
		$schedule   = $this->schedule_from_row( $current );
		$next       = $schedule;
		$next_state = $state;
		$now        = $record['occurred_at_utc'];
		switch ( $record['action'] ) {
			case Change_Assignment_Command::UPDATE_SCHEDULE:
				$next = $record['schedule'];
				if ( 'open' === $state ) {
					if ( null === $next['opens_at_utc'] || $next['opens_at_utc'] > $now || $next['closes_at_utc'] <= $now ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
					}
					$next_state = 'open';
				} else {
					if ( $next['closes_at_utc'] <= $now ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
					}
					$next_state = null === $next['opens_at_utc'] ? 'draft' : 'scheduled';
				}
				break;
			case Change_Assignment_Command::OPEN:
				if ( $schedule['closes_at_utc'] <= $now ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
				}
				if ( null === $next['opens_at_utc'] || $next['opens_at_utc'] > $now ) {
					$next['opens_at_utc'] = $now;
				}
				if ( $next['opens_at_utc'] > $next['due_at_utc'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
				}
				$next_state = 'open';
				break;
			case Change_Assignment_Command::CLOSE:
				if ( null === $next['opens_at_utc'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
				}
				if ( $now < $next['closes_at_utc'] ) {
					$next['closes_at_utc'] = $now;
				}
				if ( $next['due_at_utc'] > $next['closes_at_utc'] ) {
					$next['due_at_utc'] = $next['closes_at_utc'];
				}
				$next_state = 'closed';
				break;
			case Change_Assignment_Command::REOPEN:
				$next                 = $record['schedule'];
				$next['opens_at_utc'] = $now;
				if ( $next['opens_at_utc'] > $next['due_at_utc'] || $next['due_at_utc'] > $next['closes_at_utc'] || $next['closes_at_utc'] <= $now ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
				}
				$next_state = 'open';
				break;
			case Change_Assignment_Command::WITHDRAW:
				$next_state = 'withdrawn';
				break;
			default:
				throw new InvalidArgumentException( 'Assignment action is invalid.' );
		}
		$schedule_changed = $next !== $schedule;
		if ( in_array( $record['action'], array( Change_Assignment_Command::UPDATE_SCHEDULE, Change_Assignment_Command::REOPEN ), true ) ) {
			$schedule_changed = true;
		}
		$schedule_version = $this->positive_integer( $current['schedule_version'] ?? null ) + ( $schedule_changed ? 1 : 0 );
		return array(
			'state'            => $next_state,
			'schedule'         => $next,
			'schedule_version' => $schedule_version,
			'schedule_hash'    => $schedule_changed ? $this->schedule_hash( $next, $schedule_version ) : (string) $current['schedule_hash'],
			'schedule_changed' => $schedule_changed,
		);
	}

	/**
	 * Schedule from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}
	 */
	private function schedule_from_row( array $row ): array {
		return array(
			'opens_at_utc'        => $this->optional_utc( $row['opens_at_utc'] ?? null ),
			'due_at_utc'          => $this->required_utc( $row['due_at_utc'] ?? null ),
			'closes_at_utc'       => $this->required_utc( $row['closes_at_utc'] ?? null ),
			'auto_submit_enabled' => 1 === $this->zero_or_one( $row['auto_submit_enabled'] ?? null ),
		);
	}

	/**
	 * Schedule hash.
	 *
	 * @param array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool} $schedule Schedule.
	 * @param int                                                                                         $version Version.
	 * @return string
	 */
	private function schedule_hash( array $schedule, int $version ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => $schedule['auto_submit_enabled'],
					'closes_at_utc'       => $schedule['closes_at_utc'],
					'due_at_utc'          => $schedule['due_at_utc'],
					'opens_at_utc'        => $schedule['opens_at_utc'],
					'schedule_version'    => $version,
				)
			)
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed>      $current Current.
	 * @param Change_Assignment_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $current, Change_Assignment_Result $result ): void {
		$assignment = $result->assignment();
		$row        = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, schedule_version, schedule_hash FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $current['id'] ?? null ) )
			)
		);
		if (
			null === $row || ! hash_equals( $assignment['id'], (string) ( $row['public_id'] ?? '' ) )
			|| $assignment['lock_version'] !== $this->positive_integer( $row['lock_version'] ?? null )
			|| $assignment['state'] !== $this->state( $row['state'] ?? null )
			|| $assignment['schedule_version'] !== $this->positive_integer( $row['schedule_version'] ?? null )
			|| ! hash_equals( $assignment['schedule_hash'], (string) ( $row['schedule_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>      $current Current.
	 * @param array<string,mixed>      $record Record.
	 * @param Change_Assignment_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $current, array $record, Change_Assignment_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$assignment    = $result->assignment();
		$before        = $this->root_digest( $record['expected_state'], $record['expected_lock_version'], $record['expected_schedule_version'], $record['expected_schedule_hash'] );
		$after         = $this->root_digest( $assignment['state'], $assignment['lock_version'], $assignment['schedule_version'], $assignment['schedule_hash'] );
		$metadata_json = $this->canonical->encode(
			array(
				'action'                    => $record['action'],
				'command_id'                => $record['command_id'],
				'expected_lock_version'     => $record['expected_lock_version'],
				'expected_schedule_hash'    => $record['expected_schedule_hash'],
				'expected_schedule_version' => $record['expected_schedule_version'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_type    = $this->event_type( $record['action'] );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event_type,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $assignment['id'],
					'object_type'         => 'assignment',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event_type,
				'object_type'         => 'assignment',
				'object_public_id'    => $assignment['id'],
				'context_type'        => (string) ( $current['context_type'] ?? '' ),
				'context_id'          => (string) ( $current['context_id'] ?? '' ),
				'context_revision'    => (string) ( $current['context_revision'] ?? '' ),
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
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
	 * Insert outbox.
	 *
	 * @param array<string,mixed>      $record Record.
	 * @param Change_Assignment_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Change_Assignment_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => $result->assignment(),
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
				'aggregate_type'       => 'assignment',
				'aggregate_public_id'  => $result->assignment()['id'],
				'event_type'           => $this->event_type( $record['action'] ),
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
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
	 * Insert auto submit outbox.
	 *
	 * @param array<string,mixed>      $record Record.
	 * @param Change_Assignment_Result $result Result.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_auto_submit_outbox( array $record, Change_Assignment_Result $result ): void {
		$assignment  = $result->assignment();
		$storage_key = 'assignment-auto-submit:' . hash( 'sha256', $assignment['id'] . '|' . $assignment['schedule_hash'] );
		$existing    = $this->get_row(
			$this->prepare(
				'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
					. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1 FOR UPDATE',
				array( $storage_key )
			)
		);
		if ( null !== $existing ) {
			$payload = $this->decode_json( (string) ( $existing['payload_json'] ?? '' ), (string) ( $existing['payload_hash'] ?? '' ) );
			$keys    = array( 'assignment_id', 'schedule_hash', 'schedule_version' );
			if ( array_key_exists( 'candidate_cursor', $payload ) ) {
				$this->non_negative_integer( $payload['candidate_cursor'] );
				$keys[] = 'candidate_cursor';
			}
			$this->exact_keys( $payload, $keys );
			if (
				'assignment' !== ( $existing['aggregate_type'] ?? null )
				|| $assignment['id'] !== ( $existing['aggregate_public_id'] ?? null )
				|| 'assignment.auto_submit_due' !== ( $existing['event_type'] ?? null )
				|| self::AUTO_SUBMIT_SCHEMA !== ( $existing['payload_schema'] ?? null )
				|| self::VERSION !== $this->positive_integer( $existing['payload_version'] ?? null )
				|| $assignment['id'] !== ( $payload['assignment_id'] ?? null )
				|| $assignment['schedule_hash'] !== ( $payload['schedule_hash'] ?? null )
				|| $assignment['schedule_version'] !== ( $payload['schedule_version'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			// Cron owns the existing lease, progress cursor, and completion receipt.
			return;
		}
		$payload_json = $this->canonical->encode(
			array(
				'assignment_id'    => $assignment['id'],
				'schedule_hash'    => $assignment['schedule_hash'],
				'schedule_version' => $assignment['schedule_version'],
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['auto_submit_outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => 'assignment',
				'aggregate_public_id'  => $assignment['id'],
				'event_type'           => 'assignment.auto_submit_due',
				'idempotency_key'      => $storage_key,
				'payload_schema'       => self::AUTO_SUBMIT_SCHEMA,
				'payload_version'      => self::VERSION,
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
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @param bool   $for_update For update.
	 * @return Change_Assignment_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, string $action, bool $for_update ): ?Change_Assignment_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'assignment' !== ( $row['aggregate_type'] ?? null ) || $this->event_type( $action ) !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) || ( $row['aggregate_public_id'] ?? null ) !== ( $result['id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			return new Change_Assignment_Result( $result, $action, true ); } catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
		$this->exact_keys(
			$record,
			array(
				'actor_user_id',
				'assignment_public_id',
				'action',
				'audit_public_id',
				'outbox_public_id',
				'auto_submit_outbox_public_id',
				'command_id',
				'correlation_id',
				'idempotency_storage_key',
				'request_digest',
				'occurred_at_utc',
				'expected_state',
				'expected_lock_version',
				'expected_schedule_version',
				'expected_schedule_hash',
				'schedule',
			)
		);
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0 || ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1 ) {
			throw new InvalidArgumentException( 'Assignment lifecycle record is invalid.' );
		}
		foreach ( array( 'assignment_public_id', 'audit_public_id', 'outbox_public_id', 'auto_submit_outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'request_digest', 'expected_schedule_hash' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc( (string) $record['occurred_at_utc'] );
		$this->event_type( (string) $record['action'] );
		$this->state( $record['expected_state'] );
		if ( null !== $record['schedule'] && ! is_array( $record['schedule'] ) ) {
			throw new InvalidArgumentException( 'Assignment lifecycle schedule is invalid.' );
		}
	}

	/**
	 * Event type.
	 *
	 * @param string $action Action.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function event_type( string $action ): string {
		return match ( $action ) {
			Change_Assignment_Command::UPDATE_SCHEDULE => 'assignment.schedule_updated',
			Change_Assignment_Command::OPEN => 'assignment.opened',
			Change_Assignment_Command::CLOSE => 'assignment.closed',
			Change_Assignment_Command::REOPEN => 'assignment.reopened',
			Change_Assignment_Command::WITHDRAW => 'assignment.withdrawn',
			default => throw new InvalidArgumentException( 'Assignment lifecycle action is invalid.' ),
		};
	}

	/**
	 * Root digest.
	 *
	 * @param string $state State.
	 * @param int    $lock_version Lock version.
	 * @param int    $schedule_version Schedule version.
	 * @param string $schedule_hash Schedule hash.
	 * @return string
	 */
	private function root_digest( string $state, int $lock_version, int $schedule_version, string $schedule_hash ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'lock_version'     => $lock_version,
					'schedule_hash'    => $schedule_hash,
					'schedule_version' => $schedule_version,
					'state'            => $state,
				)
			)
		);
	}

	/**
	 * Exact keys.
	 *
	 * @param array<array-key,mixed> $value Value.
	 * @param array<int,string>      $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
		$this->assert_hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			return $value;
	}
	/**
	 * State.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^assignment:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment storage key is invalid.' );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} $this->assert_utc( $value );
		return $value;
	}
	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}
	/**
	 * Zero or one.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function zero_or_one( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value > 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		} return $value;
	}

	/**
	 * Update or fail.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_or_fail( array $data, array $where ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$affected = $db->update( self::TABLE_ASSIGNMENTS, $data, $where );
		if ( false === $affected || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		if ( 1 !== $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
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
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $row;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ): mixed {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_read( $db );
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->query( $sql ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'update', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
