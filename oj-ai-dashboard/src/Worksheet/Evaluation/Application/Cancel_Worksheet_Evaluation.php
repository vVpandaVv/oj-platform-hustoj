<?php
/**
 * Cooperatively cancel the latest queued or running worksheet evaluation run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Control_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cooperatively cancel the latest queued or running worksheet evaluation run.
 */
final class Cancel_Worksheet_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize worksheet evaluation dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Control_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                    $ids Ids.
	 * @param callable|null                                          $clock Clock.
	 */
	public function __construct(
		private Worksheet_Evaluation_Control_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute worksheet evaluation.
	 *
	 * @param Cancel_Worksheet_Evaluation_Command $command Command.
	 * @return Worksheet_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Cancel_Worksheet_Evaluation_Command $command ): Worksheet_Evaluation_Control_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest(),
			Worksheet_Evaluation_Control_Result::OPERATION_CANCEL
		);
		if ( $receipt instanceof Worksheet_Evaluation_Control_Result ) {
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		return $this->repository->cancel(
			array(
				'actor_user_id'                    => $command->actor_user_id(),
				'command_id'                       => $command->command_id(),
				'command_storage_key'              => $command->idempotency_storage_key(),
				'request_digest'                   => $command->request_digest(),
				'correlation_id'                   => $command->correlation_id(),
				'occurred_at_utc'                  => $this->now(),
				'evaluation_public_id'             => $command->evaluation_id(),
				'worksheet_revision_public_id'     => $snapshot['worksheet_revision_id'],
				'worksheet_revision_hash'          => $snapshot['source_hash'],
				'expected_evaluation_state'        => $command->expected_state(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_run_public_id'           => $command->expected_run_id(),
				'expected_run_no'                  => $command->expected_run_no(),
				'expected_run_state'               => $snapshot['latest_run_state'],
				'expected_run_lock_version'        => $snapshot['latest_run_lock_version'],
				'audit_public_id'                  => $this->ids->create()->value(),
				'outbox_public_id'                 => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Cancel_Worksheet_Evaluation_Command $command Command.
	 * @param array<string,mixed>                 $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Cancel_Worksheet_Evaluation_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_state',
			'worksheet_revision_id',
			'source_hash',
			'evaluation_lock_version',
			'latest_run_id',
			'latest_run_no',
			'latest_run_state',
			'latest_run_lock_version',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'owner' !== $command->actor_relation()
			|| ! hash_equals( $command->authorized_revision(), (string) $snapshot['worksheet_revision_id'] )
			|| ! hash_equals( $command->authorized_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->expected_state(), (string) $snapshot['evaluation_state'] )
			|| ! hash_equals( $command->expected_run_id(), (string) $snapshot['latest_run_id'] )
			|| $command->expected_run_no() !== (int) $snapshot['latest_run_no']
			|| ! in_array( (string) $snapshot['latest_run_state'], array( 'queued', 'retry_wait', 'leased', 'running' ), true )
		) {
			throw Worksheet_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Evaluation_Failure::STATE_CONFLICT,
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
			throw new InvalidArgumentException( 'CancelWorksheetEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
