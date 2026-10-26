<?php
/**
 * SubmitOwnSubmission use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rechecks current learner participation before making one revision official.
 */
final class Submit_Own_Submission {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submit own submission dependencies and state.
	 *
	 * @param Student_Assignment_Access_Port_Interface            $access Access.
	 * @param Learning_Context_Query_Port_Interface               $contexts Contexts.
	 * @param Student_Submission_Commit_Repository_Port_Interface $commits Commits.
	 * @param Public_Id_Generator                                 $ids Ids.
	 * @param callable|null                                       $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Access_Port_Interface $access,
		private Learning_Context_Query_Port_Interface $contexts,
		private Student_Submission_Commit_Repository_Port_Interface $commits,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute submit own submission.
	 *
	 * @param Submit_Own_Submission_Command $command Command.
	 * @return Submit_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Submit_Own_Submission_Command $command ): Submit_Own_Submission_Result {
		$snapshot = $this->access->submission_authorization_snapshot( $command->submission_id(), $command->actor_user_id() );
		if ( null === $snapshot || 'active' !== $snapshot['target_state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		$contexts = $this->contexts->for_actor( $command->actor_user_id() );
		$context  = $contexts[ $snapshot['context_type'] . ':' . $snapshot['context_id'] ] ?? null;
		if ( ! is_array( $context ) || 'active' !== ( $context['lifecycle_state'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		$receipt = $this->commits->find_submit_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Submit_Own_Submission_Result ) {
			return $receipt;
		}
		if ( 'open' !== $snapshot['assignment_state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if ( ! in_array( $snapshot['state'], array( 'draft', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}

		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission clock is invalid.' );
		}
		$receipt_id = $this->ids->create()->value();
		$audit_id   = $this->ids->create()->value();
		$outbox_id  = $this->ids->create()->value();
		if ( 3 !== count( array_unique( array( $receipt_id, $audit_id, $outbox_id ) ) ) ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission generated duplicate public IDs.' );
		}

		return $this->commits->submit(
			array(
				'actor_user_id'                => $command->actor_user_id(),
				'submission_public_id'         => $command->submission_id(),
				'receipt_public_id'            => $receipt_id,
				'audit_public_id'              => $audit_id,
				'outbox_public_id'             => $outbox_id,
				'command_id'                   => $command->command_id(),
				'correlation_id'               => $command->correlation_id(),
				'idempotency_storage_key'      => $command->idempotency_storage_key(),
				'request_digest'               => $command->request_digest(),
				'occurred_at_utc'              => $occurred_at,
				'expected_state'               => $command->expected_state(),
				'expected_lock_version'        => $command->expected_lock_version(),
				'expected_current_revision_id' => $command->expected_current_revision_id(),
				'expected_schedule_version'    => $command->expected_schedule_version(),
				'expected_schedule_hash'       => $command->expected_schedule_hash(),
				'expected_publication_hash'    => $command->expected_publication_hash(),
				'expected_save_token'          => $command->expected_save_token(),
				'authorized_context_revision'  => (string) $context['revision'],
			)
		);
	}
}
