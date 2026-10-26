<?php
/**
 * Cooperatively cancel the latest queued or running submission evaluation run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cooperatively cancel the latest queued or running submission evaluation run.
 */
final class Cancel_Submission_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Control_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                     $ids Ids.
	 * @param callable|null                                           $clock Clock.
	 */
	public function __construct(
		private Submission_Evaluation_Control_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute submission evaluation.
	 *
	 * @param Cancel_Submission_Evaluation_Command $command Command.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Cancel_Submission_Evaluation_Command $command ): Submission_Evaluation_Control_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest(),
			Submission_Evaluation_Control_Result::OPERATION_CANCEL
		);
		if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
			return $receipt;
		}
		$snapshot = $this->repository->find_snapshot( $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );

		return $this->repository->cancel(
			array(
				'actor_user_id'                    => $command->actor_user_id(),
				'command_id'                       => $command->command_id(),
				'command_storage_key'              => $command->idempotency_storage_key(),
				'request_digest'                   => $command->request_digest(),
				'reason'                           => $command->reason(),
				'correlation_id'                   => $command->correlation_id(),
				'occurred_at_utc'                  => $this->now(),
				'context_type'                     => $command->context_type(),
				'context_id'                       => $command->context_id(),
				'context_revision'                 => $command->context_revision(),
				'evaluation_public_id'             => $command->evaluation_id(),
				'submission_public_id'             => $snapshot['submission_id'],
				'submission_revision_public_id'    => $snapshot['submission_revision_id'],
				'submission_revision_hash'         => $snapshot['source_hash'],
				'worksheet_publication_public_id'  => $snapshot['publication_id'],
				'publication_hash'                 => $snapshot['publication_hash'],
				'expected_evaluation_state'        => $command->expected_state(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_run_public_id'           => $command->expected_run_id(),
				'expected_run_no'                  => $command->expected_run_no(),
				'expected_run_state'               => $snapshot['latest_run_state'],
				'expected_run_lock_version'        => $snapshot['latest_run_lock_version'],
				'expected_latest_result_id'        => $snapshot['latest_result_id'],
				'expected_result_count'            => $snapshot['result_count'],
				'audit_public_id'                  => $this->ids->create()->value(),
				'outbox_public_id'                 => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Cancel_Submission_Evaluation_Command $command Command.
	 * @param array<string,mixed>                  $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Cancel_Submission_Evaluation_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_state',
			'evaluation_lock_version',
			'submission_id',
			'submission_revision_id',
			'source_hash',
			'publication_id',
			'publication_hash',
			'context_type',
			'context_id',
			'context_revision',
			'latest_run_id',
			'latest_run_no',
			'latest_run_state',
			'latest_run_lock_version',
			'latest_result_id',
			'result_count',
			'source_is_current',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'context_staff' !== $command->actor_relation()
			|| $command->authorized_revision() !== (string) $snapshot['evaluation_lock_version']
			|| $command->authorized_state() !== $snapshot['evaluation_state']
			|| $command->expected_state() !== $snapshot['evaluation_state']
			|| $command->expected_run_id() !== $snapshot['latest_run_id']
			|| $command->expected_run_no() !== (int) $snapshot['latest_run_no']
			|| $command->context_type() !== $snapshot['context_type']
			|| $command->context_id() !== $snapshot['context_id']
			|| $command->context_revision() !== $snapshot['context_revision']
			|| true !== $snapshot['source_is_current']
			|| ! in_array( $snapshot['latest_run_state'], array( 'queued', 'retry_wait', 'leased', 'running' ), true )
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::STATE_CONFLICT,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_state'  => (string) ( $snapshot['evaluation_state'] ?? '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_run_id' => (string) ( $snapshot['latest_run_id'] ?? '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_run_no' => (string) ( $snapshot['latest_run_no'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'CancelSubmissionEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
