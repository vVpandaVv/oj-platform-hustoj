<?php
/**
 * C49 submission PDF enqueue application service.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C49 submission PDF enqueue application service.
 */
final class Enqueue_Submission_Pdf {
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
	 * Initialize enqueue submission pdf dependencies and state.
	 *
	 * @param Build_Submission_Print_Projection          $projections Projections.
	 * @param Submission_Pdf_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Submission_Pdf_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                        $ids Ids.
	 * @param Canonical_Json                             $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                              $clock Clock.
	 * @param callable|null                              $timezone Timezone.
	 */
	public function __construct(
		private Build_Submission_Print_Projection $projections,
		private Submission_Pdf_Repository_Port_Interface $repository,
		private Submission_Pdf_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $timezone = null
	) {
		$this->clock    = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
		$this->timezone = null === $timezone
			? static fn (): string => function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC'
			: Closure::fromCallable( $timezone );
	}

	/**
	 * Execute enqueue submission pdf.
	 *
	 * @param Enqueue_Submission_Pdf_Command $command Command.
	 * @return Enqueue_Submission_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Submission_Pdf_Command $command ): Enqueue_Submission_Pdf_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Submission_Pdf_Result ) {
			return $receipt;
		}
		try {
			$projection = $this->projections->build(
				$command->revision_id(),
				$command->expected_revision_hash(),
				$command->expected_publication_hash(),
				$command->expected_attachment_set_hash()
			);
		} catch ( Submission_Pdf_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_INVALID );
		}

		$this->assert_expected_projection( $projection, $command );
		try {
			$snapshot = Submission_Pdf_Request_Snapshot::freeze(
				$projection,
				(string) ( $this->timezone )(),
				$this->canonical
			);
		} catch ( InvalidArgumentException $error ) {
			$reason = str_contains( $error->getMessage(), 'too large' )
				? Submission_Pdf_Failure::TOO_LARGE
				: Submission_Pdf_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( $reason );
		}

		$queued_at = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
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
	 * Reject invalid expected projection.
	 *
	 * @param array<string,mixed>            $projection Projection.
	 * @param Enqueue_Submission_Pdf_Command $command Command.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected_projection( array $projection, Enqueue_Submission_Pdf_Command $command ): void {
		$source           = $projection['source'] ?? null;
		$context          = $projection['context'] ?? null;
		$submission       = $projection['submission'] ?? null;
		$publication      = $projection['publication'] ?? null;
		$print            = $projection['print'] ?? null;
		$print_projection = is_array( $print ) ? ( $print['projection'] ?? null ) : null;
		$envelope         = is_array( $print_projection ) ? ( $print_projection['submission_print_projection'] ?? null ) : null;
		$payload          = is_array( $envelope ) ? ( $envelope['payload'] ?? null ) : null;
		$provenance       = is_array( $payload ) ? ( $payload['provenance'] ?? null ) : null;
		if (
			! is_array( $source ) || ! is_array( $context ) || ! is_array( $submission ) || ! is_array( $publication ) || ! is_array( $provenance )
			|| ! hash_equals( $command->revision_id(), (string) ( $source['id'] ?? '' ) )
			|| ! hash_equals( $command->expected_revision_hash(), (string) ( $source['hash'] ?? '' ) )
			|| ! hash_equals( $command->expected_submission_id(), (string) ( $submission['id'] ?? '' ) )
			|| ! hash_equals( $command->expected_submission_state(), (string) ( $submission['state'] ?? '' ) )
			|| $command->expected_submission_lock_version() !== ( $submission['lock_version'] ?? null )
			|| ! hash_equals( $command->expected_context_type(), (string) ( $context['type'] ?? '' ) )
			|| ! hash_equals( $command->expected_context_id(), (string) ( $context['id'] ?? '' ) )
			|| ! hash_equals( $command->expected_context_revision(), (string) ( $context['revision'] ?? '' ) )
			|| ! hash_equals( $command->expected_publication_hash(), (string) ( $publication['hash'] ?? '' ) )
			|| ! hash_equals( $command->expected_attachment_set_hash(), (string) ( $provenance['attachment_set_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_STALE );
		}
	}
}
