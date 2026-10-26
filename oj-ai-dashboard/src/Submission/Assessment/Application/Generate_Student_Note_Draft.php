<?php
/**
 * Enqueue one special-note draft from the exact current official review.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue one special-note draft from the exact current official review.
 */
final class Generate_Student_Note_Draft {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize generate student note draft dependencies and state.
	 *
	 * @param Student_Note_Repository_Port_Interface            $repository Persistence boundary used by this operation.
	 * @param Submission_Evaluation_Routing_Port_Interface      $routing Routing.
	 * @param Submission_Evaluation_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Student_Note_Input_Builder                        $inputs Inputs.
	 * @param Public_Id_Generator                               $ids Ids.
	 * @param Canonical_Json                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                     $clock Clock.
	 */
	public function __construct(
		private Student_Note_Repository_Port_Interface $repository,
		private Submission_Evaluation_Routing_Port_Interface $routing,
		private Submission_Evaluation_Queue_Wakeup_Port_Interface $queue,
		private Student_Note_Input_Builder $inputs,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) { $this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute generate student note draft.
	 *
	 * @param Generate_Student_Note_Draft_Command $command Command.
	 * @return Generate_Student_Note_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Generate_Student_Note_Draft_Command $command ): Generate_Student_Note_Draft_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Generate_Student_Note_Draft_Result ) {
			$this->queue->wake();
			return $receipt;
		}
		$source = $this->repository->find_owned_source( $command->actor_user_id(), $command->evaluation_id() );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		$this->assert_source( $command, $source );
		try {
			$input = $this->inputs->build( $source ); } catch ( InvalidArgumentException $error ) {
			$reason = str_contains( strtolower( $error->getMessage() ), 'too large' ) ? Submission_Evaluation_Failure::INPUT_TOO_LARGE : Submission_Evaluation_Failure::INPUT_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( $reason );
			}
			$route = $this->routing->plan();
			if ( Addon_Manifest::TASK_STUDENT_ASSESSMENT !== $route->task_key() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_ROUTABLE );
			}
			$queued_at = ( $this->clock )();
			if ( ! is_string( $queued_at ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
				throw new InvalidArgumentException( 'GenerateStudentNoteDraft clock returned an invalid UTC value.' );
			}
			$identity = array(
				'evaluation_id'        => $command->evaluation_id(),
				'input_digest'         => $input->gateway_input_hash(),
				'official_review_hash' => $input->official_review_hash(),
				'official_review_id'   => $input->official_review_id(),
				'operation'            => Student_Note_Input_Builder::OPERATION,
				'profile_fingerprint'  => $route->profile_fingerprint(),
				'routing_revision'     => $route->routing_revision(),
				'task_key'             => $route->task_key(),
			);
			$result   = $this->repository->enqueue(
				array(
					'actor_user_id'            => $command->actor_user_id(),
					'audit_public_id'          => $this->ids->create()->value(),
					'command_id'               => $command->command_id(),
					'command_storage_key'      => $command->idempotency_storage_key(),
					'context_id'               => $source['context_id'],
					'context_revision'         => $source['context_revision'],
					'context_type'             => $source['context_type'],
					'correlation_id'           => $command->correlation_id(),
					'evaluation_lock_version'  => $source['evaluation_lock_version'],
					'evaluation_public_id'     => $command->evaluation_id(),
					'evaluation_state'         => $source['evaluation_state'],
					'input_digest'             => $input->gateway_input_hash(),
					'model_key'                => $route->model_key(),
					'official_review_hash'     => $input->official_review_hash(),
					'official_review_id'       => $input->official_review_id(),
					'outbox_public_id'         => $this->ids->create()->value(),
					'profile_fingerprint'      => $route->profile_fingerprint(),
					'provider_key'             => $route->provider_key(),
					'queued_at_utc'            => $queued_at,
					'request_digest'           => $command->request_digest(),
					'result_id'                => $input->result_id(),
					'routing_revision'         => $route->routing_revision(),
					'run_idempotency_key'      => 'run:' . hash( 'sha256', $this->canonical->encode( $identity ) ),
					'run_public_id'            => $this->ids->create()->value(),
					'submission_public_id'     => $source['submission_id'],
					'submission_revision_hash' => $input->submission_revision_hash(),
					'task_key'                 => $route->task_key(),
				)
			);
		$this->queue->wake();
		return $result;
	}

	/**
	 * Reject invalid source.
	 *
	 * @param Generate_Student_Note_Draft_Command $command Command.
	 * @param array<string,mixed>                 $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source( Generate_Student_Note_Draft_Command $command, array $source ): void {
		foreach ( array( 'evaluation_id', 'evaluation_lock_version', 'evaluation_state', 'context_type', 'context_id', 'context_revision', 'official_review_id', 'official_review_hash' ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
		}
		if (
			'context_staff' !== $command->actor_relation()
			|| ! hash_equals( $command->evaluation_id(), (string) $source['evaluation_id'] )
			|| ! hash_equals( $command->authorized_revision(), (string) $source['evaluation_lock_version'] )
			|| ! hash_equals( $command->authorized_state(), (string) $source['evaluation_state'] )
			|| 'reviewed' !== $source['evaluation_state']
			|| ! hash_equals( $command->context_type(), (string) $source['context_type'] )
			|| ! hash_equals( $command->context_id(), (string) $source['context_id'] )
			|| ! hash_equals( $command->context_revision(), (string) $source['context_revision'] )
			|| ! hash_equals( $command->expected_official_review_id(), (string) $source['official_review_id'] )
			|| ! hash_equals( $command->expected_official_review_hash(), (string) $source['official_review_hash'] )
		) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_review_id'   => (string) ( $source['official_review_id'] ?? '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_official_review_hash' => (string) ( $source['official_review_hash'] ?? '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					'current_state'                => (string) ( $source['evaluation_state'] ?? '' ),
				)
			);
		}
	}
}
