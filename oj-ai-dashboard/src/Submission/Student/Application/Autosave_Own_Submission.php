<?php
/**
 * AutosaveOwnSubmission use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rechecks current learner participation before appending one immutable revision.
 */
final class Autosave_Own_Submission {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize autosave own submission dependencies and state.
	 *
	 * @param Student_Assignment_Repository_Port_Interface $submissions Submissions.
	 * @param Learning_Context_Query_Port_Interface        $contexts Contexts.
	 * @param Public_Id_Generator                          $ids Ids.
	 * @param callable|null                                $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Repository_Port_Interface $submissions,
		private Learning_Context_Query_Port_Interface $contexts,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute autosave own submission.
	 *
	 * @param Autosave_Own_Submission_Command $command Command.
	 * @return Autosave_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Autosave_Own_Submission_Command $command ): Autosave_Own_Submission_Result {
		$snapshot = $this->submissions->submission_authorization_snapshot( $command->submission_id(), $command->actor_user_id() );
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
		$receipt = $this->submissions->find_autosave_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Autosave_Own_Submission_Result ) {
			return $receipt;
		}
		if ( 'open' !== $snapshot['assignment_state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if ( ! in_array( $snapshot['state'], array( 'draft', 'conflict', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}

		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission clock is invalid.' );
		}
		$revision_id = $this->ids->create()->value();
		$audit_id    = $this->ids->create()->value();
		$outbox_id   = $this->ids->create()->value();
		if ( 3 !== count( array_unique( array( $revision_id, $audit_id, $outbox_id ) ) ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission generated duplicate public IDs.' );
		}

		return $this->submissions->autosave(
			array(
				'actor_user_id'                => $command->actor_user_id(),
				'submission_public_id'         => $command->submission_id(),
				'revision_public_id'           => $revision_id,
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
				'answers'                      => $command->answers(),
				'staged_attachment_ids'        => $command->staged_attachment_ids(),
				'authorized_context_revision'  => (string) $context['revision'],
			)
		);
	}
}
