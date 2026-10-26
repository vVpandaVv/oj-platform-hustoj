<?php
/**
 * Teacher submission control use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues immutable evidence IDs and delegates one atomic C27 or C28 mutation.
 */
final class Control_Teacher_Submission {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize control teacher submission dependencies and state.
	 *
	 * @param Teacher_Submission_Control_Repository_Port_Interface $submissions Submissions.
	 * @param Public_Id_Generator                                  $ids Ids.
	 * @param callable|null                                        $clock Clock.
	 */
	public function __construct(
		private Teacher_Submission_Control_Repository_Port_Interface $submissions,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute control teacher submission.
	 *
	 * @param Control_Teacher_Submission_Command $command Command.
	 * @return Control_Teacher_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Control_Teacher_Submission_Command $command ): Control_Teacher_Submission_Result {
		$receipt = $this->submissions->find_receipt( $command->idempotency_storage_key(), $command->request_digest(), $command->action() );
		if ( $receipt instanceof Control_Teacher_Submission_Result ) {
			return $receipt;
		}
		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'Teacher submission control clock is invalid.' );
		}
		$revision_id = Control_Teacher_Submission_Command::REOPEN === $command->action() ? $this->ids->create()->value() : null;
		$receipt_id  = $this->ids->create()->value();
		$audit_id    = $this->ids->create()->value();
		$outbox_id   = $this->ids->create()->value();
		$ids         = array_filter( array( $revision_id, $receipt_id, $audit_id, $outbox_id ), static fn ( ?string $value ): bool => null !== $value );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new InvalidArgumentException( 'Teacher submission control generated duplicate public IDs.' );
		}

		return $this->submissions->control(
			array(
				'action'                        => $command->action(),
				'actor_user_id'                 => $command->actor_user_id(),
				'submission_public_id'          => $command->submission_id(),
				'revision_public_id'            => $revision_id,
				'receipt_public_id'             => $receipt_id,
				'audit_public_id'               => $audit_id,
				'outbox_public_id'              => $outbox_id,
				'command_id'                    => $command->command_id(),
				'correlation_id'                => $command->correlation_id(),
				'idempotency_storage_key'       => $command->idempotency_storage_key(),
				'request_digest'                => $command->request_digest(),
				'occurred_at_utc'               => $occurred_at,
				'expected_state'                => $command->expected_state(),
				'expected_lock_version'         => $command->expected_lock_version(),
				'expected_official_revision_id' => $command->expected_official_revision_id(),
				'expected_official_attempt_no'  => $command->expected_official_attempt_no(),
				'expected_schedule_version'     => $command->expected_schedule_version(),
				'expected_schedule_hash'        => $command->expected_schedule_hash(),
				'expected_publication_hash'     => $command->expected_publication_hash(),
				'reason'                        => $command->reason(),
				'authorized_context_type'       => $command->authorized_context_type(),
				'authorized_context_id'         => $command->authorized_context_id(),
				'authorized_context_revision'   => $command->authorized_context_revision(),
			)
		);
	}
}
