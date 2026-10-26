<?php
/**
 * Teacher-selected additions to an existing assignment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Target_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rechecks live membership, then appends only explicitly selected learners. */
final class Add_Assignment_Targets {
	/**
	 * Initialize dependencies.
	 *
	 * @param Assignment_Target_Repository_Port_Interface $assignments Storage.
	 * @param Assignment_Roster_Port_Interface            $rosters Platform roster.
	 * @param Learner_Display_Snapshot_Port_Interface     $displays Display snapshots.
	 * @param Public_Id_Generator                         $ids Public IDs.
	 * @param Canonical_Json                              $canonical Canonical encoder.
	 */
	public function __construct(
		private Assignment_Target_Repository_Port_Interface $assignments,
		private Assignment_Roster_Port_Interface $rosters,
		private Learner_Display_Snapshot_Port_Interface $displays,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Preview current members who have never been targets of this assignment.
	 *
	 * @param int                 $actor Authenticated teacher.
	 * @param string              $id Assignment ID.
	 * @param array<string,mixed> $context Authorized context query.
	 * @return array<string,mixed>
	 */
	public function preview( int $actor, string $id, array $context ): array {
		$snapshot = $this->snapshot( $id, $context );
		$roster   = $this->rosters->collect( $actor, $context['context_type'], $context['context_id'], $context['context_revision'] );
		$existing = array_column( $snapshot['targets'], 'learner_user_id' );
		$items    = array();
		foreach ( $roster->learners() as $learner ) {
			if ( ! in_array( $learner['learner_user_id'], $existing, true ) ) {
				$items[] = array(
					'learner_user_id' => $learner['learner_user_id'],
					'display_name'    => $this->displays->snapshot( $learner['learner_user_id'] )['display_name'],
				);
			}
		}
		return array(
			'items'    => $items,
			'expected' => array(
				'lock_version'         => (int) $snapshot['assignment']['lock_version'],
				'target_snapshot_hash' => $snapshot['assignment']['target_snapshot_hash'],
				'roster_revision'      => $roster->roster_revision(),
			),
		);
	}

	/**
	 * Validate the request independently of the platform/storage boundary.
	 *
	 * @param mixed $input JSON body.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException Invalid shape or selection.
	 */
	public static function input( mixed $input ): array {
		if ( ! is_array( $input ) || 2 !== count( $input ) || ! isset( $input['expected'], $input['learner_user_ids'] ) ) {
			throw new InvalidArgumentException( 'Invalid target addition.' );
		}
		$expected = $input['expected'];
		$learners = $input['learner_user_ids'];
		if ( ! is_array( $expected ) || 3 !== count( $expected )
			|| ! is_int( $expected['lock_version'] ?? null ) || $expected['lock_version'] < 0
			|| ! is_string( $expected['target_snapshot_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected['target_snapshot_hash'] )
			|| ! is_string( $expected['roster_revision'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{0,190}$/D', $expected['roster_revision'] )
			|| ! is_array( $learners ) || ! array_is_list( $learners ) || count( $learners ) < 1 || count( $learners ) > 5000 ) {
			throw new InvalidArgumentException( 'Invalid target addition values.' );
		}
		$seen = array();
		foreach ( $learners as $learner ) {
			if ( ! is_int( $learner ) || $learner < 1 || isset( $seen[ $learner ] ) ) {
				throw new InvalidArgumentException( 'Invalid selected learner.' );
			}
			$seen[ $learner ] = true;
		}
		sort( $learners, SORT_NUMERIC );
		$input['learner_user_ids'] = $learners;
		return $input;
	}

	/**
	 * Add confirmed learners using freshly collected membership evidence.
	 *
	 * @param int                 $actor Authenticated teacher.
	 * @param string              $id Assignment ID.
	 * @param array<string,mixed> $context Authorized context.
	 * @param array<string,mixed> $input Selection and preview version.
	 * @param string              $fingerprint Idempotency fingerprint.
	 * @param string              $correlation Request correlation.
	 * @return array<string,mixed>
	 * @throws Assignment_Failure Invalid state, membership or unavailable storage.
	 */
	public function execute( int $actor, string $id, array $context, array $input, string $fingerprint, string $correlation ): array {
		$input  = self::input( $input );
		$scope  = array(
			'actor'      => $actor,
			'assignment' => $id,
			'context'    => array_intersect_key( $context, array_flip( array( 'context_type', 'context_id', 'context_revision' ) ) ),
		);
		$key    = 'assignment:' . hash( 'sha256', 'add_targets:' . $this->canonical->encode( $scope ) . ':' . $fingerprint );
		$digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'scope' => $scope,
					'input' => $input,
				)
			)
		);
		$replay = $this->assignments->target_receipt( $key, $digest );
		if ( null !== $replay ) {
			return $replay;
		}
		$snapshot = $this->snapshot( $id, $context );
		$row      = $snapshot['assignment'];
		$roster   = $this->rosters->collect( $actor, $context['context_type'], $context['context_id'], $context['context_revision'] );
		if ( $input['expected']['lock_version'] !== (int) $row['lock_version']
			|| ! hash_equals( $input['expected']['target_snapshot_hash'], $row['target_snapshot_hash'] )
			|| ! hash_equals( $input['expected']['roster_revision'], $roster->roster_revision() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$members  = array_column( $roster->learners(), null, 'learner_user_id' );
		$existing = array_column( $snapshot['targets'], 'learner_user_id' );
		$targets  = array();
		foreach ( $input['learner_user_ids'] as $learner ) {
			if ( ! isset( $members[ $learner ] ) || in_array( $learner, $existing, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
			}
			$targets[] = ( new Assignment_Target_Factory( $this->displays, $this->ids, $this->canonical ) )->create( $members[ $learner ], $context );
		}
		if ( count( $existing ) + count( $targets ) > 5000 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
		}
		return $this->assignments->add_targets(
			array_merge(
				$context,
				array(
					'assignment_public_id'            => $id,
					'worksheet_publication_public_id' => $row['worksheet_publication_public_id'],
					'publication_hash'                => $row['publication_hash'],
					'expected'                        => $input['expected'],
					'targets'                         => $targets,
					'target_roster_revision'          => $roster->roster_revision(),
					'actor_user_id'                   => $actor,
					'occurred_at_utc'                 => gmdate( 'Y-m-d H:i:s' ),
					'correlation_id'                  => $correlation,
					'command_id'                      => $this->ids->create()->value(),
					'audit_public_id'                 => $this->ids->create()->value(),
					'outbox_public_id'                => $this->ids->create()->value(),
					'idempotency_storage_key'         => $key,
					'request_digest'                  => $digest,
				)
			)
		);
	}

	/**
	 * Enforce assignment/context identity and its remaining participation window.
	 *
	 * @param string              $id Assignment ID.
	 * @param array<string,mixed> $context Authorized context.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $id, array $context ): array {
		new Public_Id( $id );
		$snapshot = $this->assignments->target_snapshot( $id );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $field ) {
			if ( $snapshot['assignment'][ $field ] !== ( $context[ $field ] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
		}
		self::assert_open( $snapshot['assignment'], gmdate( 'Y-m-d H:i:s' ) );
		return $snapshot;
	}

	/**
	 * Reused inside the transaction to close lifecycle/schedule races.
	 *
	 * @param array<string,mixed> $row Persisted assignment.
	 * @param string              $now UTC time.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function assert_open( array $row, string $now ): void {
		if ( ! in_array( $row['state'], array( 'draft', 'scheduled', 'open' ), true )
			|| $row['due_at_utc'] <= $now || $row['closes_at_utc'] <= $now ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exceptions are mapped to escaped REST messages.
			throw Assignment_Failure::because( Assignment_Failure::TARGETS_CLOSED );
		}
	}
}
