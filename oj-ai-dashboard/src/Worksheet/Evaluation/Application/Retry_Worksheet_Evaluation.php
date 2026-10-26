<?php
/**
 * Create a new run for one failed or cancelled worksheet evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create a new run for one failed or cancelled worksheet evaluation.
 */
final class Retry_Worksheet_Evaluation {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize retry worksheet evaluation dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Control_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Worksheet_Evaluation_Configuration_Port_Interface      $configuration Configuration.
	 * @param Worksheet_Evaluation_Routing_Port_Interface            $routing Routing.
	 * @param Worksheet_Evaluation_Queue_Wakeup_Port_Interface       $queue Queue.
	 * @param Public_Id_Generator                                    $ids Ids.
	 * @param Canonical_Json                                         $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                          $clock Clock.
	 */
	public function __construct(
		private Worksheet_Evaluation_Control_Repository_Port_Interface $repository,
		private Worksheet_Evaluation_Configuration_Port_Interface $configuration,
		private Worksheet_Evaluation_Routing_Port_Interface $routing,
		private Worksheet_Evaluation_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute retry worksheet evaluation.
	 *
	 * @param Retry_Worksheet_Evaluation_Command $command Command.
	 * @return Worksheet_Evaluation_Control_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Retry_Worksheet_Evaluation_Command $command ): Worksheet_Evaluation_Control_Result {
		$receipt = $this->repository->find_command_receipt(
			$command->idempotency_storage_key(),
			$command->request_digest(),
			Worksheet_Evaluation_Control_Result::OPERATION_RETRY
		);
		if ( $receipt instanceof Worksheet_Evaluation_Control_Result ) {
			$this->queue->wake();
			return $receipt;
		}

		$snapshot = $this->repository->find_owned_snapshot( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $snapshot ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_expected( $command, $snapshot );
		if ( ! hash_equals( (string) $snapshot['worksheet_revision_id'], (string) $snapshot['current_revision_id'] ) ) {
			throw Worksheet_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Evaluation_Failure::SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				array( 'current_revision_id' => (string) $snapshot['current_revision_id'] )
			);
		}

		$configuration = $this->configuration instanceof \OJAI\Dashboard\Worksheet\Evaluation\Port\Scoped_Evaluation_Configuration_Port_Interface ? $this->configuration->execution() : $this->configuration->active();
		// The grading rubric is already frozen in the original input; only execution policy can invalidate a retry.
		$configuration      = array_intersect_key( $configuration, array_flip( array( 'policy', 'rubric', 'review_rule', 'prompt', 'input_schema', 'output_schema' ) ) );
		$configuration_hash = hash( 'sha256', $this->canonical->encode( $configuration ) );
		if ( ! hash_equals( (string) $snapshot['frozen_configuration_hash'], $configuration_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::CONFIGURATION_CHANGED );
		}

		$route  = $this->routing->plan();
		$now    = $this->now();
		$run_no = (int) $snapshot['latest_run_no'] + 1;
		if ( $run_no < 2 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$run_identity = array(
			'evaluation_identity_digest' => $snapshot['evaluation_identity_digest'],
			'run_no'                     => $run_no,
			'task_key'                   => Addon_Manifest::TASK_WORKSHEET_QUALITY,
			'contract_version'           => Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT,
			'operation'                  => Worksheet_Evaluation_Input_Builder::OPERATION,
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
				'correlation_id'                   => $command->correlation_id(),
				'occurred_at_utc'                  => $now,
				'evaluation_public_id'             => $command->evaluation_id(),
				'worksheet_revision_public_id'     => $snapshot['worksheet_revision_id'],
				'worksheet_revision_hash'          => $snapshot['source_hash'],
				'configuration'                    => $configuration,
				'configuration_hash'               => $configuration_hash,
				'input_hash'                       => $snapshot['input_hash'],
				'expected_evaluation_state'        => $command->expected_state(),
				'expected_evaluation_lock_version' => $snapshot['evaluation_lock_version'],
				'expected_run_public_id'           => $command->expected_run_id(),
				'expected_run_no'                  => $command->expected_run_no(),
				'expected_run_state'               => $snapshot['latest_run_state'],
				'expected_run_lock_version'        => $snapshot['latest_run_lock_version'],
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
	 * @param Retry_Worksheet_Evaluation_Command $command Command.
	 * @param array<string,mixed>                $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( Retry_Worksheet_Evaluation_Command $command, array $snapshot ): void {
		$required = array(
			'evaluation_state',
			'worksheet_revision_id',
			'current_revision_id',
			'source_hash',
			'frozen_configuration_hash',
			'evaluation_identity_digest',
			'input_hash',
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
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->state_conflict( $snapshot );
		}
		if ( ! in_array( (string) $snapshot['latest_run_state'], array( 'failed', 'cancelled' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->state_conflict( $snapshot );
		}
	}

	/**
	 * State conflict.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return Worksheet_Evaluation_Failure
	 */
	private function state_conflict( array $snapshot ): Worksheet_Evaluation_Failure {
		return Worksheet_Evaluation_Failure::because(
			Worksheet_Evaluation_Failure::STATE_CONFLICT,
			array(
				'current_state'  => (string) ( $snapshot['evaluation_state'] ?? '' ),
				'current_run_id' => (string) ( $snapshot['latest_run_id'] ?? '' ),
				'current_run_no' => (string) ( $snapshot['latest_run_no'] ?? '' ),
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
			throw new InvalidArgumentException( 'RetryWorksheetEvaluation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
