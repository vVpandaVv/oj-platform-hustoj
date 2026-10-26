<?php
/**
 * Enqueue own submission pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Own_Submission_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the learner's exact current official submission through the shared queue.
 */
final class Enqueue_Own_Submission_Pdf {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Timezone.
	 *
	 * @var Closure
	 */
	private Closure $timezone;

	/**
	 * Initialize enqueue own submission pdf dependencies and state.
	 *
	 * @param Own_Submission_Pdf_Source_Query_Port_Interface $sources Sources.
	 * @param Build_Submission_Print_Projection              $projections Projections.
	 * @param Submission_Pdf_Repository_Port_Interface       $repository Persistence boundary used by this operation.
	 * @param Submission_Pdf_Queue_Wakeup_Port_Interface     $queue Queue.
	 * @param Public_Id_Generator                            $ids Ids.
	 * @param Canonical_Json                                 $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                  $clock Clock.
	 * @param callable|null                                  $timezone Timezone.
	 */
	public function __construct(
		private Own_Submission_Pdf_Source_Query_Port_Interface $sources,
		private Build_Submission_Print_Projection $projections,
		private Submission_Pdf_Repository_Port_Interface $repository,
		private Submission_Pdf_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $timezone = null
	) {
		$this->clock    = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->timezone = null === $timezone
			? static fn (): string => function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC'
			: Closure::fromCallable( $timezone );
	}

	/**
	 * Execute enqueue own submission pdf.
	 *
	 * @param Enqueue_Own_Submission_Pdf_Command $command Command.
	 * @return Enqueue_Submission_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Own_Submission_Pdf_Command $command ): Enqueue_Submission_Pdf_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Submission_Pdf_Result ) {
			return $receipt;
		}

		$queued_at = $this->now();
		try {
			$source = $this->sources->find_current_official( $command->actor_user_id(), $command->submission_id() );
		} catch ( Submission_Pdf_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::NOT_FOUND );
		}
		$this->assert_source( $source, $command );

		try {
			$projection = $this->projections->build(
				$command->expected_official_revision_id(),
				$command->expected_official_revision_hash(),
				$command->expected_publication_hash(),
				$command->expected_attachment_set_hash()
			);
			$snapshot   = Submission_Pdf_Request_Snapshot::freeze_own(
				$projection,
				$source,
				(string) ( $this->timezone )(),
				$this->canonical
			);
		} catch ( Submission_Pdf_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			$reason = str_contains( $error->getMessage(), 'too large' )
				? Submission_Pdf_Failure::TOO_LARGE
				: Submission_Pdf_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( $reason );
		}

		$context     = $snapshot->context();
		$submission  = $snapshot->submission();
		$publication = $snapshot->publication();
		$semantic    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id'    => $command->actor_user_id(),
					'artifact_kind'    => 'submission_pdf',
					'assignment_id'    => $command->expected_assignment_id(),
					'context'          => $context,
					'publication_hash' => $publication['hash'],
					'request_hash'     => $snapshot->hash(),
					'source_hash'      => $snapshot->source_hash(),
					'source_id'        => $snapshot->source_id(),
					'submission'       => $submission,
				)
			)
		);
		$record      = array(
			'actor_user_id'           => $command->actor_user_id(),
			'command_id'              => $command->command_id(),
			'command_storage_key'     => $command->idempotency_storage_key(),
			'request_digest'          => $command->request_digest(),
			'correlation_id'          => $command->correlation_id(),
			'job_public_id'           => $this->ids->create()->value(),
			'audit_public_id'         => $this->ids->create()->value(),
			'outbox_public_id'        => $this->ids->create()->value(),
			'job_idempotency_key'     => 'submission-pdf-job:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'semantic_hash'           => $semantic,
			'context_type'            => $context['type'],
			'context_id'              => $context['id'],
			'context_revision'        => $context['revision'],
			'submission_public_id'    => $submission['id'],
			'submission_state'        => $submission['state'],
			'submission_lock_version' => $submission['lock_version'],
			'source_public_id'        => $snapshot->source_id(),
			'source_hash'             => $snapshot->source_hash(),
			'publication_hash'        => $publication['hash'],
			'attachment_set_hash'     => $snapshot->attachment_set_hash(),
			'snapshot_json'           => $snapshot->json(),
			'snapshot_hash'           => $snapshot->hash(),
			'queued_at_utc'           => $queued_at,
			'print_profile_version'   => $snapshot->profile_version(),
		);
		$result      = $this->repository->enqueue( $record );
		$this->queue->wake();
		return $result;
	}

	/**
	 * Reject invalid source.
	 *
	 * @param array<string,mixed>                $source Source.
	 * @param Enqueue_Own_Submission_Pdf_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source( array $source, Enqueue_Own_Submission_Pdf_Command $command ): void {
		$expected_keys = array(
			'assignment_id',
			'assignment_state',
			'attachment_set_hash',
			'context_id',
			'context_revision',
			'context_type',
			'official_revision_hash',
			'official_revision_id',
			'publication_hash',
			'publication_id',
			'submission_id',
			'submission_lock_version',
			'submission_state',
			'target_id',
			'target_state',
		);
		$actual_keys   = array_keys( $source );
		sort( $actual_keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );
		if (
			$actual_keys !== $expected_keys
			|| ! hash_equals( $command->submission_id(), (string) ( $source['submission_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_assignment_id(), (string) ( $source['assignment_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_assignment_state(), (string) ( $source['assignment_state'] ?? '' ) )
			|| ! hash_equals( $command->expected_official_revision_id(), (string) ( $source['official_revision_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_official_revision_hash(), (string) ( $source['official_revision_hash'] ?? '' ) )
			|| ! hash_equals( $command->expected_attachment_set_hash(), (string) ( $source['attachment_set_hash'] ?? '' ) )
			|| ! hash_equals( $command->expected_publication_id(), (string) ( $source['publication_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_publication_hash(), (string) ( $source['publication_hash'] ?? '' ) )
			|| $command->expected_submission_lock_version() !== ( $source['submission_lock_version'] ?? null )
			|| ! hash_equals( $command->expected_submission_state(), (string) ( $source['submission_state'] ?? '' ) )
			|| ! hash_equals( $command->context_type(), (string) ( $source['context_type'] ?? '' ) )
			|| ! hash_equals( $command->context_id(), (string) ( $source['context_id'] ?? '' ) )
			|| ! hash_equals( $command->expected_context_revision(), (string) ( $source['context_revision'] ?? '' ) )
			|| ! hash_equals( $command->expected_target_state(), (string) ( $source['target_state'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_STALE );
		}
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
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
