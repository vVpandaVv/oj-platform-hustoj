<?php
/**
 * Enqueue report export.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Repository_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues one private export from an exact, currently authorized report snapshot.
 */
final class Enqueue_Report_Export {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize enqueue report export dependencies and state.
	 *
	 * @param Read_Report_Snapshot                      $reports Reports.
	 * @param Report_Export_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Report_Export_Queue_Wakeup_Port_Interface $queue Queue.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param Canonical_Json                            $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Read_Report_Snapshot $reports,
		private Report_Export_Repository_Port_Interface $repository,
		private Report_Export_Queue_Wakeup_Port_Interface $queue,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute enqueue report export.
	 *
	 * @param Enqueue_Report_Export_Command $command Command.
	 * @param Context_Authorization_Result  $context Access or execution context for this operation.
	 * @return Enqueue_Report_Export_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Report_Export_Command $command, Context_Authorization_Result $context ): Enqueue_Report_Export_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Enqueue_Report_Export_Result ) {
			return $receipt;
		}

		try {
			$report = $this->reports->read( $command->actor_user_id(), $command->snapshot_id(), $context )->payload();
			if (
				$command->snapshot_id() !== ( $report['id'] ?? null )
				|| $command->expected_snapshot_hash() !== ( $report['snapshot_hash'] ?? null )
				|| $command->expected_snapshot_state() !== ( $report['state'] ?? null )
			) {
				throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_STALE );
			}
			$snapshot = Report_Export_Request_Snapshot::freeze(
				$report,
				$command->context(),
				$command->artifact_kind(),
				$command->locale(),
				$this->canonical
			);
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Report_Snapshot_Query_Failure::NOT_FOUND => Report_Export_Failure::NOT_FOUND,
				Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED => Report_Export_Failure::SOURCE_ACCESS_DENIED,
				Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED => Report_Export_Failure::PROFILE_UNSUPPORTED,
				Report_Snapshot_Query_Failure::STORED_DATA_INVALID => Report_Export_Failure::STORED_DATA_INVALID,
				default => Report_Export_Failure::STORAGE_UNAVAILABLE,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( $reason );
		} catch ( Report_Export_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			$reason = str_contains( $error->getMessage(), 'too large' ) ? Report_Export_Failure::TOO_LARGE : Report_Export_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( $reason );
		}

		$queued_at        = $this->now();
		$snapshot_context = $snapshot->context();
		$semantic         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'actor_user_id' => $command->actor_user_id(),
					'artifact_kind' => $snapshot->artifact_kind(),
					'context'       => $snapshot_context,
					'profile'       => $snapshot->profile(),
					'request_hash'  => $snapshot->hash(),
					'source_hash'   => $snapshot->source_hash(),
					'source_id'     => $snapshot->source_id(),
				)
			)
		);
		$record           = array(
			'actor_user_id'       => $command->actor_user_id(),
			'artifact_kind'       => $snapshot->artifact_kind(),
			'audit_public_id'     => $this->ids->create()->value(),
			'command_id'          => $command->command_id(),
			'command_storage_key' => $command->idempotency_storage_key(),
			'context_id'          => $snapshot_context['id'],
			'context_revision'    => $snapshot_context['revision'],
			'context_type'        => $snapshot_context['type'],
			'correlation_id'      => $command->correlation_id(),
			'job_idempotency_key' => 'report-export-job:' . hash( 'sha256', $semantic . '|' . $command->idempotency_storage_key() ),
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
		$result           = $this->repository->enqueue( $record );
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
