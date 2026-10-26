<?php
/**
 * Enqueue worksheet pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue worksheet pdf.
 */
final class Enqueue_Worksheet_Pdf {
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
	 * Initialize enqueue worksheet pdf dependencies and state.
	 *
	 * @param Build_Worksheet_Print_Projection          $projections Projections.
	 * @param Worksheet_Pdf_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Worksheet_Pdf_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param Canonical_Json                            $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                             $clock Clock.
	 * @param callable|null                             $timezone Timezone.
	 */
	public function __construct(
		private Build_Worksheet_Print_Projection $projections,
		private Worksheet_Pdf_Repository_Port_Interface $repository,
		private Worksheet_Pdf_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $timezone = null
	) {
		$this->clock    = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->timezone = null === $timezone ? static fn (): string => function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC' : Closure::fromCallable( $timezone );
	}

	/**
	 * Execute enqueue worksheet pdf.
	 *
	 * @param Enqueue_Worksheet_Pdf_Command $command Command.
	 * @return Enqueue_Worksheet_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Worksheet_Pdf_Command $command ): Enqueue_Worksheet_Pdf_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Worksheet_Pdf_Result ) {
			return $receipt;
		}
		try {
			$projection = $this->projections->build(
				$command->actor_user_id(),
				'revision',
				$command->revision_id(),
				$command->expected_source_hash(),
				$command->profile()
			);
		} catch ( Worksheet_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Worksheet_Failure::NOT_FOUND => Worksheet_Pdf_Failure::NOT_FOUND,
				Worksheet_Failure::PRINT_SOURCE_CONFLICT => Worksheet_Pdf_Failure::SOURCE_STALE,
				Worksheet_Failure::STORED_DATA_INVALID => Worksheet_Pdf_Failure::SOURCE_INVALID,
				default => Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( $reason );
		}
		try {
			$snapshot = Worksheet_Pdf_Request_Snapshot::freeze( $projection, $command->expected_state(), (string) ( $this->timezone )(), $this->canonical );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::SOURCE_INVALID );
		}
		$queued_at = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		$semantic = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id' => $command->actor_user_id(),
					'source_id'     => $command->revision_id(),
					'source_hash'   => $command->expected_source_hash(),
					'request_hash'  => $snapshot->hash(),
				)
			)
		);
		$record   = array(
			'actor_user_id'            => $command->actor_user_id(),
			'context_type'             => null,
			'context_id'               => null,
			'context_revision'         => null,
			'command_id'               => $command->command_id(),
			'command_storage_key'      => $command->idempotency_storage_key(),
			'request_digest'           => $command->request_digest(),
			'correlation_id'           => $command->correlation_id(),
			'job_public_id'            => $this->ids->create()->value(),
			'audit_public_id'          => $this->ids->create()->value(),
			'outbox_public_id'         => $this->ids->create()->value(),
			'job_idempotency_key'      => 'pdfjob:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
			'semantic_hash'            => $semantic,
			'source_public_id'         => $command->revision_id(),
			'source_hash'              => $command->expected_source_hash(),
			'source_state'             => $command->expected_state(),
			'snapshot_json'            => $snapshot->json(),
			'snapshot_hash'            => $snapshot->hash(),
			'queued_at_utc'            => $queued_at,
			'request_contract_version' => $snapshot->version(),
			'request_snapshot_schema'  => Worksheet_Pdf_Request_Snapshot::SCHEMA,
			'request_snapshot_version' => Worksheet_Pdf_Request_Snapshot::DATABASE_VERSION,
			'source_type'              => $snapshot->source_type(),
			'print_profile_version'    => $snapshot->profile_version(),
		);
		$result   = $this->repository->enqueue( $record );
		$this->queue->wake();
		return $result;
	}
}
