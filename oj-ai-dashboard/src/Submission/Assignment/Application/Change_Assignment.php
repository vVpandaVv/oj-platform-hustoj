<?php
/**
 * Assignment schedule and lifecycle use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Lifecycle_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Issues IDs and delegates one atomic C14-C18 mutation.
 */
final class Change_Assignment {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize change assignment dependencies and state.
	 *
	 * @param Assignment_Lifecycle_Repository_Port_Interface $assignments Assignments.
	 * @param Public_Id_Generator                            $ids Ids.
	 * @param callable|null                                  $clock Clock.
	 */
	public function __construct(
		private Assignment_Lifecycle_Repository_Port_Interface $assignments,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute change assignment.
	 *
	 * @param Change_Assignment_Command $command Command.
	 * @return Change_Assignment_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Change_Assignment_Command $command ): Change_Assignment_Result {
		$receipt = $this->assignments->find_receipt( $command->idempotency_storage_key(), $command->request_digest(), $command->action() );
		if ( $receipt instanceof Change_Assignment_Result ) {
			return $receipt;
		}
		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'Assignment lifecycle clock is invalid.' );
		}
		$audit_id              = $this->ids->create()->value();
		$outbox_id             = $this->ids->create()->value();
		$auto_submit_outbox_id = $this->ids->create()->value();
		if ( 3 !== count( array_unique( array( $audit_id, $outbox_id, $auto_submit_outbox_id ) ) ) ) {
			throw new InvalidArgumentException( 'Assignment lifecycle generated duplicate public IDs.' );
		}
		return $this->assignments->change(
			array(
				'actor_user_id'                => $command->actor_user_id(),
				'assignment_public_id'         => $command->assignment_id(),
				'action'                       => $command->action(),
				'audit_public_id'              => $audit_id,
				'outbox_public_id'             => $outbox_id,
				'auto_submit_outbox_public_id' => $auto_submit_outbox_id,
				'command_id'                   => $command->command_id(),
				'correlation_id'               => $command->correlation_id(),
				'idempotency_storage_key'      => $command->idempotency_storage_key(),
				'request_digest'               => $command->request_digest(),
				'occurred_at_utc'              => $occurred_at,
				'expected_state'               => $command->expected_state(),
				'expected_lock_version'        => $command->expected_lock_version(),
				'expected_schedule_version'    => $command->expected_schedule_version(),
				'expected_schedule_hash'       => $command->expected_schedule_hash(),
				'schedule'                     => $command->schedule(),
			)
		);
	}
}
