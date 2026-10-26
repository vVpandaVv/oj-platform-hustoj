<?php
/**
 * Enqueue submission bundle.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues C50 only from two identical reads of the current official submissions.
 */
final class Enqueue_Submission_Bundle {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize enqueue submission bundle dependencies and state.
	 *
	 * @param Submission_Bundle_Source_Port_Interface       $source Source.
	 * @param Submission_Bundle_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Submission_Bundle_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                 $clock Clock.
	 */
	public function __construct(
		private Submission_Bundle_Source_Port_Interface $source,
		private Submission_Bundle_Repository_Port_Interface $repository,
		private Submission_Bundle_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue submission bundle.
	 *
	 * @param Enqueue_Submission_Bundle_Command $command Command.
	 * @return Enqueue_Submission_Bundle_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Submission_Bundle_Command $command ): Enqueue_Submission_Bundle_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Submission_Bundle_Result ) {
			return $receipt;
		}

		try {
			$first = $this->source->read(
				$command->assignment_id(),
				$command->context_type(),
				$command->context_id(),
				$command->context_revision(),
				$command->include_allowed_originals()
			);
			if ( null === $first ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::NOT_FOUND );
			}
			$second = $this->source->read(
				$command->assignment_id(),
				$command->context_type(),
				$command->context_id(),
				$command->context_revision(),
				$command->include_allowed_originals()
			);
			if (
				null === $second
				|| ! is_string( $first['source_hash'] ?? null )
				|| ! is_string( $second['source_hash'] ?? null )
				|| ! hash_equals( $first['source_hash'], $second['source_hash'] )
			) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
			}

			$assignment = $second['assignment'] ?? null;
			if (
				! is_array( $assignment ) || array_is_list( $assignment )
				|| $command->assignment_id() !== ( $assignment['id'] ?? null )
				|| $command->expected_assignment_hash() !== ( $assignment['hash'] ?? null )
				|| $command->expected_schedule_version() !== ( $assignment['schedule_version'] ?? null )
				|| $command->expected_assignment_state() !== ( $assignment['state'] ?? null )
			) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
			}

			$snapshot = Submission_Bundle_Request_Snapshot::freeze(
				$second,
				$command->context(),
				$command->locale(),
				$command->include_allowed_originals(),
				$this->canonical
			);
		} catch ( Submission_Bundle_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			$reason = str_contains( strtolower( $error->getMessage() ), 'too large' )
				? Submission_Bundle_Failure::TOO_LARGE
				: Submission_Bundle_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( $reason );
		}

		$queued_at = $this->now();
		$context   = $snapshot->context();
		$semantic  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id' => $command->actor_user_id(),
					'artifact_kind' => Submission_Bundle_Request_Snapshot::ARTIFACT_KIND,
					'context'       => $context,
					'profile'       => $snapshot->profile(),
					'request_hash'  => $snapshot->hash(),
					'source_hash'   => $snapshot->source_hash(),
					'source_id'     => $snapshot->source_id(),
				)
			)
		);
		$record    = array(
			'actor_user_id'       => $command->actor_user_id(),
			'artifact_kind'       => Submission_Bundle_Request_Snapshot::ARTIFACT_KIND,
			'audit_public_id'     => $this->ids->create()->value(),
			'command_id'          => $command->command_id(),
			'command_storage_key' => $command->idempotency_storage_key(),
			'context_id'          => $context['id'],
			'context_revision'    => $context['revision'],
			'context_type'        => $context['type'],
			'correlation_id'      => $command->correlation_id(),
			'job_idempotency_key' => 'submission-bundle-job:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'job_public_id'       => $this->ids->create()->value(),
			'outbox_public_id'    => $this->ids->create()->value(),
			'profile_version'     => $snapshot->profile(),
			'queued_at_utc'       => $queued_at,
			'request_digest'      => $command->request_digest(),
			'semantic_hash'       => $semantic,
			'snapshot_hash'       => $snapshot->hash(),
			'snapshot_json'       => $snapshot->json(),
			'source_hash'         => $snapshot->source_hash(),
			'source_public_id'    => $snapshot->source_id(),
		);
		$result    = $this->repository->enqueue( $record );
		if ( ! $result->replayed() ) {
			$this->queue->wake();
		}
		return $result;
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
