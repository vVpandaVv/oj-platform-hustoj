<?php
/**
 * Append a new run for one terminal student submission evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append a new run for one terminal student submission evaluation.
 */
final class Retry_Submission_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize retry submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Control_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Submission_Evaluation_Routing_Port_Interface            $routing Routing.
	 * @param Submission_Evaluation_Queue_Wakeup_Port_Interface       $queue Queue.
	 * @param Public_Id_Generator                                     $ids Ids.
	 * @param Canonical_Json                                          $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                           $clock Clock.
	 */
	public function __construct(
		private Submission_Evaluation_Control_Repository_Port_Interface $repository,
		private Submission_Evaluation_Routing_Port_Interface $routing,
		private Submission_Evaluation_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute retry submission evaluation.
	 *
	 * @param Retry_Submission_Evaluation_Command $command Command.
	 * @return Submission_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Retry_Submission_Evaluation_Command $command ): Submission_Evaluation_Control_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest(),
			Submission_Evaluation_Control_Result::OPERATION_RETRY
		);
		if ( $receipt instanceof Submission_Evaluation_Control_Result ) {
			$this->queue->wake();
			return $receipt;
		}

		$snapshot = $this->repository->find_snapshot( $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );

		$route = $this->routing->plan();
		if ( Addon_Manifest::TASK_STUDENT_ASSESSMENT !== $route->task_key() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_ROUTABLE );
		}
		$now          = $this->now();
		$run_no       = (int) $snapshot['latest_run_no'] + 1;
		$run_identity = array(
			'evaluation_identity_digest' => $snapshot['evaluation_identity_digest'],
			'run_no'                     => $run_no,
			'task_key'                   => $route->task_key(),
			'contract_version'           => Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
			'operation'                  => Submission_Score_Input_Builder::OPERATION,
			'input_digest'               => $snapshot['input_hash'],
			'profile_fingerprint'        => $route->profile_fingerprint(),
			'routing_revision'           => $route->routing_revision(),
		);

		$result = $this->repository->retry(
			array(
				'actor_user_id'                    => $command->actor_user_id(),
				'command_id'                       => $command->command_id(),
				'command_storage_key'              => $command->idempotency_storage_key(),
				'request_digest'                   => $command->request_digest(),
				'reason'                           => $command->reason(),
				'correlation_id'                   => $command->correlation_id(),
				'occurred_at_utc'                  => $now,
				'context_type'                     => $command->context_type(),
				'context_id'                       => $command->context_id(),
				'context_revision'                 => $command->context_revision(),
				'evaluation_public_id'             => $command->evaluation_id(),
				'submission_public_id'             => $snapshot['submission_id'],
				'submission_revision_public_id'    => $snapshot['submission_revision_id'],
				'submission_revision_hash'         => $snapshot['source_hash'],
				'worksheet_publication_public_id'  => $snapshot['publication_id'],
				'publication_hash'                 => $snapshot['publication_hash'],
				'input_hash'                       => $snapshot['input_hash'],
				'expected_evaluation_state'        => $command->expected_state(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_run_public_id'           => $command->expected_run_id(),
				'expected_run_no'                  => $command->expected_run_no(),
				'expected_run_state'               => $snapshot['latest_run_state'],
				'expected_run_lock_version'        => $snapshot['latest_run_lock_version'],
				'expected_latest_result_id'        => $command->expected_latest_result_id(),
				'expected_result_count'            => $snapshot['result_count'],
				'run_public_id'                    => $this->ids->create()->value(),
				'run_no'                           => $run_no,
				'run_idempotency_key'              => 'run:' . hash( 'sha256', $this->canonical->encode( $run_identity ) ),
				'audit_public_id'                  => $this->ids->create()->value(),
				'outbox_public_id'                 => $this->ids->create()->value(),
				'task_key'                         => $route->task_key(),
				'provider_key'                     => $route->provider_key(),
				'model_key'                        => $route->model_key(),
				'profile_fingerprint'              => $route->profile_fingerprint(),
				'routing_revision'                 => $route->routing_revision(),
			)
		);
		$this->queue->wake();
		return $result;
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param Retry_Submission_Evaluation_Command $command Command.
	 * @param array<string,mixed>                 $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Retry_Submission_Evaluation_Command $command, array $snapshot ): void {
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
			'input_hash',
			'evaluation_identity_digest',
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
		$state         = (string) $snapshot['evaluation_state'];
		$run_state     = (string) $snapshot['latest_run_state'];
		$terminal_pair =
			( in_array( $state, array( 'review_required', 'reviewed' ), true ) && 'succeeded' === $run_state )
			|| ( 'failed' === $state && 'failed' === $run_state )
			|| ( 'cancelled' === $state && 'cancelled' === $run_state );
		if (
			'context_staff' !== $command->actor_relation()
			|| $command->authorized_revision() !== (string) $snapshot['evaluation_lock_version']
			|| $command->authorized_state() !== $state
			|| $command->expected_state() !== $state
			|| $command->expected_run_id() !== $snapshot['latest_run_id']
			|| $command->expected_run_no() !== (int) $snapshot['latest_run_no']
			|| $command->expected_latest_result_id() !== $snapshot['latest_result_id']
			|| $command->context_type() !== $snapshot['context_type']
			|| $command->context_id() !== $snapshot['context_id']
			|| $command->context_revision() !== $snapshot['context_revision']
			|| true !== $snapshot['source_is_current']
			|| ! $terminal_pair
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->state_conflict( $snapshot );
		}
	}

	/**
	 * State conflict.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return Submission_Evaluation_Failure
	 */
	private function state_conflict( array $snapshot ): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because(
			Submission_Evaluation_Failure::STATE_CONFLICT,
			array(
				'current_state'    => (string) ( $snapshot['evaluation_state'] ?? '' ),
				'current_run_id'   => (string) ( $snapshot['latest_run_id'] ?? '' ),
				'current_run_no'   => (string) ( $snapshot['latest_run_no'] ?? '' ),
				'latest_result_id' => (string) ( $snapshot['latest_result_id'] ?? '' ),
			)
		);
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
			throw new InvalidArgumentException( 'RetrySubmissionEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
