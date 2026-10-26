<?php
/**
 * Durable assignment deadline queue for automatic submissions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submit_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable assignment deadline queue for automatic submissions.
 */
final class WordPress_Auto_Submit_Assignment_Repository implements Auto_Submit_Assignment_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';
	private const EVENT_TYPE        = 'assignment.auto_submit_due';
	private const PAYLOAD_SCHEMA    = 'oj_ai.assignment_auto_submit_due';
	private const VERSION           = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress auto submit assignment repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Compensate due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $outbox_public_id Outbox public id.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function compensate_due( string $now_utc, string $outbox_public_id ): bool {
		$this->utc( $now_utc );
		new Public_Id( $outbox_public_id );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$sql         = 'SELECT a.id AS assignment_pk, a.public_id AS assignment_id, a.created_by_user_id, a.schedule_version, a.schedule_hash, a.closes_at_utc FROM ' . self::TABLE_ASSIGNMENTS . ' a '
				. 'WHERE a.auto_submit_enabled = 1 AND a.auto_submit_completed_at_utc IS NULL AND a.closes_at_utc IS NOT NULL '
				. 'AND a.closes_at_utc <= %s AND a.state IN (%s,%s,%s) AND NOT EXISTS (SELECT 1 FROM ' . self::TABLE_OUTBOX . ' queued '
				. "WHERE queued.idempotency_key = CONCAT('assignment-auto-submit:', SHA2(CONCAT(a.public_id, '|', a.schedule_hash), 256))) "
				. 'ORDER BY a.closes_at_utc ASC, a.id ASC LIMIT 1 FOR UPDATE';
			$row         = $this->row( $this->prepare( $sql, array( $now_utc, 'scheduled', 'open', 'closed' ) ) );
			if ( null === $row ) {
				$this->query_or_fail( 'COMMIT' );
				return false;
			}
			$assignment_id    = $this->public_id( $row['assignment_id'] ?? null );
			$schedule_hash    = $this->hash_value( $row['schedule_hash'] ?? null );
			$schedule_version = $this->positive_integer( $row['schedule_version'] ?? null );
			$owner            = $this->positive_integer( $row['created_by_user_id'] ?? null );
			$closes           = $this->required_utc( $row['closes_at_utc'] ?? null );
			$payload_json     = $this->canonical->encode(
				array(
					'assignment_id'    => $assignment_id,
					'schedule_hash'    => $schedule_hash,
					'schedule_version' => $schedule_version,
				)
			);
			$this->insert_or_fail(
				self::TABLE_OUTBOX,
				array(
					'public_id'            => $outbox_public_id,
					'lock_version'         => 0,
					'created_by_user_id'   => $owner,
					'created_at_utc'       => $now_utc,
					'updated_by_user_id'   => $owner,
					'updated_at_utc'       => $now_utc,
					'aggregate_type'       => 'assignment',
					'aggregate_public_id'  => $assignment_id,
					'event_type'           => self::EVENT_TYPE,
					'idempotency_key'      => 'assignment-auto-submit:' . hash( 'sha256', $assignment_id . '|' . $schedule_hash ),
					'payload_schema'       => self::PAYLOAD_SCHEMA,
					'payload_version'      => self::VERSION,
					'payload_json'         => $payload_json,
					'payload_hash'         => hash( 'sha256', $payload_json ),
					'available_at_utc'     => $closes,
					'published_at_utc'     => null,
					'lease_token'          => null,
					'lease_expires_at_utc' => null,
					'attempt_count'        => 0,
					'last_error_code'      => null,
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Auto_Submit_Assignment_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Auto_Submit_Assignment_Work_Item {
		$this->utc( $now_utc );
		$this->utc( $lease_expires_at_utc );
		$this->lease( $lease_token );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$sql         = 'SELECT o.id AS outbox_pk, o.lock_version AS outbox_lock_version, o.aggregate_public_id, o.payload_schema, o.payload_version, o.payload_json, o.payload_hash, a.id AS assignment_pk, a.public_id AS assignment_id, a.created_by_user_id, a.context_type, a.context_id, a.context_revision, a.schedule_version, a.schedule_hash, a.due_at_utc, a.closes_at_utc FROM ' . self::TABLE_OUTBOX . ' o INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a '
				. 'ON a.public_id = o.aggregate_public_id WHERE o.aggregate_type = %s AND o.event_type = %s '
				. 'AND o.published_at_utc IS NULL AND o.available_at_utc <= %s AND (o.lease_token IS NULL OR o.lease_expires_at_utc <= %s) '
				. 'AND a.auto_submit_enabled = 1 AND a.auto_submit_completed_at_utc IS NULL AND a.closes_at_utc IS NOT NULL '
				. 'AND a.closes_at_utc <= %s AND a.state IN (%s,%s,%s) ORDER BY o.available_at_utc ASC, o.id ASC LIMIT 1 FOR UPDATE';
			$row         = $this->row( $this->prepare( $sql, array( 'assignment', self::EVENT_TYPE, $now_utc, $now_utc, $now_utc, 'scheduled', 'open', 'closed' ) ) );
			if ( null === $row ) {
				$this->query_or_fail( 'COMMIT' );
				return null;
			}
			$outbox_pk        = $this->positive_integer( $row['outbox_pk'] ?? null );
			$outbox_lock      = $this->non_negative_integer( $row['outbox_lock_version'] ?? null );
			$assignment_id    = $this->public_id( $row['assignment_id'] ?? null );
			$schedule_hash    = $this->hash_value( $row['schedule_hash'] ?? null );
			$schedule_version = $this->positive_integer( $row['schedule_version'] ?? null );
			$payload          = $this->payload( $row );
			if (
				$assignment_id !== ( $payload['assignment_id'] ?? null )
				|| $schedule_hash !== ( $payload['schedule_hash'] ?? null )
				|| $schedule_version !== ( $payload['schedule_version'] ?? null )
			) {
				$this->change_one(
					$this->prepare(
						'UPDATE ' . self::TABLE_OUTBOX . ' SET published_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
						. 'attempt_count = attempt_count + 1, last_error_code = %s, lock_version = lock_version + 1, updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND published_at_utc IS NULL',
						array( $now_utc, 'auto_submit_schedule_stale', $now_utc, $outbox_pk, $outbox_lock )
					)
				);
				$this->query_or_fail( 'COMMIT' );
				return null;
			}
			$this->change_one(
				$this->prepare(
					'UPDATE ' . self::TABLE_OUTBOX . ' SET lease_token = %s, lease_expires_at_utc = %s, attempt_count = attempt_count + 1, '
					. 'last_error_code = NULL, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d '
					. 'AND published_at_utc IS NULL',
					array( $lease_token, $lease_expires_at_utc, $now_utc, $outbox_pk, $outbox_lock )
				)
			);
			$item = new Auto_Submit_Assignment_Work_Item(
				$outbox_pk,
				$outbox_lock + 1,
				$this->positive_integer( $row['assignment_pk'] ?? null ),
				$assignment_id,
				$this->positive_integer( $row['created_by_user_id'] ?? null ),
				$this->token( $row['context_type'] ?? null, 32 ),
				$this->token( $row['context_id'] ?? null, 191 ),
				$this->token( $row['context_revision'] ?? null, 191 ),
				$schedule_version,
				$schedule_hash,
				$this->required_utc( $row['due_at_utc'] ?? null ),
				$this->required_utc( $row['closes_at_utc'] ?? null ),
				$lease_token,
				(int) ( $payload['candidate_cursor'] ?? 0 )
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Candidates.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param int                              $limit Maximum number of entries requested.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function candidates( Auto_Submit_Assignment_Work_Item $item, int $limit ): array {
		if ( $limit < 1 || $limit > 200 ) {
			throw new InvalidArgumentException( 'Auto-submit candidate limit is invalid.' );
		}
		$sql  = 'SELECT s.id AS candidate_pk, s.public_id AS submission_id, s.learner_user_id, s.state, s.lock_version, s.current_revision_public_id FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = s.assignment_id '
			. 'AND t.learner_user_id = s.learner_user_id WHERE s.assignment_id = %d AND t.state = %s '
			. 'AND s.state IN (%s,%s,%s) AND s.id > %d ORDER BY s.id ASC LIMIT %d';
		$rows = $this->rows( $this->prepare( $sql, array( $item->assignment_pk(), 'active', 'draft', 'reopened', 'conflict', $item->candidate_cursor(), $limit ) ) );
		if ( array() === $rows ) {
			$item->advance_cursor( 0 );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$item->advance_cursor( $this->positive_integer( $row['candidate_pk'] ?? null ) );
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'draft', 'reopened', 'conflict' ), true ) ) {
				$this->stored_invalid();
			}
			$result[] = array(
				'submission_id'       => $this->public_id( $row['submission_id'] ?? null ),
				'learner_user_id'     => $this->positive_integer( $row['learner_user_id'] ?? null ),
				'state'               => $state,
				'lock_version'        => $this->non_negative_integer( $row['lock_version'] ?? null ),
				'current_revision_id' => null === ( $row['current_revision_public_id'] ?? null ) ? null : $this->public_id( $row['current_revision_public_id'] ),
			);
		}
		return $result;
	}

	/**
	 * Finish.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param string                           $now_utc Now utc.
	 * @param string                           $retry_at_utc Retry at utc.
	 * @param string|null                      $error_code Error code.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function finish( Auto_Submit_Assignment_Work_Item $item, string $now_utc, string $retry_at_utc, ?string $error_code ): string {
		$this->utc( $now_utc );
		$this->utc( $retry_at_utc );
		if ( null !== $error_code && ( '' === $error_code || strlen( $error_code ) > 100 ) ) {
			throw new InvalidArgumentException( 'Auto-submit error code is invalid.' );
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$event       = $this->row(
				$this->prepare(
					'SELECT lock_version, lease_token, published_at_utc FROM ' . self::TABLE_OUTBOX . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					array( $item->outbox_pk() )
				)
			);
			$assignment  = $this->row(
				$this->prepare(
					'SELECT public_id, lock_version, created_by_user_id, schedule_version, schedule_hash, auto_submit_enabled, auto_submit_completed_at_utc FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					array( $item->assignment_pk() )
				)
			);
			if (
				null === $event || null === $assignment || null !== ( $event['published_at_utc'] ?? null )
				|| $item->outbox_lock_version() !== $this->non_negative_integer( $event['lock_version'] ?? null )
				|| ! hash_equals( $item->lease_token(), (string) ( $event['lease_token'] ?? '' ) )
				|| ! hash_equals( $item->assignment_id(), $this->public_id( $assignment['public_id'] ?? null ) )
				|| $item->schedule_version() !== $this->positive_integer( $assignment['schedule_version'] ?? null )
				|| ! hash_equals( $item->schedule_hash(), $this->hash_value( $assignment['schedule_hash'] ?? null ) )
				|| 1 !== $this->zero_or_one( $assignment['auto_submit_enabled'] ?? null )
			) {
				$this->rollback();
				return 'stale';
			}
			$remaining = $this->non_negative_integer(
				$this->scalar(
					$this->prepare(
						'SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_TARGETS . ' t '
						. 'ON t.assignment_id = s.assignment_id AND t.learner_user_id = s.learner_user_id WHERE s.assignment_id = %d '
						. 'AND t.state = %s AND s.state IN (%s,%s,%s)',
						array( $item->assignment_pk(), 'active', 'draft', 'reopened', 'conflict' )
					)
				)
			);
			$owner     = $this->positive_integer( $assignment['created_by_user_id'] ?? null );
			if ( $remaining > 0 ) {
				$payload_json = $this->canonical->encode(
					array(
						'assignment_id'    => $item->assignment_id(),
						'schedule_hash'    => $item->schedule_hash(),
						'schedule_version' => $item->schedule_version(),
						'candidate_cursor' => $item->candidate_cursor(),
					)
				);
				$this->change_one(
					$this->prepare(
						'UPDATE ' . self::TABLE_OUTBOX . ' SET payload_json = %s, payload_hash = %s, available_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
						. 'last_error_code = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
						. 'WHERE id = %d AND lock_version = %d AND lease_token = %s AND published_at_utc IS NULL',
						array( $payload_json, hash( 'sha256', $payload_json ), $retry_at_utc, $error_code ?? 'auto_submit_incomplete', $owner, $now_utc, $item->outbox_pk(), $item->outbox_lock_version(), $item->lease_token() )
					)
				);
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return 'retry_wait';
			}
			if ( null === ( $assignment['auto_submit_completed_at_utc'] ?? null ) ) {
				$this->change_one(
					$this->prepare(
						'UPDATE ' . self::TABLE_ASSIGNMENTS . ' SET auto_submit_completed_at_utc = %s, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND auto_submit_completed_at_utc IS NULL',
						array( $now_utc, $owner, $now_utc, $item->assignment_pk(), $this->non_negative_integer( $assignment['lock_version'] ?? null ) )
					)
				);
			}
			$this->change_one(
				$this->prepare(
					'UPDATE ' . self::TABLE_OUTBOX . ' SET published_at_utc = %s, lease_token = NULL, lease_expires_at_utc = NULL, '
					. 'last_error_code = NULL, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND lease_token = %s AND published_at_utc IS NULL',
					array( $now_utc, $owner, $now_utc, $item->outbox_pk(), $item->outbox_lock_version(), $item->lease_token() )
				)
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return 'completed';
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			} throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			} throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Payload.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function payload( array $row ): array {
		if ( self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$json = (string) ( $row['payload_json'] ?? '' );
		if ( ! hash_equals( $this->hash_value( $row['payload_hash'] ?? null ), hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$payload = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $payload ) || array_is_list( $payload ) || ! hash_equals( $json, $this->canonical->encode( $payload ) ) ) {
			$this->stored_invalid();
		}
		$keys = array_keys( $payload );
		sort( $keys, SORT_STRING );
		if ( array( 'assignment_id', 'schedule_hash', 'schedule_version' ) !== $keys && array( 'assignment_id', 'candidate_cursor', 'schedule_hash', 'schedule_version' ) !== $keys ) {
			$this->stored_invalid();
		}
		if ( array_key_exists( 'candidate_cursor', $payload ) ) {
			$this->non_negative_integer( $payload['candidate_cursor'] );
		}
		$this->public_id( $payload['assignment_id'] ?? null );
		$this->hash_value( $payload['schedule_hash'] ?? null );
		$this->positive_integer( $payload['schedule_version'] ?? null );
		return $payload;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		} try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		} $this->utc( $value );
		return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		$value = is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ? (int) $value : $value;
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		$value = is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ? (int) $value : $value;
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Zero or one.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function zero_or_one( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value > 1 ) {
			$this->stored_invalid();
		} return $value;
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
			throw new InvalidArgumentException( 'Auto-submit UTC value is invalid.' );
		}
	}
	/**
	 * Lease.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lease( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Auto-submit lease token is invalid.' );
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
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
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		} return array_values( $rows );
	}
	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ): mixed {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_read( $db );
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
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Change one.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_one( string $sql ): void {
		$db = $this->database();
		$this->reset_error( $db );
		if ( 1 !== $db->query( $sql ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
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
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
