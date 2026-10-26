<?php
/**
 * CreateAssignment use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pins publication, Context, exact roster, and display evidence atomically.
 */
final class Create_Assignment {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize assignment dependencies and state.
	 *
	 * @param Assignment_Repository_Port_Interface    $assignments Assignments.
	 * @param Assignment_Roster_Port_Interface        $rosters Rosters.
	 * @param Learner_Display_Snapshot_Port_Interface $displays Displays.
	 * @param Public_Id_Generator                     $ids Ids.
	 * @param Canonical_Json                          $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                           $clock Clock.
	 */
	public function __construct(
		private Assignment_Repository_Port_Interface $assignments,
		private Assignment_Roster_Port_Interface $rosters,
		private Learner_Display_Snapshot_Port_Interface $displays,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute assignment.
	 *
	 * @param Create_Assignment_Command $command Command.
	 * @return Create_Assignment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Create_Assignment_Command $command ): Create_Assignment_Result {
		$receipt = $this->assignments->find_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Create_Assignment_Result ) {
			return $receipt;
		}

		$roster = $this->rosters->collect(
			$command->actor_user_id(),
			$command->context_type(),
			$command->context_id(),
			$command->context_revision()
		);
		if ( 0 === $roster->count() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_EMPTY );
		}

		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'CreateAssignment clock is invalid.' );
		}
		$targets = array();
		$issued  = array();
		foreach ( $roster->learners() as $learner ) {
			$target    = ( new Assignment_Target_Factory( $this->displays, $this->ids, $this->canonical ) )->create(
				$learner,
				array(
					'context_type'     => $command->context_type(),
					'context_id'       => $command->context_id(),
					'context_revision' => $command->context_revision(),
				)
			);
			$issued[]  = $target['public_id'];
			$targets[] = $target;
		}

		$assignment_id = $this->ids->create()->value();
		$audit_id      = $this->ids->create()->value();
		$outbox_id     = $this->ids->create()->value();
		$issued        = array_merge( $issued, array( $assignment_id, $audit_id, $outbox_id ) );
		if ( count( $issued ) !== count( array_unique( $issued ) ) ) {
			throw new InvalidArgumentException( 'CreateAssignment generated duplicate public IDs.' );
		}

		$schedule             = $command->schedule();
		$schedule_value       = array(
			'auto_submit_enabled' => $schedule['auto_submit_enabled'],
			'closes_at_utc'       => $schedule['closes_at_utc'],
			'due_at_utc'          => $schedule['due_at_utc'],
			'opens_at_utc'        => $schedule['opens_at_utc'],
			'schedule_version'    => 1,
		);
		$resubmission_json    = $this->canonical->encode( $command->resubmission_policy() );
		$target_snapshot_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'context'         => array(
						'id'       => $command->context_id(),
						'revision' => $command->context_revision(),
						'type'     => $command->context_type(),
					),
					'roster_revision' => $roster->roster_revision(),
					'target_hashes'   => array_column( $targets, 'snapshot_hash' ),
				)
			)
		);

		return $this->assignments->create(
			array(
				'actor_user_id'                   => $command->actor_user_id(),
				'assignment_public_id'            => $assignment_id,
				'audit_public_id'                 => $audit_id,
				'outbox_public_id'                => $outbox_id,
				'command_id'                      => $command->command_id(),
				'correlation_id'                  => $command->correlation_id(),
				'idempotency_storage_key'         => $command->idempotency_storage_key(),
				'request_digest'                  => $command->request_digest(),
				'occurred_at_utc'                 => $occurred_at,
				'worksheet_publication_public_id' => $command->publication_id(),
				'publication_hash'                => $command->publication_hash(),
				'context_type'                    => $command->context_type(),
				'context_id'                      => $command->context_id(),
				'context_revision'                => $command->context_revision(),
				'target_roster_revision'          => $roster->roster_revision(),
				'target_snapshot_hash'            => $target_snapshot_hash,
				'state'                           => $command->initial_state(),
				'schedule'                        => $schedule,
				'schedule_hash'                   => hash( 'sha256', $this->canonical->encode( $schedule_value ) ),
				'resubmission_policy_schema'      => 'oj_ai.assignment_resubmission_policy',
				'resubmission_policy_version'     => 1,
				'resubmission_policy_json'        => $resubmission_json,
				'resubmission_policy_hash'        => hash( 'sha256', $resubmission_json ),
				'targets'                         => $targets,
			)
		);
	}
}
