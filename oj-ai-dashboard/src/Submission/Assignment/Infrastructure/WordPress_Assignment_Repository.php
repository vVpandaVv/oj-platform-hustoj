<?php
/**
 * WordPress assignment repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Add_Assignment_Targets;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Result;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Target_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns literal-table assignment transactions and read projections.
 */
final class WordPress_Assignment_Repository implements Assignment_Repository_Port_Interface, Assignment_Target_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS  = 'oj_ai_assignments';
	private const TABLE_TARGETS      = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_SUBMISSIONS  = 'oj_ai_submissions';
	private const TABLE_AUDIT        = 'oj_ai_audit_events';
	private const TABLE_OUTBOX       = 'oj_ai_outbox_events';
	private const EVENT_TYPE         = 'assignment.created';
	private const TARGET_EVENT       = 'assignment.targets_added';
	private const TARGET_SCHEMA      = 'oj_ai.assignment_targets_added_event';
	private const PAYLOAD_SCHEMA     = 'oj_ai.assignment_created_event';
	private const AUDIT_SCHEMA       = 'oj_ai.assignment_audit';
	private const VERSION            = 1;

	/**
	 * Initialize WordPress assignment repository dependencies and state.
	 *
	 * @param object|null              $database Database.
	 * @param Canonical_Json|null      $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 */
	public function __construct(
		private ?object $database = null,
		private ?Canonical_Json $canonical = null,
		private ?Signed_Cursor_Codec $cursors = null
	) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
	}

	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Assignment_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Create_Assignment_Result {
		$this->assert_storage_key( $idempotency_storage_key );
		$this->assert_hash( $request_digest );
		return $this->receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Create WordPress assignment repository.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Create_Assignment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( array $record ): Create_Assignment_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $replay instanceof Create_Assignment_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Create_Assignment_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$publication = $this->lock_publication( $record['worksheet_publication_public_id'] );
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Create_Assignment_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}
			if (
				null === $publication
				|| ! hash_equals( $record['publication_hash'], (string) ( $publication['publication_hash'] ?? '' ) )
			) {
				throw Assignment_Failure::because( Assignment_Failure::PUBLICATION_CONFLICT );
			}
			$title = $this->publication_title( $publication );
			$this->assert_no_target_overlap( $record );
			$schedule = $record['schedule'];
			$this->insert_or_fail(
				self::TABLE_ASSIGNMENTS,
				array(
					'public_id'                       => $record['assignment_public_id'],
					'lock_version'                    => 0,
					'created_by_user_id'              => $record['actor_user_id'],
					'created_at_utc'                  => $record['occurred_at_utc'],
					'updated_by_user_id'              => $record['actor_user_id'],
					'updated_at_utc'                  => $record['occurred_at_utc'],
					'context_type'                    => $record['context_type'],
					'context_id'                      => $record['context_id'],
					'context_revision'                => $record['context_revision'],
					'worksheet_publication_public_id' => $record['worksheet_publication_public_id'],
					'publication_hash'                => $record['publication_hash'],
					'title_snapshot'                  => $title,
					'state'                           => $record['state'],
					'opens_at_utc'                    => $schedule['opens_at_utc'],
					'due_at_utc'                      => $schedule['due_at_utc'],
					'closes_at_utc'                   => $schedule['closes_at_utc'],
					'target_roster_revision'          => $record['target_roster_revision'],
					'schedule_version'                => 1,
					'schedule_hash'                   => $record['schedule_hash'],
					'auto_submit_enabled'             => $schedule['auto_submit_enabled'] ? 1 : 0,
					'auto_submit_completed_at_utc'    => null,
					'resubmission_policy_schema'      => $record['resubmission_policy_schema'],
					'resubmission_policy_version'     => $record['resubmission_policy_version'],
					'resubmission_policy_json'        => $record['resubmission_policy_json'],
					'resubmission_policy_hash'        => $record['resubmission_policy_hash'],
					'target_snapshot_hash'            => $record['target_snapshot_hash'],
				)
			);
			$assignment_pk = $this->insert_id();

			foreach ( $record['targets'] as $target ) {
				$this->insert_or_fail(
					self::TABLE_TARGETS,
					array(
						'public_id'                => $target['public_id'],
						'content_hash'             => $target['content_hash'],
						'created_by_user_id'       => $record['actor_user_id'],
						'created_at_utc'           => $record['occurred_at_utc'],
						'assignment_id'            => $assignment_pk,
						'target_type'              => $record['context_type'],
						'target_context_id'        => $record['context_id'],
						'target_context_revision'  => $record['context_revision'],
						'membership_revision'      => $target['membership_revision'],
						'learner_user_id'          => $target['learner_user_id'],
						'display_snapshot_schema'  => $target['display_snapshot_schema'],
						'display_snapshot_version' => $target['display_snapshot_version'],
						'display_snapshot_json'    => $target['display_snapshot_json'],
						'display_snapshot_hash'    => $target['display_snapshot_hash'],
						'snapshot_hash'            => $target['snapshot_hash'],
						'state'                    => $target['state'],
					)
				);
			}

			$result = $this->result_from_record( $record, $title, false );
			$this->insert_audit( $record, $result->assignment() );
			$this->insert_outbox( $record, $result->assignment() );
			$this->assert_postcondition( $assignment_pk, $record );
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
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $replay instanceof Create_Assignment_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Read the full append-only target set.
	 *
	 * @param string $assignment_id Assignment public ID.
	 * @return array<string,mixed>|null
	 */
	public function target_snapshot( string $assignment_id ): ?array {
		new Public_Id( $assignment_id );
		$row = $this->get_row( $this->prepare( 'SELECT * FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s', array( $assignment_id ) ) );
		if ( null === $row ) {
			return null;
		}
		$targets = $this->get_results( $this->prepare( 'SELECT learner_user_id, snapshot_hash, state FROM ' . self::TABLE_TARGETS . ' WHERE assignment_id = %d ORDER BY learner_user_id ASC', array( (int) $row['id'] ) ) );
		foreach ( $targets as &$target ) {
			$target['learner_user_id'] = null === $target['learner_user_id'] ? null : $this->positive_integer( $target['learner_user_id'] );
			$this->assert_hash( (string) $target['snapshot_hash'] );
		}
		unset( $target );
		return array(
			'assignment' => $row,
			'targets'    => $targets,
		);
	}

	/**
	 * Read an append receipt, without reapplying it to a changed roster.
	 *
	 * @param string $key Scoped storage key.
	 * @param string $digest Request digest.
	 * @param bool   $locked Use a current locking read inside a transaction.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function target_receipt( string $key, string $digest, bool $locked = false ): ?array {
		$this->assert_storage_key( $key );
		$this->assert_hash( $digest );
		$row = $this->get_row( $this->prepare( 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s' . ( $locked ? ' FOR UPDATE' : '' ), array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		$value = $this->decode_json( (string) $row['payload_json'], (string) $row['payload_hash'] );
		if ( self::TARGET_EVENT !== $row['event_type'] || self::TARGET_SCHEMA !== $row['payload_schema'] || 1 !== (int) $row['payload_version']
			|| ! is_array( $value['result'] ?? null ) || $row['aggregate_public_id'] !== ( $value['result']['id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		if ( ! is_string( $value['request_digest'] ?? null ) || ! hash_equals( $digest, $value['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		return array_merge( $value['result'], array( 'replayed' => true ) );
	}

	/**
	 * Append targets under the same publication lock used by initial creation.
	 *
	 * @param array<string,mixed> $record Validated append command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function add_targets( array $record ): array {
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$publication = $this->lock_publication( $record['worksheet_publication_public_id'], false );
			$replay      = $this->target_receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( null !== $replay ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}
			if ( null === $publication || ! hash_equals( $record['publication_hash'], (string) $publication['publication_hash'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::PUBLICATION_CONFLICT );
			}
			$row = $this->get_row( $this->prepare( 'SELECT * FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s FOR UPDATE', array( $record['assignment_public_id'] ) ) );
			if ( null === $row ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			foreach ( array( 'context_type', 'context_id', 'context_revision', 'worksheet_publication_public_id', 'publication_hash' ) as $field ) {
				if ( $row[ $field ] !== $record[ $field ] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
					throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
				}
			}
			Add_Assignment_Targets::assert_open( $row, gmdate( 'Y-m-d H:i:s' ) );
			if ( (int) $row['lock_version'] !== $record['expected']['lock_version'] || ! hash_equals( $row['target_snapshot_hash'], $record['expected']['target_snapshot_hash'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
			}
			$existing = $this->get_results( $this->prepare( 'SELECT learner_user_id, snapshot_hash, state FROM ' . self::TABLE_TARGETS . ' WHERE assignment_id = %d ORDER BY learner_user_id ASC FOR UPDATE', array( (int) $row['id'] ) ) );
			$selected = array_column( $record['targets'], 'learner_user_id' );
			if ( count( $selected ) < 1 || count( $selected ) !== count( array_unique( $selected ) ) || array_intersect( $selected, array_column( $existing, 'learner_user_id' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
			}
			if ( count( $existing ) + count( $selected ) > 5000 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
			}
			$this->assert_no_target_overlap( $record );
			foreach ( $record['targets'] as $target ) {
				$this->insert_or_fail(
					self::TABLE_TARGETS,
					array_merge(
						$target,
						array(
							'created_by_user_id'      => $record['actor_user_id'],
							'created_at_utc'          => $record['occurred_at_utc'],
							'assignment_id'           => (int) $row['id'],
							'target_type'             => $row['context_type'],
							'target_context_id'       => $row['context_id'],
							'target_context_revision' => $row['context_revision'],
						)
					)
				);
			}
			$combined = array_merge( $existing, $record['targets'] );
			usort( $combined, static fn ( array $a, array $b ): int => (int) $a['learner_user_id'] <=> (int) $b['learner_user_id'] );
			$record['before_digest']        = $row['target_snapshot_hash'];
			$record['target_snapshot_hash'] = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'context'         => array(
							'id'       => $row['context_id'],
							'revision' => $row['context_revision'],
							'type'     => $row['context_type'],
						),
						'roster_revision' => $record['target_roster_revision'],
						'target_hashes'   => array_column( $combined, 'snapshot_hash' ),
					)
				)
			);
			$this->query_or_fail(
				$this->prepare(
					'UPDATE ' . self::TABLE_ASSIGNMENTS . ' SET lock_version = lock_version + 1, target_snapshot_hash = %s, target_roster_revision = %s, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d',
					array( $record['target_snapshot_hash'], $record['target_roster_revision'], $record['actor_user_id'], $record['occurred_at_utc'], (int) $row['id'], (int) $row['lock_version'] )
				)
			);
			$result = array(
				'id'                   => $record['assignment_public_id'],
				'added_count'          => count( $selected ),
				'learner_user_ids'     => $selected,
				'target_count'         => count( array_filter( $combined, static fn ( array $target ): bool => 'active' === $target['state'] ) ),
				'lock_version'         => (int) $row['lock_version'] + 1,
				'target_snapshot_hash' => $record['target_snapshot_hash'],
				'replayed'             => false,
			);
			$this->insert_audit( $record, $result, self::TARGET_EVENT );
			$this->insert_outbox( $record, $result, self::TARGET_EVENT );
			$verified = $this->target_snapshot( $record['assignment_public_id'] );
			if ( null === $verified || count( $verified['targets'] ) !== count( $combined )
				|| (int) $verified['assignment']['lock_version'] !== $result['lock_version']
				|| $verified['assignment']['target_snapshot_hash'] !== $record['target_snapshot_hash'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
			$this->query_or_fail( 'COMMIT' );
			return $result;
		} catch ( Throwable $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( $failure instanceof Assignment_Failure ) {
				throw $failure;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * List context.
	 *
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_context( string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): array {
		$cursor_value = $this->cursors->decode( $cursor, 'assignment_list' );
		$sql          = 'SELECT a.*, ' . $this->worksheet_reference_sql() . ', (SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' t WHERE t.assignment_id = a.id AND t.state = %s) AS target_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' s WHERE s.assignment_id = a.id AND s.started_at_utc IS NOT NULL) AS started_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' s WHERE s.assignment_id = a.id AND s.submitted_at_utc IS NOT NULL) AS submitted_count '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s';
		$values       = array( 'active', $context_type, $context_id, $context_revision );
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'c', 'i', 'r', 't', 'u' ) );
			if ( $cursor_value['c'] !== $context_id || $cursor_value['r'] !== $context_revision || $cursor_value['t'] !== $context_type ) {
				throw new InvalidArgumentException( 'Assignment cursor Context does not match.' );
			}
			new Public_Id( (string) $cursor_value['i'] );
			$this->assert_utc( (string) $cursor_value['u'] );
			$sql     .= ' AND (a.updated_at_utc < %s OR (a.updated_at_utc = %s AND a.public_id < %s))';
			$values[] = $cursor_value['u'];
			$values[] = $cursor_value['u'];
			$values[] = $cursor_value['i'];
		}
		$sql     .= ' ORDER BY a.updated_at_utc DESC, a.public_id DESC LIMIT %d';
		$values[] = $limit + 1;
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}
		$items = array_map( fn ( array $row ): array => $this->assignment_projection( $row ), $rows );
		$next  = '';
		if ( $has_more && array() !== $items ) {
			$last = $items[ count( $items ) - 1 ];
			$next = $this->cursors->encode(
				'assignment_list',
				array(
					'c' => $context_id,
					'i' => $last['id'],
					'r' => $context_revision,
					't' => $context_type,
					'u' => $last['updated_at_utc'],
				)
			);
		}
		return array(
			'items'       => $items,
			'next_cursor' => $next,
		);
	}

	/**
	 * Find context assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_context_assignment( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		$sql = 'SELECT a.*, ' . $this->worksheet_reference_sql() . ', (SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' t WHERE t.assignment_id = a.id AND t.state = %s) AS target_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' s WHERE s.assignment_id = a.id AND s.started_at_utc IS NOT NULL) AS started_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSIONS . ' s WHERE s.assignment_id = a.id AND s.submitted_at_utc IS NOT NULL) AS submitted_count '
			. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a WHERE a.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( 'active', $assignment_id, $context_type, $context_id, $context_revision ) ) );
		if ( null === $row ) {
			return null;
		}
		$assignment   = $this->assignment_projection( $row );
		$cursor_value = $this->cursors->decode( $cursor, 'assignment_target_list' );
		$target_sql   = 'SELECT public_id, content_hash, target_type, target_context_id, target_context_revision, membership_revision, learner_user_id, display_snapshot_schema, display_snapshot_version, display_snapshot_json, display_snapshot_hash, snapshot_hash, state FROM ' . self::TABLE_TARGETS . ' WHERE assignment_id = %d';
		$values       = array( $this->positive_integer( $row['id'] ?? null ) );
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'a', 'i', 'u' ) );
			if ( $cursor_value['a'] !== $assignment_id ) {
				throw new InvalidArgumentException( 'Assignment target cursor does not match.' );
			}
			new Public_Id( (string) $cursor_value['i'] );
			$user_id     = $this->positive_integer( $cursor_value['u'] ?? null );
			$target_sql .= ' AND (learner_user_id > %d OR (learner_user_id = %d AND public_id > %s))';
			$values[]    = $user_id;
			$values[]    = $user_id;
			$values[]    = $cursor_value['i'];
		}
		$target_sql .= ' ORDER BY learner_user_id ASC, public_id ASC LIMIT %d';
		$values[]    = $limit + 1;
		$target_rows = $this->get_results( $this->prepare( $target_sql, $values ) );
		$has_more    = count( $target_rows ) > $limit;
		if ( $has_more ) {
			array_pop( $target_rows );
		}
		$targets = array_map( fn ( array $target ): array => $this->target_projection( $target ), $target_rows );
		$next    = '';
		if ( $has_more && array() !== $targets ) {
			$last = $targets[ count( $targets ) - 1 ];
			$next = $this->cursors->encode(
				'assignment_target_list',
				array(
					'a' => $assignment_id,
					'i' => $last['id'],
					'u' => $last['learner_user_id'],
				)
			);
		}
		return array(
			'assignment'  => $assignment,
			'targets'     => $targets,
			'next_cursor' => $next,
		);
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $assignment_id ): ?array {
		$row = $this->get_row(
			$this->prepare(
				'SELECT context_type, context_id, context_revision, schedule_version, state FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s LIMIT 1',
				array( $assignment_id )
			)
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'context_type'     => (string) ( $row['context_type'] ?? '' ),
			'context_id'       => (string) ( $row['context_id'] ?? '' ),
			'context_revision' => (string) ( $row['context_revision'] ?? '' ),
			'current_revision' => (string) $this->positive_integer( $row['schedule_version'] ?? null ),
			'state'            => $this->state( $row['state'] ?? null ),
		);
	}

	/**
	 * Reject invalid no target overlap.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_target_overlap( array $record ): void {
		// The publication lock serializes creates; locking reads also see the last committed targets.
		foreach ( array_chunk( array_column( $record['targets'], 'learner_user_id' ), 500 ) as $learners ) {
			$sql    = 'SELECT a.id FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
				. 'WHERE a.worksheet_publication_public_id = %s AND a.state <> %s AND t.state = %s '
				. 'AND t.learner_user_id IN (' . implode( ',', array_fill( 0, count( $learners ), '%d' ) ) . ') LIMIT 1 FOR UPDATE';
			$values = array_merge( array( $record['worksheet_publication_public_id'], 'withdrawn', 'active' ), $learners );
			if ( null !== $this->get_row( $this->prepare( $sql, $values ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::TARGET_OVERLAP );
			}
		}
	}

	/**
	 * Publication title.
	 *
	 * @param array<string,mixed> $publication Publication.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function publication_title( array $publication ): string {
		$document     = $this->decode_json( (string) ( $publication['student_document_json'] ?? '' ), (string) ( $publication['student_document_hash'] ?? '' ) );
		$payload      = $document['payload'] ?? null;
		$title        = is_array( $payload ) && ! array_is_list( $payload ) ? ( $payload['title'] ?? null ) : null;
		$payload_hash = $document['payload_hash'] ?? null;
		if (
			! is_string( $title ) || '' === trim( $title ) || strlen( $title ) > 255
			|| ! is_string( $payload_hash ) || ! hash_equals( hash( 'sha256', $this->canonical->encode( $payload ) ), $payload_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return $title;
	}

	/**
	 * Lock publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param bool   $latest Require the latest publication for a new assignment only.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_publication( string $publication_id, bool $latest = true ): ?array {
		$sql = 'SELECT p.public_id, p.content_hash, p.publication_hash, p.state, p.student_document_json, p.student_document_hash, w.state AS worksheet_state, w.latest_publication_public_id FROM ' . self::TABLE_PUBLICATIONS . ' p '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = p.worksheet_id WHERE p.public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $publication_id ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'active' !== ( $row['state'] ?? null ) || 'active' !== ( $row['worksheet_state'] ?? null )
			|| ( $latest && ! hash_equals( $publication_id, (string) ( $row['latest_publication_public_id'] ?? '' ) ) )
			|| ! hash_equals( (string) ( $row['content_hash'] ?? '' ), (string) ( $row['publication_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::PUBLICATION_CONFLICT );
		}
		return $row;
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param int                 $assignment_pk Assignment pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( int $assignment_pk, array $record ): void {
		$row = $this->get_row(
			$this->prepare(
				'SELECT a.public_id, a.publication_hash, a.target_roster_revision, a.target_snapshot_hash, a.state, (SELECT COUNT(*) FROM ' . self::TABLE_TARGETS . ' t WHERE t.assignment_id = a.id) AS target_count '
					. 'FROM ' . self::TABLE_ASSIGNMENTS . ' a WHERE a.id = %d LIMIT 1 FOR UPDATE',
				array( $assignment_pk )
			)
		);
		if (
			null === $row || ! hash_equals( $record['assignment_public_id'], (string) ( $row['public_id'] ?? '' ) )
			|| ! hash_equals( $record['publication_hash'], (string) ( $row['publication_hash'] ?? '' ) )
			|| ! hash_equals( $record['target_roster_revision'], (string) ( $row['target_roster_revision'] ?? '' ) )
			|| ! hash_equals( $record['target_snapshot_hash'], (string) ( $row['target_snapshot_hash'] ?? '' ) )
			|| $record['state'] !== ( $row['state'] ?? null )
			|| count( $record['targets'] ) !== $this->positive_integer( $row['target_count'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array               $record Record.
	 * @param array<string,mixed> $result Result.
	 * @param string              $event Event type.
	 * @return void
	 */
	private function insert_audit( array $record, array $result, string $event = self::EVENT_TYPE ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->assert_hash( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'           => $record['command_id'],
				'publication_hash'     => $record['publication_hash'],
				'roster_revision'      => $record['target_roster_revision'],
				'target_count'         => count( $record['targets'] ),
				'target_snapshot_hash' => $record['target_snapshot_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['target_snapshot_hash'],
					'before_digest'       => $record['before_digest'] ?? null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $result['id'],
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
				'action_key'          => $event,
				'object_type'         => 'assignment',
				'object_public_id'    => $result['id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $record['before_digest'] ?? null,
				'after_digest'        => $record['target_snapshot_hash'],
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
	 * @param array               $record Record.
	 * @param array<string,mixed> $result Result.
	 * @param string              $event Event type.
	 * @return void
	 */
	private function insert_outbox( array $record, array $result, string $event = self::EVENT_TYPE ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => $result,
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
				'aggregate_public_id'  => $result['id'],
				'event_type'           => $event,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::TARGET_EVENT === $event ? self::TARGET_SCHEMA : self::PAYLOAD_SCHEMA,
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
	 * @param bool   $for_update For update.
	 * @return Create_Assignment_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Create_Assignment_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'assignment' !== ( $row['aggregate_type'] ?? null ) || self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
			return new Create_Assignment_Result( $result, true ); } catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
			}
	}

	/**
	 * Result from record.
	 *
	 * @param array  $record Record.
	 * @param string $title Title.
	 * @param bool   $replayed Replayed.
	 * @return Create_Assignment_Result
	 */
	private function result_from_record( array $record, string $title, bool $replayed ): Create_Assignment_Result {
		$schedule = $record['schedule'];
		return new Create_Assignment_Result(
			array(
				'id'                       => $record['assignment_public_id'],
				'worksheet_publication_id' => $record['worksheet_publication_public_id'],
				'publication_hash'         => $record['publication_hash'],
				'title'                    => $title,
				'state'                    => $record['state'],
				'lock_version'             => 0,
				'context'                  => array(
					'type'     => $record['context_type'],
					'id'       => $record['context_id'],
					'revision' => $record['context_revision'],
				),
				'target_roster_revision'   => $record['target_roster_revision'],
				'target_snapshot_hash'     => $record['target_snapshot_hash'],
				'target_count'             => count( $record['targets'] ),
				'schedule_version'         => 1,
				'schedule_hash'            => $record['schedule_hash'],
				'schedule'                 => $schedule,
				'resubmission_policy'      => $this->decode_json( $record['resubmission_policy_json'], $record['resubmission_policy_hash'] ),
				'created_at_utc'           => $record['occurred_at_utc'],
				'updated_at_utc'           => $record['occurred_at_utc'],
				'actions'                  => $this->actions( $record['state'] ),
			),
			$replayed
		);
	}

	/**
	 * Assignment projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function assignment_projection( array $row ): array {
		$id = (string) ( $row['public_id'] ?? '' );
		new Public_Id( $id );
		foreach ( array( 'publication_hash', 'schedule_hash', 'resubmission_policy_hash', 'target_snapshot_hash' ) as $field ) {
			$this->assert_hash( (string) ( $row[ $field ] ?? '' ) );
		}
		$created = (string) ( $row['created_at_utc'] ?? '' );
		$updated = (string) ( $row['updated_at_utc'] ?? '' );
		$this->assert_utc( $created );
		$this->assert_utc( $updated );
		$policy = $this->decode_json( (string) ( $row['resubmission_policy_json'] ?? '' ), (string) ( $row['resubmission_policy_hash'] ?? '' ) );
		$state  = $this->state( $row['state'] ?? null );
		return array(
			'id'                       => $id,
			'worksheet_publication_id' => (string) ( $row['worksheet_publication_public_id'] ?? '' ),
			'worksheet_id'             => $row['worksheet_public_id'] ?? null,
			'publication_hash'         => (string) $row['publication_hash'],
			'title'                    => (string) ( $row['title_snapshot'] ?? '' ),
			'state'                    => $state,
			'lock_version'             => $this->non_negative_integer( $row['lock_version'] ?? null ),
			'context'                  => array(
				'type'     => (string) ( $row['context_type'] ?? '' ),
				'id'       => (string) ( $row['context_id'] ?? '' ),
				'revision' => (string) ( $row['context_revision'] ?? '' ),
			),
			'target_roster_revision'   => (string) ( $row['target_roster_revision'] ?? '' ),
			'target_snapshot_hash'     => (string) $row['target_snapshot_hash'],
			'target_count'             => $this->non_negative_integer( $row['target_count'] ?? null ),
			'progress'                 => array(
				'started'   => $this->non_negative_integer( $row['started_count'] ?? 0 ),
				'submitted' => $this->non_negative_integer( $row['submitted_count'] ?? 0 ),
			),
			'schedule_version'         => $this->positive_integer( $row['schedule_version'] ?? null ),
			'schedule_hash'            => (string) $row['schedule_hash'],
			'schedule'                 => array(
				'opens_at_utc'        => $this->optional_utc( $row['opens_at_utc'] ?? null ),
				'due_at_utc'          => $this->required_utc( $row['due_at_utc'] ?? null ),
				'closes_at_utc'       => $this->required_utc( $row['closes_at_utc'] ?? null ),
				'auto_submit_enabled' => 1 === $this->zero_or_one( $row['auto_submit_enabled'] ?? null ),
			),
			'resubmission_policy'      => $policy,
			'created_at_utc'           => $created,
			'updated_at_utc'           => $updated,
			'content_hash'             => hash( 'sha256', $id . '|' . $updated ),
			'actions'                  => $this->actions( $state ),
		);
	}

	/**
	 * Worksheet reference sql.
	 *
	 * @return string
	 */
	private function worksheet_reference_sql(): string {
		return '(SELECT w.public_id FROM ' . self::TABLE_PUBLICATIONS . ' p INNER JOIN ' . self::TABLE_WORKSHEETS
			. ' w ON w.id = p.worksheet_id WHERE p.public_id = a.worksheet_publication_public_id LIMIT 1) AS worksheet_public_id';
	}

	/**
	 * Target projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_projection( array $row ): array {
		$id = (string) ( $row['public_id'] ?? '' );
		new Public_Id( $id );
		foreach ( array( 'content_hash', 'display_snapshot_hash', 'snapshot_hash' ) as $field ) {
			$this->assert_hash( (string) ( $row[ $field ] ?? '' ) );
		}
		$display = $this->decode_json( (string) ( $row['display_snapshot_json'] ?? '' ), (string) $row['display_snapshot_hash'] );
		if ( array_keys( $display ) !== array( 'display_name' ) || ! is_string( $display['display_name'] ) || '' === $display['display_name'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
		}
		return array(
			'id'                  => $id,
			'learner_user_id'     => $this->positive_integer( $row['learner_user_id'] ?? null ),
			'display_name'        => $display['display_name'],
			'membership_revision' => (string) ( $row['membership_revision'] ?? '' ),
			'snapshot_hash'       => (string) $row['snapshot_hash'],
			'state'               => (string) ( $row['state'] ?? '' ),
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
			'actor_user_id',
			'assignment_public_id',
			'audit_public_id',
			'outbox_public_id',
			'command_id',
			'correlation_id',
			'idempotency_storage_key',
			'request_digest',
			'occurred_at_utc',
			'worksheet_publication_public_id',
			'publication_hash',
			'context_type',
			'context_id',
			'context_revision',
			'target_roster_revision',
			'target_snapshot_hash',
			'state',
			'schedule',
			'schedule_hash',
			'resubmission_policy_schema',
			'resubmission_policy_version',
			'resubmission_policy_json',
			'resubmission_policy_hash',
			'targets',
		);
		$this->exact_keys( $record, $required );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_array( $record['schedule'] ) || ! is_array( $record['targets'] ) || ! array_is_list( $record['targets'] ) || count( $record['targets'] ) < 1 || count( $record['targets'] ) > 5000 ) {
			throw new InvalidArgumentException( 'Assignment record is invalid.' );
		}
		foreach ( array( 'assignment_public_id', 'audit_public_id', 'outbox_public_id', 'worksheet_publication_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'request_digest', 'publication_hash', 'target_snapshot_hash', 'schedule_hash', 'resubmission_policy_hash' ) as $field ) {
			$this->assert_hash( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc( (string) $record['occurred_at_utc'] );
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
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
	 * Actions.
	 *
	 * @param string $state State.
	 * @return array<int,string>
	 */
	private function actions( string $state ): array {
		return match ( $state ) {
			'draft', 'scheduled' => array( 'assignment.update_schedule', 'assignment.open', 'assignment.withdraw' ),
			'open' => array( 'assignment.update_schedule', 'assignment.close', 'assignment.withdraw' ),
			'closed' => array( 'assignment.reopen', 'assignment.withdraw' ),
			default => array(),
		};
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
		if ( null === $value ) {
			return null;
		} return $this->required_utc( $value );
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
	 * Insert id.
	 *
	 * @return int
	 */
	private function insert_id(): int {
		return $this->positive_integer( $this->database()->insert_id ?? null );
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
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
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
		} return $rows;
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
		foreach ( array( 'prepare', 'get_results', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
