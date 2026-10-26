<?php
/**
 * Enqueue own result pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Result_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Result_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the learner's exact current public result.
 */
final class Enqueue_Own_Result_Pdf {
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
	 * Initialize enqueue own result pdf dependencies and state.
	 *
	 * @param Build_Result_Print_Projection          $projections Projections.
	 * @param Result_Pdf_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Result_Pdf_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                    $ids Ids.
	 * @param Canonical_Json                         $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                          $clock Clock.
	 * @param callable|null                          $timezone Timezone.
	 */
	public function __construct(
		private Build_Result_Print_Projection $projections,
		private Result_Pdf_Repository_Port_Interface $repository,
		private Result_Pdf_Queue_Wakeup_Port_Interface $queue,
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
	 * Execute enqueue own result pdf.
	 *
	 * @param Enqueue_Result_Pdf_Command $command Command.
	 * @return Enqueue_Result_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Result_Pdf_Command $command ): Enqueue_Result_Pdf_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Result_Pdf_Result ) {
			return $receipt;
		}

		try {
			$projection = $this->projections->build(
				$command->actor_user_id(),
				$command->release_id(),
				$command->expected_release_hash(),
				$command->locale()
			);
			$source     = $projection['source'] ?? null;
			if (
				! is_array( $source ) || array_is_list( $source )
				|| $command->release_id() !== ( $source['id'] ?? null )
				|| $command->expected_release_hash() !== ( $source['hash'] ?? null )
				|| $command->expected_release_state() !== ( $source['state'] ?? null )
			) {
				throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_STALE );
			}
			$snapshot = Result_Pdf_Request_Snapshot::freeze(
				$projection,
				$command->context(),
				(string) ( $this->timezone )(),
				$this->canonical
			);
		} catch ( Result_Pdf_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			$reason = str_contains( $error->getMessage(), 'too large' ) ? Result_Pdf_Failure::TOO_LARGE : Result_Pdf_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( $reason );
		}

		$queued_at = $this->now();
		$context   = $snapshot->context();
		$semantic  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id' => $command->actor_user_id(),
					'artifact_kind' => 'result_pdf',
					'context'       => $context,
					'release_state' => $snapshot->source_state(),
					'request_hash'  => $snapshot->hash(),
					'source_hash'   => $snapshot->source_hash(),
					'source_id'     => $snapshot->source_id(),
				)
			)
		);
		$record    = array(
			'actor_user_id'         => $command->actor_user_id(),
			'command_id'            => $command->command_id(),
			'command_storage_key'   => $command->idempotency_storage_key(),
			'request_digest'        => $command->request_digest(),
			'correlation_id'        => $command->correlation_id(),
			'job_public_id'         => $this->ids->create()->value(),
			'audit_public_id'       => $this->ids->create()->value(),
			'outbox_public_id'      => $this->ids->create()->value(),
			'job_idempotency_key'   => 'result-pdf-job:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'semantic_hash'         => $semantic,
			'context_type'          => $context['type'],
			'context_id'            => $context['id'],
			'context_revision'      => $context['revision'],
			'source_public_id'      => $snapshot->source_id(),
			'source_hash'           => $snapshot->source_hash(),
			'release_state'         => $snapshot->source_state(),
			'snapshot_json'         => $snapshot->json(),
			'snapshot_hash'         => $snapshot->hash(),
			'queued_at_utc'         => $queued_at,
			'print_profile_version' => $snapshot->profile_version(),
		);
		$result    = $this->repository->enqueue( $record );
		$this->queue->wake();
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
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
